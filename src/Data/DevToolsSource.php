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
 * Five reads of two files: the AGENT SESSIONS, one session's TIMELINE, the DEBT SIGNALS
 * (`session.debt_signaled`, grouped by their four real kinds), the EVIDENCE (`session.evidence_recorded`)
 * — all four from the agent ledger — and a LOG: the file the app DECLARED under `admin.log`, confined to
 * the app root; without a declaration the section says so and invents no path.
 *
 * THE LEDGER IS RESOLVED THE WAY THE `agent` OPERATION RESOLVES IT (`AgentOperations::sessions()`): a
 * container-registered `EventStoreInterface` first, then a registered `SessionStore`, then the file
 * `var/agent-sessions.jsonl` under the kernel's root. The page says which one it is reading — the class
 * name or the path — so nobody reads an empty table as an empty ledger while the agent writes elsewhere.
 *
 * THE LEDGER IS READ ONCE PER SNAPSHOT — `replayAll()` when a store gives it, this class's own tolerant
 * line reader when it is the file — and sessions, debt and evidence are derived from that one pass; the
 * timeline reduces the already-fetched stream with `SessionReducer`. The file reader mirrors
 * `FileEventStore`'s line format exactly (one JSON object per line: `stream_id`, `type`, `payload`, `seq`,
 * optional `recorded_at`; blank lines skipped) and differs in one thing only: a line that does not decode
 * is COUNTED and skipped, never a failure that blanks every block.
 *
 * AUDIT PAINTING FOR WHAT `milpa/agent`'s TRANSCRIPT PROJECTOR DELEGATES TO AUDIT SURFACES: the timeline
 * asks `SessionProjector` first and uses its translation as is. Only an event the projector maps to null
 * AND that is in the explicit, bounded list {@see self::AUDIT_EVENTS} is painted here — the opening
 * (`session.started`), a debt signal (`session.debt_signaled`), the closure verdict
 * (`session.closure_derived`), the trial facts (`session.trial_run_recorded`, `session.trial_promoted`,
 * `session.trial_discarded`), an executed operation (`session.operation_executed`: operation ·
 * executed_by/authorized_by · arguments digest) and a paused/resumed sequence (`session.sequence_paused`,
 * `session.sequence_resumed`). Anything else the projector leaves unpainted stays unpainted: the list is
 * the whole of this section's own reading, and growing it is a decision, not a default.
 *
 * The coupling to `milpa/agent` is SOFT, like the capabilities read of the Plugins section: `class_exists`
 * decides, and without the package the snapshot degrades to «not available» naming it. Every block carries
 * its own `error`, so an unreadable log does not blank the sessions. Nothing here appends, ends, answers
 * or deletes.
 */
final class DevToolsSource
{
    /** Why the agent ledger is not available: the package is not installed. */
    public const WHY_AGENT = 'milpa/agent';

    /** Why the agent ledger is not available: no store in the container and no kernel to find the file under. */
    public const WHY_KERNEL = 'kernel';

    /** The ledger the `agent` operation writes when no store is registered, relative to the app root (greenhouse evidence/0509). */
    public const LEDGER = 'var/agent-sessions.jsonl';

    /** The config key naming the log file the section tails. */
    public const LOG_KEY = 'admin.log';

    /** How many lines of the log the section shows, counted from the end. */
    public const LOG_LINES = 200;

    /** How many bytes of the log the tail reads at most, counted from the end — a log without newlines stays bounded. */
    public const LOG_BYTES = 1_048_576;

    /** The log block's error when the declared path resolves outside the app root — or when no root is known to confine it to. */
    public const LOG_OUTSIDE = 'outside';

    /** The event type the house appends a debt observation under (`Milpa\AppRuntime\Agent\DebtSignal::EVENT`). */
    public const DEBT_EVENT = 'session.debt_signaled';

    /** The event type the house appends the closure verdict under (`Milpa\AppRuntime\Agent\ClosureVerdict::EVENT`). */
    public const CLOSURE_EVENT = 'session.closure_derived';

    /** The four real debt kinds, in the order `DebtSignal` declares them — listed even when their count is zero. */
    public const DEBT_KINDS = ['admitted_intent_skip', 'high_tier_double_ceremony', 'scope_fragility', 'framework_gap'];

