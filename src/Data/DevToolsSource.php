<?php

/**
 * This file is part of Milpa Admin — the administration panel of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin\Data;

use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;

/**
 * The ledgers the house already writes, read for the Dev tools section — nothing here runs anything
 * (greenhouse decisions/0205).
 *
 * Five reads of three files: the AGENT SESSIONS (`var/agent-sessions.jsonl` under the app root, the file
 * the `agent` operation writes, replayed through `milpa/agent`'s `SessionStore`), one session's TIMELINE
 * (what `SessionProjector` paints, plus the audit-only facts the projector leaves to audit surfaces), the
 * DEBT SIGNALS (`session.debt_signaled` events in those same streams, grouped by their four real kinds),
 * the EVIDENCE (`session.evidence_recorded` events), and a LOG — the file the app DECLARED under `admin.log`;
 * without a declaration the section says so and invents no path.
 *
 * The coupling to `milpa/agent` is SOFT, like the capabilities read of the Plugins section: `class_exists`
 * decides, and without the package the snapshot degrades to «not available» naming it. Every block reads
 * on its own and carries its own `error`, so an unreadable log does not blank the sessions and a broken
 * ledger does not blank the log. Nothing here appends, ends, answers or deletes.
 */
final class DevToolsSource
{
    /** Why the agent ledger is not available: the package is not installed. */
    public const WHY_AGENT = 'milpa/agent';

    /** Why the agent ledger is not available: no kernel in the container, so no app root to find it under. */
    public const WHY_KERNEL = 'kernel';

    /** The ledger the `agent` operation writes, relative to the app root (greenhouse evidence/0509). */
    public const LEDGER = 'var/agent-sessions.jsonl';

    /** The config key naming the log file the section tails. */
    public const LOG_KEY = 'admin.log';

    /** How many lines of the log the section shows, counted from the end. */
    public const LOG_LINES = 200;

    /** The event type the house appends a debt observation under (`Milpa\AppRuntime\Agent\DebtSignal::EVENT`). */
    public const DEBT_EVENT = 'session.debt_signaled';

    /** The event type the house appends the closure verdict under (`Milpa\AppRuntime\Agent\ClosureVerdict::EVENT`). */
    public const CLOSURE_EVENT = 'session.closure_derived';

    /** The four real debt kinds, in the order `DebtSignal` declares them — listed even when their count is zero. */
    public const DEBT_KINDS = ['admitted_intent_skip', 'high_tier_double_ceremony', 'scope_fragility', 'framework_gap'];

    public const STATE_RUNNING = 'running';
    public const STATE_WAITING = 'waiting';
    public const STATE_DONE = 'done';
    public const STATE_INTERRUPTED = 'interrupted';

    /** The most recent evidence entries the overview lists. */
    private const EVIDENCE_LIMIT = 100;

    /** How much of a plan or a message the timeline shows per row. */
    private const DETAIL_CHARS = 160;

    /**
     * @param EventStoreInterface|null $events     a ledger to read instead of the kernel's file (tests)
     * @param string                   $agentClass the class whose presence means `milpa/agent` is installed — a
     *                                             name that does not exist makes the absent case testable
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ?EventStoreInterface $events = null,
        private readonly string $agentClass = \Milpa\Agent\SessionStore::class,
    ) {
    }

    /**
     * Whether the agent ledger can be read, and — when it cannot — why: the package, or the kernel.
     *
     * @return array{available: bool, why: string|null}
     */
    public function availability(): array
    {
        if (!class_exists($this->agentClass) || !class_exists(\Milpa\EventStore\FileEventStore::class)) {
            return ['available' => false, 'why' => self::WHY_AGENT];
        }
        if ($this->events === null && $this->kernel() === null) {
            return ['available' => false, 'why' => self::WHY_KERNEL];
        }

        return ['available' => true, 'why' => null];
    }