    /**
     * The audit facts this section paints when — and only when — the transcript projector returned null
     * for them. See the class docblock: this list is the whole of the section's own reading.
     */
    public const AUDIT_EVENTS = [
        'session.started',
        self::DEBT_EVENT,
        self::CLOSURE_EVENT,
        'session.trial_run_recorded',
        'session.trial_promoted',
        'session.trial_discarded',
        'session.operation_executed',
        'session.sequence_paused',
        'session.sequence_resumed',
    ];

    public const STATE_RUNNING = 'running';
    public const STATE_WAITING = 'waiting';
    public const STATE_DONE = 'done';
    public const STATE_INTERRUPTED = 'interrupted';

    /** The newest sessions the overview lists; the rest is a count. */
    public const SESSIONS_LIMIT = 50;

    /** The most recent evidence entries the overview lists. */
    private const EVIDENCE_LIMIT = 100;

    /** How much of a plan or a message the timeline shows per row. */
    private const DETAIL_CHARS = 160;

    /** The chunk the log tail reads per seek. */
    private const TAIL_CHUNK = 8192;

    /**
     * @param string $agentClass the class whose presence means `milpa/agent` is installed — a name that
     *                           does not exist makes the absent case testable
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly string $agentClass = \Milpa\Agent\SessionStore::class,
    ) {
    }

    /**
     * Whether the agent ledger can be read, and — when it cannot — why: the package, or the kernel.
     * `source` names what would be (or is) read: the registered store's class, or the file's path;
     * null when neither exists.
     *
     * @return array{available: bool, why: string|null, source: string|null}
     */
    public function availability(): array
    {
        $whence = $this->whence();
        $source = $whence['source'] ?? null;
        if (!class_exists($this->agentClass) || !class_exists(\Milpa\EventStore\FileEventStore::class)) {
            return ['available' => false, 'why' => self::WHY_AGENT, 'source' => $source];
        }
        if ($whence === null) {
            return ['available' => false, 'why' => self::WHY_KERNEL, 'source' => null];
        }

        return ['available' => true, 'why' => null, 'source' => $source];
    }

    /**
     * The overview: the newest sessions with their state, the debt signals by kind, the evidence ledger
     * and the declared log's tail — the ledger read once and the three blocks derived from that pass.
     * Each block carries its own `error` (null when it read) so a derivation failing does not blank the
     * others; the log block is read even when the agent ledger is not available.
     *
     * @return array{available: bool, why: string|null, source: string|null, sessions: array{error: string|null, rows: list<array<string, mixed>>, total: int, more: int, unstarted: int, unreadable: int}, debt: array{error: string|null, total: int, kinds: list<array{kind: string, count: int, sessions: list<string>}>}, evidence: array{error: string|null, items: list<array<string, mixed>>}, log: array{declared: bool, path: string|null, root: string|null, error: string|null, lines: list<string>, truncated: bool}}
     */
    public function snapshot(): array
    {
        $availability = $this->availability();
        $blocks = [
            'sessions' => ['error' => null, 'rows' => [], 'total' => 0, 'more' => 0, 'unstarted' => 0, 'unreadable' => 0],
            'debt' => ['error' => null, 'total' => 0, 'kinds' => self::emptyKinds()],
            'evidence' => ['error' => null, 'items' => []],
        ];

        if ($availability['available']) {
            try {
                $ledger = $this->ledger();
                $blocks['sessions'] = $this->guard(fn (): array => $this->sessions($ledger), $blocks['sessions']);
                $blocks['debt'] = $this->guard(fn (): array => $this->debt($ledger['streams']), $blocks['debt']);
                $blocks['evidence'] = $this->guard(fn (): array => ['items' => $this->evidence($ledger['streams'])], $blocks['evidence']);
            } catch (\Throwable $failure) {
                foreach ($blocks as &$block) {
                    $block['error'] = $failure->getMessage();
                }
                unset($block);
            }
        }

        return [...$availability, ...$blocks, 'log' => $this->log()];
    }