    /**
     * The overview: every session with its state, the debt signals by kind, the evidence ledger and the
     * declared log's tail. Each block carries its own `error` (null when it read) so one failure does not
     * blank the others; the log block is read even when the agent ledger is not available.
     *
     * @return array{available: bool, why: string|null, sessions: array{error: string|null, rows: list<array<string, mixed>>}, debt: array{error: string|null, total: int, kinds: list<array{kind: string, count: int, sessions: list<string>}>}, evidence: array{error: string|null, items: list<array<string, mixed>>}, log: array{declared: bool, path: string|null, error: string|null, lines: list<string>, truncated: bool}}
     */
    public function snapshot(): array
    {
        $availability = $this->availability();
        $empty = [
            'sessions' => ['error' => null, 'rows' => []],
            'debt' => ['error' => null, 'total' => 0, 'kinds' => self::emptyKinds()],
            'evidence' => ['error' => null, 'items' => []],
        ];

        if ($availability['available']) {
            $empty['sessions'] = $this->guard(fn (): array => ['rows' => $this->sessions()], $empty['sessions']);
            $empty['debt'] = $this->guard(fn (): array => $this->debt(), $empty['debt']);
            $empty['evidence'] = $this->guard(fn (): array => ['items' => $this->evidence()], $empty['evidence']);
        }

        return [...$availability, ...$empty, 'log' => $this->log()];
    }

    /**
     * One session's timeline: its row (as the overview lists it) and every painted event of its stream in
     * order — what `SessionProjector` paints, plus the audit-only facts it deliberately leaves to audit
     * surfaces (the opening, debt signals, the closure verdict, trial runs). `found` is false when no
     * stream carries that id.
     *
     * @return array{available: bool, why: string|null, id: string, found: bool, error: string|null, session: array<string, mixed>|null, events: list<array{seq: int, when: string|null, kind: string, detail: string, flags: list<string>}>}
     */
    public function timeline(string $sessionId): array
    {
        $availability = $this->availability();
        $out = [...$availability, 'id' => $sessionId, 'found' => false, 'error' => null, 'session' => null, 'events' => []];
        if (!$availability['available']) {
            return $out;
        }

        return $this->guard(function () use ($sessionId, $out): array {
            $store = $this->store();
            $stream = $store->stream($sessionId);
            if ($stream === []) {
                return $out;
            }
            $session = $store->load($sessionId);
            $projector = new \Milpa\Agent\SessionProjector();

            $rows = [];
            foreach ($stream as $event) {
                $row = $this->paint($projector, $event);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return [
                ...$out,
                'found' => true,
                'session' => $session === null ? null : $this->row($session, $stream),
                'events' => $rows,
            ];
        }, $out);
    }

    /**
     * The declared log's tail: `declared` false when `admin.log` names nothing; `error` `missing` when
     * the path does not exist and `unreadable` when it is not a readable file; else the last
     * {@see self::LOG_LINES} lines, `truncated` when older ones exist. A relative path is resolved
     * against the app root when a kernel is in the container.
     *
     * @return array{declared: bool, path: string|null, error: string|null, lines: list<string>, truncated: bool}
     */
    public function log(): array
    {
        $out = ['declared' => false, 'path' => null, 'error' => null, 'lines' => [], 'truncated' => false];
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declared = $config instanceof Config ? $config->get(self::LOG_KEY) : null;
        if (!\is_string($declared) || trim($declared) === '') {
            return $out;
        }

        $path = trim($declared);
        $kernel = $this->kernel();
        if (!str_starts_with($path, '/') && $kernel !== null) {
            $path = rtrim($kernel->root(), '/') . '/' . $path;
        }
        $out['declared'] = true;
        $out['path'] = $path;

        if (!file_exists($path)) {
            $out['error'] = 'missing';

            return $out;
        }
        if (!is_file($path) || !is_readable($path)) {
            $out['error'] = 'unreadable';

            return $out;
        }

        [$out['lines'], $out['truncated']] = self::tail($path, self::LOG_LINES);

        return $out;
    }

    /**
     * Every session the ledger knows, newest activity first, each with the state derived from its fold.
     *
     * @return list<array<string, mixed>>
     */
    private function sessions(): array
    {
        $store = $this->store();
        $streams = $this->streams();

        $rows = [];
        foreach ($store->loadAll() as $id => $session) {
            $rows[] = $this->row($session, $streams[\Milpa\Agent\SessionStore::PREFIX . $id] ?? []);
        }
        usort($rows, static fn (array $a, array $b): int => [$b['lastSeq'], $a['id']] <=> [$a['lastSeq'], $b['id']]);

        return $rows;
    }

    /**
     * One session as the overview lists it and the drill-down heads it.
     *
     * The STATE is derived from the fold and the stream, never declared: `waiting` while a question or a
     * paused sequence stops it; `interrupted` when it ended because the answer window closed — the
     * store's own «died of silence»; `done` for any other end; `running` otherwise. No fact in the ledger
     * says «failed», so no row does. TOKENS are the provider's own numbers summed over every
     * `session.model_returned` that carried usage — and `null`, not zero, when none did: absent is not zero
     * (greenhouse decisions/0192).
     *
     * @param list<Event> $stream
     *
     * @return array<string, mixed>
     */
    private function row(\Milpa\Agent\Session $session, array $stream): array
    {
        $in = 0;
        $out = 0;
        $reported = false;
        $debt = 0;
        $windowClosed = false;
        $closure = null;
        foreach ($stream as $event) {
            if ($event->type === 'session.model_returned') {
                $usage = \is_array($event->payload['usage'] ?? null) ? $event->payload['usage'] : [];
                if ($usage === []) {
                    continue;
                }
                $in += (int) ($usage['prompt_tokens'] ?? 0);
                $out += (int) ($usage['completion_tokens'] ?? 0);
                $reported = true;
            } elseif ($event->type === self::DEBT_EVENT) {
                ++$debt;
            } elseif ($event->type === 'session.answer_window_closed') {
                $windowClosed = true;
            } elseif ($event->type === self::CLOSURE_EVENT) {
                $reasons = \is_array($event->payload['reasons'] ?? null) ? $event->payload['reasons'] : [];
                $closure = ['verified' => ($event->payload['verified'] ?? false) === true, 'reasons' => \count($reasons)];
            }
        }

        $state = match (true) {
            $session->endedBecause !== null => $windowClosed ? self::STATE_INTERRUPTED : self::STATE_DONE,
            $session->question !== null || $session->pausedSequence !== null => self::STATE_WAITING,
            default => self::STATE_RUNNING,
        };

        $pending = null;
        if ($state === self::STATE_WAITING) {
            $pending = $session->question !== null
                ? ['reason' => $session->question->reason ?? '', 'question' => $session->question->question]
                : ['reason' => 'sequence_paused', 'question' => $session->pausedSequence->sequenceId ?? ''];
        }

        $first = $stream[0] ?? null;
        $last = $stream === [] ? null : $stream[\count($stream) - 1];

        return [
            'id' => $session->id,
            'goal' => $session->goal,
            'mode' => $session->mode->value,
            'state' => $state,
            'endedBecause' => $session->endedBecause,
            'tokensIn' => $reported ? $in : null,
            'tokensOut' => $reported ? $out : null,
            'pending' => $pending,
            'events' => \count($stream),
            'debt' => $debt,
            'closure' => $closure,
            'startedAt' => self::when($first),
            'lastAt' => self::when($last),
            'lastSeq' => $last->seq ?? 0,
        ];
    }

    /**
     * The debt signals across every stream: the four real kinds with their counts and the sessions that
     * carry them (a kind nobody signaled stays listed at zero — the honest empty), then any other kind the
     * ledger holds. Each signal's context is read in the session's timeline, beside the fact it names.
     *
     * @return array{error: null, total: int, kinds: list<array{kind: string, count: int, sessions: list<string>}>}
     */
    private function debt(): array
    {
        $kinds = [];
        foreach (self::DEBT_KINDS as $kind) {
            $kinds[$kind] = ['kind' => $kind, 'count' => 0, 'sessions' => []];
        }
        $total = 0;
        foreach ($this->streams() as $stream => $events) {
            $sessionId = self::sessionId($stream);
            foreach ($events as $event) {
                if ($event->type !== self::DEBT_EVENT) {
                    continue;
                }
                $kind = \is_string($event->payload['signal'] ?? null) && $event->payload['signal'] !== '' ? $event->payload['signal'] : '(unnamed)';
                $kinds[$kind] ??= ['kind' => $kind, 'count' => 0, 'sessions' => []];
                ++$kinds[$kind]['count'];
                ++$total;
                if (!\in_array($sessionId, $kinds[$kind]['sessions'], true)) {
                    $kinds[$kind]['sessions'][] = $sessionId;
                }
            }
        }

        return ['error' => null, 'total' => $total, 'kinds' => array_values($kinds)];
    }

    /**
     * The evidence ledger across every stream, newest first: what the agent recorded to back a claim.
     *
     * @return list<array<string, mixed>>
     */
    private function evidence(): array
    {
        $items = [];
        foreach ($this->streams() as $stream => $events) {
            $sessionId = self::sessionId($stream);
            foreach ($events as $event) {
                if ($event->type !== 'session.evidence_recorded') {
                    continue;
                }
                $p = $event->payload;
                $items[] = [
                    'seq' => $event->seq,
                    'session' => $sessionId,
                    'when' => self::when($event),
                    'kind' => \is_string($p['kind'] ?? null) ? $p['kind'] : '',
                    'reference' => \is_string($p['reference'] ?? null) ? $p['reference'] : '',
                    'todo' => \is_string($p['todo'] ?? null) ? $p['todo'] : null,
                    'detail' => \is_string($p['detail'] ?? null) ? $p['detail'] : null,
                ];
            }
        }
        usort($items, static fn (array $a, array $b): int => $b['seq'] <=> $a['seq']);

        return \array_slice($items, 0, self::EVIDENCE_LIMIT);
    }

    /**
     * One event as a timeline row, or null when nothing paints it.
     *
     * The projector's translation is used as is — one reader of the stream, never a second one that
     * diverges. What the projector maps to null ON PURPOSE as «audit material» (its own words) is exactly
     * this section's material, so those facts — the opening, a debt signal, the closure verdict, a trial
     * run / promotion / discard — are painted here and nowhere else.
     *
     * @return array{seq: int, when: string|null, kind: string, detail: string, flags: list<string>}|null
     */
    private function paint(\Milpa\Agent\SessionProjector $projector, Event $event): ?array
    {
        $p = $event->payload;
        $base = ['seq' => $event->seq, 'when' => self::when($event), 'flags' => []];

        $audit = match ($event->type) {
            'session.started' => ['kind' => 'opened', 'detail' => self::str($p['goal'] ?? null), 'flags' => array_values(array_filter([self::str($p['mode'] ?? null)], static fn (string $f): bool => $f !== ''))],
            self::DEBT_EVENT => ['kind' => 'debt', 'detail' => self::debtDetail($p)],
            self::CLOSURE_EVENT => [
                'kind' => 'closure',
                'detail' => implode('; ', self::strings($p['reasons'] ?? null)),
                'flags' => [($p['verified'] ?? false) === true ? 'verified' : 'unverified'],
            ],
            'session.trial_run_recorded' => [
                'kind' => 'trial',
                'detail' => self::dots([
                    self::str($p['operation'] ?? null),
                    self::str($p['exit'] ?? null) !== '' ? 'exit ' . self::str($p['exit'] ?? null) : '',
                    self::str($p['workspace'] ?? null),
                ]),
            ],
            'session.trial_promoted' => [
                'kind' => 'trial-promoted',
                'detail' => self::dots([self::str($p['workspace'] ?? null), implode(', ', self::strings($p['paths'] ?? null))]),
            ],
            'session.trial_discarded' => ['kind' => 'trial-discarded', 'detail' => self::str($p['workspace'] ?? null)],
            default => null,
        };
        if ($audit !== null) {
            return [...$base, ...$audit];
        }

        $painted = $projector->project($event);
        if ($painted === null) {
            return null;
        }

        return [...$base, ...self::flatten($painted)];
    }

    /**
     * A projected event as `kind · detail · flags` — the three things a timeline row shows.
     *
     * @param array<string, mixed> $painted
     *
     * @return array{kind: string, detail: string, flags: list<string>}
     */
    private static function flatten(array $painted): array
    {
        $kind = self::str($painted['kind'] ?? null);
        $card = \is_array($painted['card'] ?? null) ? $painted['card'] : [];
        $plan = \is_array($painted['plan'] ?? null) ? $painted['plan'] : [];
        $ended = \is_array($painted['ended'] ?? null) ? $painted['ended'] : [];
        $activity = \is_array($painted['activity'] ?? null) ? $painted['activity'] : [];
        $message = \is_array($painted['message'] ?? null) ? $painted['message'] : [];
        $answered = \is_array($painted['answered'] ?? null) ? $painted['answered'] : [];
        $flags = [];

        switch ($kind) {
            case 'card':
                $from = self::str($card['from'] ?? null);
                $move = ($from !== '' ? $from . ' → ' : '') . self::str($card['to'] ?? null);
                $detail = trim(self::str($card['id'] ?? null) . ': ' . self::clip(self::str($card['text'] ?? null)), ': ') . ' [' . $move . ']';
                if (($card['evidenced'] ?? null) === false) {
                    $flags[] = 'unverified';
                }

                break;
            case 'evidence':
                $todo = self::str($card['todo'] ?? null);
                $detail = trim(self::str($card['evidenceKind'] ?? null) . ' ' . self::str($card['reference'] ?? null) . ($todo !== '' ? ' · todo ' . $todo : ''));

                break;
            case 'plan':
                $detail = 'v' . self::str($plan['version'] ?? null) . ': ' . self::clip(self::str($plan['text'] ?? null));

                break;
            case 'open-work':
                $detail = (string) \count(\is_array($ended['todos'] ?? null) ? $ended['todos'] : []);

                break;
            case 'transferred':
                $detail = '→ ' . self::str($ended['to'] ?? null) . ' (' . \count(\is_array($ended['todos'] ?? null) ? $ended['todos'] : []) . ')';

                break;
            case 'ended':
                $detail = self::str($ended['because'] ?? null);

                break;
            case 'waiting':
                $detail = self::str($ended['question'] ?? null);

                break;
            case 'answered':
                $by = \is_array($answered['by'] ?? null) ? self::str($answered['by']['id'] ?? null) : '';
                $detail = self::str($answered['answer'] ?? null) . ($by !== '' ? ' — ' . $by : '');

                break;
            case 'message':
                $detail = self::str($message['from'] ?? null) . ': ' . self::clip(self::str($message['content'] ?? null));

                break;
            case 'activity':
                $kind = self::str($activity['state'] ?? null);
                $detail = self::str($activity['detail'] ?? null);
                if (($activity['mutating'] ?? false) === true) {
                    $flags[] = 'mutating';
                }
                if (($activity['ok'] ?? true) === false) {
                    $flags[] = 'failed';
                }

                break;
            case 'option-removed':
                $why = self::str($activity['why'] ?? null);
                $detail = self::str($activity['detail'] ?? null) . ($why !== '' ? ' (' . $why . ')' : '');

                break;
            default:
                $detail = self::str($activity['detail'] ?? null);
        }

        return ['kind' => $kind, 'detail' => $detail, 'flags' => $flags];
    }

    /**
     * A debt signal's row detail: the kind, then its bounded context as `field=value` pairs.
     *
     * @param array<string, mixed> $payload
     */
    private static function debtDetail(array $payload): string
    {
        $pairs = [];
        foreach (self::strings($payload['context'] ?? null) as $field => $value) {
            $pairs[] = $field . '=' . $value;
        }
        $kind = self::str($payload['signal'] ?? null);

        return $pairs === [] ? $kind : $kind . ' — ' . self::clip(implode(', ', $pairs));
    }

    /**
     * The last `$max` lines of a file, read from the end in chunks so a long log is never loaded whole,
     * and whether older lines were left out.
     *
     * @return array{0: list<string>, 1: bool}
     */
    private static function tail(string $path, int $max): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [[], false];
        }

        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle) ?: 0;
            $buffer = '';
            $chunk = 8192;
            while ($position > 0 && substr_count($buffer, "\n") <= $max) {
                $read = min($chunk, $position);
                $position -= $read;
                fseek($handle, $position);
                $buffer = (string) fread($handle, $read) . $buffer;
            }
        } finally {
            fclose($handle);
        }

        $lines = explode("\n", rtrim($buffer, "\n"));
        if ($lines === ['']) {
            return [[], false];
        }
        $truncated = \count($lines) > $max || $position > 0;

        return [\array_slice($lines, -$max), $truncated];
    }

    /**
     * Runs one block's read, handing back its fallback with the error named when the read throws —
     * so a broken ledger is a notice in that block, never a blank panel.
     *
     * @template T of array<string, mixed>
     *
     * @param callable(): T $read
     * @param T             $fallback
     *
     * @return T
     */
    private function guard(callable $read, array $fallback): array
    {
        try {
            return [...$fallback, ...$read()];
        } catch (\Throwable $failure) {
            return [...$fallback, 'error' => $failure->getMessage()];
        }
    }

    /** The store to read sessions through, over the ledger this source reads. */
    private function store(): \Milpa\Agent\SessionStore
    {
        return new \Milpa\Agent\SessionStore($this->ledger());
    }

    /**
     * Every stream of the ledger in one read, keyed by stream id.
     *
     * @return array<string, list<Event>>
     */
    private function streams(): array
    {
        return $this->ledger()->replayAll();
    }

    /** The ledger: the injected store, else the kernel's `var/agent-sessions.jsonl`. */
    private function ledger(): EventStoreInterface
    {
        if ($this->events !== null) {
            return $this->events;
        }
        $kernel = $this->kernel();
        if ($kernel === null) {
            throw new \RuntimeException('no kernel in the container: the agent ledger has no root to live under');
        }

        return new \Milpa\EventStore\FileEventStore(rtrim($kernel->root(), '/') . '/' . self::LEDGER);
    }

    private function kernel(): ?Kernel
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;

        return $kernel instanceof Kernel ? $kernel : null;
    }

    /**
     * The four real kinds at zero — what an empty ledger honestly shows.
     *
     * @return list<array{kind: string, count: int, sessions: list<string>}>
     */
    private static function emptyKinds(): array
    {
        return array_map(static fn (string $kind): array => ['kind' => $kind, 'count' => 0, 'sessions' => []], self::DEBT_KINDS);
    }

    /** The session id a stream id names — the id itself when it carries no session prefix. */
    private static function sessionId(string $stream): string
    {
        $prefix = \Milpa\Agent\SessionStore::PREFIX;

        return str_starts_with($stream, $prefix) ? substr($stream, \strlen($prefix)) : $stream;
    }

    /** The instant an event was recorded, ISO-8601 in UTC — null for a record that predates the field. */
    private static function when(?Event $event): ?string
    {
        return $event?->recordedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : '');
    }

    /**
     * The string entries of a value, keys kept — anything that is not an array of strings is nothing.
     *
     * @return array<int|string, string>
     */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? array_filter($value, 'is_string') : [];
    }

    /**
     * The non-empty parts joined with a middle dot — no dangling separator when a part is missing.
     *
     * @param list<string> $parts
     */
    private static function dots(array $parts): string
    {
        return implode(' · ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /** The text cut to {@see self::DETAIL_CHARS} code points with an ellipsis — whole characters, no mbstring. */
    private static function clip(string $text): string
    {
        return preg_match('/^(.{' . (self::DETAIL_CHARS - 1) . '}).+/us', $text, $head) === 1 ? $head[1] . '…' : $text;
    }
}