    /**
     * One session's timeline: its row (as the overview lists it) and every painted event of its stream in
     * order — what `SessionProjector` paints, plus the audit facts it leaves to audit surfaces (the class
     * docblock lists them). The stream is the one already fetched by the single read, reduced here with
     * `SessionReducer`. `found` is false when no stream carries that id.
     *
     * @return array{available: bool, why: string|null, source: string|null, id: string, found: bool, error: string|null, unreadable: int, row: array<string, mixed>|null, events: list<array{seq: int, when: string|null, kind: string, detail: string, flags: list<string>}>}
     */
    public function timeline(string $sessionId): array
    {
        $availability = $this->availability();
        $out = [...$availability, 'id' => $sessionId, 'found' => false, 'error' => null, 'unreadable' => 0, 'row' => null, 'events' => []];
        if (!$availability['available']) {
            return $out;
        }

        return $this->guard(function () use ($sessionId, $out): array {
            $ledger = $this->ledger();
            $out['unreadable'] = $ledger['unreadable'];
            $stream = $ledger['streams'][\Milpa\Agent\SessionStore::PREFIX . $sessionId] ?? [];
            if ($stream === []) {
                return $out;
            }
            $session = (new \Milpa\Agent\SessionReducer())->reduce($sessionId, $stream);
            $projector = new \Milpa\Agent\SessionProjector();

            $rows = [];
            foreach ($stream as $event) {
                $row = $this->paint($projector, $event);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return [...$out, 'found' => true, 'row' => $this->row($session, $stream), 'events' => $rows];
        }, $out);
    }

    /**
     * The declared log's tail, CONFINED to the app root: `declared` false when `admin.log` names nothing;
     * `root` the kernel's root, or null when no kernel is in the container — and then nothing is read:
     * a relative path cannot be resolved (never against the working directory) and is reported `missing`,
     * an absolute one cannot be confined and is reported {@see self::LOG_OUTSIDE}. With a root, the path
     * — absolute, or relative to the root — is normalised lexically and through `realpath()`, so neither
     * `..` nor a symlink reaches outside; outside is {@see self::LOG_OUTSIDE}, nothing there is `missing`,
     * not a readable file is `unreadable`; else the last {@see self::LOG_LINES} lines within the last
     * {@see self::LOG_BYTES} bytes, `truncated` when older content exists.
     *
     * @return array{declared: bool, path: string|null, root: string|null, error: string|null, lines: list<string>, truncated: bool}
     */
    public function log(): array
    {
        $out = ['declared' => false, 'path' => null, 'root' => null, 'error' => null, 'lines' => [], 'truncated' => false];
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declared = $config instanceof Config ? $config->get(self::LOG_KEY) : null;
        if (!\is_string($declared) || trim($declared) === '') {
            return $out;
        }

        $path = trim($declared);
        $absolute = str_starts_with($path, '/');
        $kernel = $this->kernel();
        $out['declared'] = true;
        $out['path'] = $path;
        if ($kernel === null) {
            $out['error'] = $absolute ? self::LOG_OUTSIDE : 'missing';

            return $out;
        }

        $root = self::normalize($kernel->root());
        $confine = realpath($root);
        $confine = $confine === false ? $root : $confine;
        $out['root'] = $root;
        $out['path'] = self::normalize($absolute ? $path : $root . '/' . $path);

        if (!self::under($out['path'], $root) && !self::under($out['path'], $confine)) {
            $out['error'] = self::LOG_OUTSIDE;

            return $out;
        }
        $real = realpath($out['path']);
        if ($real === false) {
            $out['error'] = 'missing';

            return $out;
        }
        if (!self::under($real, $confine)) {
            $out['error'] = self::LOG_OUTSIDE;

            return $out;
        }
        if (!is_file($real) || !is_readable($real)) {
            $out['error'] = 'unreadable';

            return $out;
        }

        [$out['lines'], $out['truncated']] = self::tail($real, self::LOG_LINES, self::LOG_BYTES);

        return $out;
    }

    /**
     * Where the ledger is, resolved as the `agent` operation resolves it: a registered
     * `EventStoreInterface` (read whole in one `replayAll()`), else a registered `SessionStore` (read
     * through its own `ids()` and `stream()`), else the file under the kernel's root — null when there is
     * neither a store nor a kernel.
     *
     * @return array{source: string, events: EventStoreInterface|null, store: object|null, path: string|null}|null
     */
    private function whence(): ?array
    {
        if ($this->container->has(EventStoreInterface::class)) {
            $events = $this->container->get(EventStoreInterface::class);
            if ($events instanceof EventStoreInterface) {
                return ['source' => $events::class, 'events' => $events, 'store' => null, 'path' => null];
            }
        }
        if (class_exists($this->agentClass) && $this->container->has($this->agentClass)) {
            $store = $this->container->get($this->agentClass);
            if ($store instanceof \Milpa\Agent\SessionStore) {
                return ['source' => $store::class, 'events' => null, 'store' => $store, 'path' => null];
            }
        }
        $kernel = $this->kernel();
        if ($kernel === null) {
            return null;
        }
        $path = rtrim($kernel->root(), '/') . '/' . self::LEDGER;

        return ['source' => $path, 'events' => null, 'store' => null, 'path' => $path];
    }

    /**
     * The whole ledger in ONE read, keyed by stream id and ordered by `seq` within each — plus how many
     * lines could not be read when it is the file (a store gives its streams as they are).
     *
     * @return array{source: string, streams: array<string, list<Event>>, unreadable: int}
     */
    private function ledger(): array
    {
        $whence = $this->whence();
        if ($whence === null) {
            throw new \RuntimeException('no event store in the container and no kernel: the agent ledger has no root to live under');
        }
        if ($whence['events'] !== null) {
            return ['source' => $whence['source'], 'streams' => $whence['events']->replayAll(), 'unreadable' => 0];
        }
        if ($whence['store'] instanceof \Milpa\Agent\SessionStore) {
            $streams = [];
            foreach ($whence['store']->ids() as $id) {
                $streams[\Milpa\Agent\SessionStore::PREFIX . $id] = $whence['store']->stream($id);
            }

            return ['source' => $whence['source'], 'streams' => $streams, 'unreadable' => 0];
        }

        return ['source' => $whence['source'], ...self::readFile((string) $whence['path'])];
    }

    /**
     * The sessions block from the fetched streams: every stream that opened with `session.started`,
     * reduced and rowed, newest activity first, cut to the newest {@see self::SESSIONS_LIMIT} with the
     * rest counted in `more`; a stream without a start is not a session — skipped and counted.
     *
     * @param array{source: string, streams: array<string, list<Event>>, unreadable: int} $ledger
     *
     * @return array{rows: list<array<string, mixed>>, total: int, more: int, unstarted: int, unreadable: int}
     */
    private function sessions(array $ledger): array
    {
        $reducer = new \Milpa\Agent\SessionReducer();
        $prefix = \Milpa\Agent\SessionStore::PREFIX;
        $rows = [];
        $unstarted = 0;
        foreach ($ledger['streams'] as $stream => $events) {
            if (!str_starts_with($stream, $prefix) || $events === []) {
                continue;
            }
            if (!self::started($events)) {
                ++$unstarted;

                continue;
            }
            $id = substr($stream, \strlen($prefix));
            $rows[] = $this->row($reducer->reduce($id, $events), $events);
        }
        usort($rows, static fn (array $a, array $b): int => [$b['lastSeq'], $a['id']] <=> [$a['lastSeq'], $b['id']]);
        $total = \count($rows);

        return [
            'rows' => \array_slice($rows, 0, self::SESSIONS_LIMIT),
            'total' => $total,
            'more' => max(0, $total - self::SESSIONS_LIMIT),
            'unstarted' => $unstarted,
            'unreadable' => $ledger['unreadable'],
        ];
    }

    /**
     * One session as the overview lists it and the drill-down heads it.
     *
     * The STATE is derived from the fold and the stream, never declared: `waiting` while a question or a
     * paused sequence stops it; `interrupted` when the END FACT says so — its `because` names an
     * interruption, or the event immediately before `session.ended` is `session.answer_window_closed`,
     * the store's own «died of silence»; `done` for any other end; `running` otherwise. No fact in the
     * ledger says «failed», so no row does. TOKENS are the provider's own numbers summed over every
     * `session.model_returned` whose usage carries an integer `prompt_tokens` or `completion_tokens`;
     * a call that reported neither is skipped, and when none reported the count is `null`, not zero:
     * absent is not zero (greenhouse decisions/0192).
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
        $interrupted = false;
        $closure = null;
        $previous = null;
        foreach ($stream as $event) {
            if ($event->type === 'session.model_returned') {
                $usage = \is_array($event->payload['usage'] ?? null) ? $event->payload['usage'] : [];
                $prompt = $usage['prompt_tokens'] ?? null;
                $completion = $usage['completion_tokens'] ?? null;
                if (\is_int($prompt) || \is_int($completion)) {
                    $in += \is_int($prompt) ? $prompt : 0;
                    $out += \is_int($completion) ? $completion : 0;
                    $reported = true;
                }
            } elseif ($event->type === self::DEBT_EVENT) {
                ++$debt;
            } elseif ($event->type === 'session.ended') {
                $because = strtolower(self::str($event->payload['because'] ?? null));
                $interrupted = $previous === 'session.answer_window_closed' || str_contains($because, 'interrup');
            } elseif ($event->type === self::CLOSURE_EVENT) {
                $reasons = \is_array($event->payload['reasons'] ?? null) ? $event->payload['reasons'] : [];
                $closure = ['verified' => ($event->payload['verified'] ?? false) === true, 'reasons' => \count($reasons)];
            }
            $previous = $event->type;
        }

        $state = match (true) {
            $session->endedBecause !== null => $interrupted ? self::STATE_INTERRUPTED : self::STATE_DONE,
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
     * @param array<string, list<Event>> $streams
     *
     * @return array{error: null, total: int, kinds: list<array{kind: string, count: int, sessions: list<string>}>}
     */
    private function debt(array $streams): array
    {
        $kinds = [];
        foreach (self::DEBT_KINDS as $kind) {
            $kinds[$kind] = ['kind' => $kind, 'count' => 0, 'sessions' => []];
        }
        $total = 0;
        foreach ($streams as $stream => $events) {
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
     * @param array<string, list<Event>> $streams
     *
     * @return list<array<string, mixed>>
     */
    private function evidence(array $streams): array
    {
        $items = [];
        foreach ($streams as $stream => $events) {
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
     * The projector goes FIRST and its translation is used as is — one reader of the stream, never a
     * second one that diverges. Only what it maps to null AND is in {@see self::AUDIT_EVENTS} is painted
     * here (the class docblock says why the list is bounded).
     *
     * @return array{seq: int, when: string|null, kind: string, detail: string, flags: list<string>}|null
     */
    private function paint(\Milpa\Agent\SessionProjector $projector, Event $event): ?array
    {
        $base = ['seq' => $event->seq, 'when' => self::when($event), 'flags' => []];

        $painted = $projector->project($event);
        if ($painted !== null) {
            return [...$base, ...self::flatten($painted)];
        }
        if (!\in_array($event->type, self::AUDIT_EVENTS, true)) {
            return null;
        }

        $p = $event->payload;
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
            'session.operation_executed' => self::operationDetail($p),
            'session.sequence_paused' => [
                'kind' => 'sequence-paused',
                'detail' => self::dots([
                    self::str($p['sequenceId'] ?? null),
                    \is_int($p['nextIndex'] ?? null) ? $p['nextIndex'] . '/' . \count(\is_array($p['steps'] ?? null) ? $p['steps'] : []) : '',
                ]),
            ],
            'session.sequence_resumed' => ['kind' => 'sequence-resumed', 'detail' => self::str($p['sequenceId'] ?? null)],
        };

        return [...$base, ...$audit];
    }

    /**
     * An executed operation's row: `operation · executed_by/authorized_by · arguments digest`, each
     * identity as `principal@source` — `—` where the fact says nobody — and `verified` flagged only
     * when the executor's observation says it was.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{kind: string, detail: string, flags: list<string>}
     */
    private static function operationDetail(array $payload): array
    {
        $executed = \is_array($payload['executed_by'] ?? null) ? $payload['executed_by'] : [];
        $authorized = \is_array($payload['authorized_by'] ?? null) ? $payload['authorized_by'] : null;
        $identity = static function (?array $who, string $sourceField): string {
            if ($who === null) {
                return '—';
            }
            $principal = self::str($who['principal'] ?? null);
            $source = self::str($who[$sourceField] ?? null);

            return ($principal !== '' ? $principal : '—') . ($source !== '' ? '@' . $source : '');
        };
        $digest = self::str($payload['arguments_digest'] ?? null);

        return [
            'kind' => 'operation',
            'detail' => self::dots([
                self::str($payload['operation'] ?? null),
                $identity($executed, 'source') . '/' . $identity($authorized, 'provenance'),
                $digest,
            ]),
            'flags' => ($executed['verified'] ?? false) === true ? ['verified'] : [],
        ];
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
     * The ledger file read whole in one pass with this class's own tolerant reader — the same line format
     * `FileEventStore` writes and reads (one JSON object per line, blank lines skipped, a shared lock while
     * reading), except that a line which does not decode into an event is counted and skipped instead of
     * failing the whole read. A path with no file is an empty ledger.
     *
     * @return array{streams: array<string, list<Event>>, unreadable: int}
     */
    private static function readFile(string $path): array
    {
        if (!is_file($path)) {
            return ['streams' => [], 'unreadable' => 0];
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Unable to open event store file: %s', $path));
        }

        $streams = [];
        $unreadable = 0;

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException(\sprintf('Unable to lock event store file: %s', $path));
            }
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $event = self::decodeLine($line);
                if ($event === null) {
                    ++$unreadable;

                    continue;
                }
                $streams[$event->streamId][] = $event;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        foreach ($streams as &$events) {
            usort($events, static fn (Event $a, Event $b): int => $a->seq <=> $b->seq);
        }
        unset($events);

        return ['streams' => $streams, 'unreadable' => $unreadable];
    }

    /** One JSONL line as the event it carries, or null when it is not one (bad JSON, a missing or mistyped field, a date that does not parse). */
    private static function decodeLine(string $line): ?Event
    {
        try {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($row)
            || !\is_string($row['stream_id'] ?? null)
            || !\is_string($row['type'] ?? null)
            || !\is_array($row['payload'] ?? null)
            || !\is_int($row['seq'] ?? null)
        ) {
            return null;
        }
        $recordedAt = $row['recorded_at'] ?? null;
        if ($recordedAt !== null && !\is_string($recordedAt)) {
            return null;
        }

        try {
            return Event::fromArray([
                'stream_id' => $row['stream_id'],
                'type' => $row['type'],
                'payload' => $row['payload'],
                'seq' => $row['seq'],
                'recorded_at' => $recordedAt,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The last `$max` lines within the last `$bytes` bytes of a file, read from the end in chunks
     * collected once (never a buffer re-prepended per chunk, which is quadratic), and whether older
     * content was left out — by line count or by the byte cap. A file whose last `$bytes` bytes hold
     * no newline yields that tail as its one, cut, line.
     *
     * @return array{0: list<string>, 1: bool}
     */
    private static function tail(string $path, int $max, int $bytes): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [[], false];
        }

        $chunks = [];
        $newlines = 0;
        $read = 0;

        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle) ?: 0;
            while ($position > 0 && $newlines <= $max && $read < $bytes) {
                $size = min(self::TAIL_CHUNK, $position, $bytes - $read);
                $position -= $size;
                fseek($handle, $position);
                $piece = (string) fread($handle, $size);
                $chunks[] = $piece;
                $read += \strlen($piece);
                $newlines += substr_count($piece, "\n");
            }
        } finally {
            fclose($handle);
        }

        $lines = explode("\n", rtrim(implode('', array_reverse($chunks)), "\n"));
        if ($lines === ['']) {
            return [[], false];
        }
        $truncated = \count($lines) > $max || $position > 0;

        return [\array_slice($lines, -$max), $truncated];
    }

    /**
     * Runs one block's derivation, handing back its fallback with the error named when it throws —
     * so a broken derivation is a notice in that block, never a blank panel.
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

    /**
     * Whether a stream opened as a session — carries a `session.started` fact.
     *
     * @param list<Event> $events
     */
    private static function started(array $events): bool
    {
        foreach ($events as $event) {
            if ($event->type === 'session.started') {
                return true;
            }
        }

        return false;
    }

    /** The session id a stream id names — the id itself when it carries no session prefix. */
    private static function sessionId(string $stream): string
    {
        $prefix = \Milpa\Agent\SessionStore::PREFIX;

        return str_starts_with($stream, $prefix) ? substr($stream, \strlen($prefix)) : $stream;
    }

    /** An absolute path with `.`, `..` and repeated separators resolved lexically — no filesystem touched. */
    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    /** Whether a normalised path is the root or lies under it. */
    private static function under(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
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
