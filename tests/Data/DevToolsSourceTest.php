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

namespace Milpa\Admin\Tests\Data;

use Milpa\Admin\Data\DevToolsSource;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Evidence;
use Milpa\Agent\PausedSequence;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The ledgers read through the real `milpa/agent` store over an in-memory event store, and the file
 * paths through a kernel rooted in a temp dir — nothing is mocked, nothing is run.
 */
final class DevToolsSourceTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::remove($root);
        }
    }

    public function testWithoutTheAgentPackageItIsNotAvailableAndNamesIt(): void
    {
        $source = new DevToolsSource(new DIContainer(), new InMemoryEventStore(), 'Acme\\NotInstalled');

        self::assertSame(['available' => false, 'why' => DevToolsSource::WHY_AGENT], $source->availability());
        $snapshot = $source->snapshot();
        self::assertFalse($snapshot['available']);
        self::assertSame(DevToolsSource::WHY_AGENT, $snapshot['why']);
        self::assertSame([], $snapshot['sessions']['rows']);
        self::assertSame(0, $snapshot['debt']['total']);
        self::assertSame(DevToolsSource::DEBT_KINDS, array_column($snapshot['debt']['kinds'], 'kind'), 'the four real kinds, at zero');
        self::assertSame([], $snapshot['evidence']['items']);
        self::assertFalse($snapshot['log']['declared'], 'the log block is still read: nothing declared');
        self::assertFalse($source->timeline('x')['available']);
        self::assertSame('x', $source->timeline('x')['id']);
    }

    public function testWithoutAKernelAndWithoutAnInjectedLedgerItIsNotAvailableAndNamesTheKernel(): void
    {
        $source = new DevToolsSource(new DIContainer());

        self::assertSame(['available' => false, 'why' => DevToolsSource::WHY_KERNEL], $source->availability());
        self::assertSame(DevToolsSource::WHY_KERNEL, $source->snapshot()['why']);
        self::assertFalse($source->timeline('x')['found']);
    }

    public function testSessionsCarryAnHonestStateRealTokensAndWhatTheyWaitOn(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        self::seed($events, $store);

        $source = new DevToolsSource(new DIContainer(), $events);
        $snapshot = $source->snapshot();

        self::assertTrue($snapshot['available']);
        self::assertNull($snapshot['why']);
        self::assertNull($snapshot['sessions']['error']);
        $rows = array_column($snapshot['sessions']['rows'], null, 'id');
        self::assertSame(['s-paused', 's-dead', 's-done', 's-wait', 's-run'], array_keys($rows), 'newest activity first');

        $run = $rows['s-run'];
        self::assertSame(DevToolsSource::STATE_RUNNING, $run['state']);
        self::assertSame('greet the house', $run['goal']);
        self::assertSame('auto', $run['mode']);
        self::assertSame(150, $run['tokensIn'], 'the provider\'s prompt tokens, summed over the calls that carried usage');
        self::assertSame(25, $run['tokensOut']);
        self::assertNull($run['pending']);
        self::assertSame(1, $run['debt']);
        self::assertNull($run['closure']);
        self::assertNull($run['endedBecause']);
        self::assertSame(10, $run['events']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $run['startedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $run['lastAt']);

        $wait = $rows['s-wait'];
        self::assertSame(DevToolsSource::STATE_WAITING, $wait['state']);
        self::assertSame(['reason' => 'target_not_named', 'question' => 'Which target?'], $wait['pending']);
        self::assertNull($wait['tokensIn'], 'no call reported usage: absent, not zero');
        self::assertNull($wait['tokensOut']);

        $done = $rows['s-done'];
        self::assertSame(DevToolsSource::STATE_DONE, $done['state']);
        self::assertSame('finished', $done['endedBecause']);
        self::assertSame(['verified' => false, 'reasons' => 1], $done['closure']);
        self::assertNull($done['pending']);

        $dead = $rows['s-dead'];
        self::assertSame(DevToolsSource::STATE_INTERRUPTED, $dead['state'], 'the answer window closed and nobody answered: the store\'s own «died of silence»');
        self::assertNull($dead['pending'], 'nothing is pending on a dead session');

        $paused = $rows['s-paused'];
        self::assertSame(DevToolsSource::STATE_WAITING, $paused['state']);
        self::assertSame(['reason' => 'sequence_paused', 'question' => 'seq-1'], $paused['pending']);
    }

    public function testDebtIsGroupedByTheFourRealKindsPlusWhateverElseTheLedgerHolds(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        self::seed($events, $store);
        self::signal($events, 's-wait', 'admitted_intent_skip', ['operation' => 'x']);
        self::signal($events, 's-wait', 'framework_gap', ['digest' => 'abc']);
        self::signal($events, 's-wait', 'weird', []);
        $events->append(new Event(SessionStore::PREFIX . 's-wait', DevToolsSource::DEBT_EVENT, ['context' => []], $events->nextSeq()));

        $debt = (new DevToolsSource(new DIContainer(), $events))->snapshot()['debt'];

        self::assertNull($debt['error']);
        self::assertSame(5, $debt['total']);
        self::assertSame(
            [
                ['kind' => 'admitted_intent_skip', 'count' => 2, 'sessions' => ['s-run', 's-wait']],
                ['kind' => 'high_tier_double_ceremony', 'count' => 0, 'sessions' => []],
                ['kind' => 'scope_fragility', 'count' => 0, 'sessions' => []],
                ['kind' => 'framework_gap', 'count' => 1, 'sessions' => ['s-wait']],
                ['kind' => 'weird', 'count' => 1, 'sessions' => ['s-wait']],
                ['kind' => '(unnamed)', 'count' => 1, 'sessions' => ['s-wait']],
            ],
            $debt['kinds'],
            'the four real kinds first, even at zero; the rest after, named as found',
        );
    }

    public function testEvidenceIsListedNewestFirstWithWhatItPointsAt(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        self::seed($events, $store);
        $store->recordEvidence('s-wait', Evidence::testPassed('e2', 'tests/HolaTest.php', 't1', '3 passed'));

        $evidence = (new DevToolsSource(new DIContainer(), $events))->snapshot()['evidence'];

        self::assertNull($evidence['error']);
        self::assertCount(2, $evidence['items']);
        [$newest, $oldest] = $evidence['items'];
        self::assertSame(['s-wait', 'test_passed', 'tests/HolaTest.php', 't1', '3 passed'], [$newest['session'], $newest['kind'], $newest['reference'], $newest['todo'], $newest['detail']]);
        self::assertSame(['s-run', 'operation_ok', 'hola:greet', null, 'exit 0'], [$oldest['session'], $oldest['kind'], $oldest['reference'], $oldest['todo'], $oldest['detail']]);
        self::assertGreaterThan($oldest['seq'], $newest['seq']);
        self::assertNotNull($newest['when']);
    }

    public function testTheTimelineIsWhatTheProjectorPaintsPlusTheAuditFactsItLeavesToAuditSurfaces(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        self::seed($events, $store);
        $source = new DevToolsSource(new DIContainer(), $events);

        $run = $source->timeline('s-run');
        self::assertTrue($run['found']);
        self::assertNull($run['error']);
        self::assertSame('s-run', $run['id']);
        self::assertSame('s-run', $run['session']['id'] ?? null);
        self::assertSame(150, $run['session']['tokensIn'] ?? null);
        $painted = array_map(static fn (array $e): array => [$e['kind'], $e['detail'], $e['flags']], $run['events']);
        self::assertSame([
            ['opened', 'greet the house', ['auto']],
            ['thinking', '', []],
            ['tool', 'hola:greet', []],
            ['ready', '', []],
            ['debt', 'admitted_intent_skip — operation=hola:greet', []],
            ['evidence', 'operation_ok hola:greet', []],
            ['trial', 'make:crud · exit 0 · ws-1', []],
        ], $painted, 'model_returned is not painted (the projector says so); the audit facts are');
        foreach ($run['events'] as $event) {
            self::assertIsInt($event['seq']);
            self::assertNotNull($event['when']);
        }

        $done = $source->timeline('s-done');
        self::assertSame(
            [['opened', 'third', ['ask']], ['ended', 'finished', []], ['closure', 'todo t1 done without evidence', ['unverified']]],
            array_map(static fn (array $e): array => [$e['kind'], $e['detail'], $e['flags']], $done['events']),
        );

        $dead = $source->timeline('s-dead');
        self::assertSame(
            ['opened', 'waiting', 'ended'],
            array_column($dead['events'], 'kind'),
            'the closed answer window is not painted; the end it caused is',
        );
        self::assertSame('May I?', $dead['events'][1]['detail']);

        $nope = $source->timeline('nope');
        self::assertFalse($nope['found']);
        self::assertNull($nope['session']);
        self::assertSame([], $nope['events']);
        self::assertSame('nope', $nope['id']);
    }

    public function testEveryPaintedKindFlattensToADetailAReaderCanFollow(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s-all', 'everything');
        $store->setPlan('s-all', 'plan text');
        $store->setTodo('s-all', new Todo('t1', 'write tests'));
        $store->setTodo('s-all', new Todo('t1', 'write tests', TodoStatus::InProgress));
        $store->requireFirst('s-all', ['plan', 'todo']);
        $store->removeOption('s-all', 'shell', 'denied', 'no shell');
        $store->setGoal('s-all', 'new goal');
        $store->setGoal('s-all', '');
        $store->message('s-all', 'parent', str_repeat('x', 200));
        $store->ask('s-all', new PendingQuestion('q', 'ok?', ['y']));
        $store->answer('s-all', 'q', 'yes', new Principal('rod', true), 'cli');
        $store->recordEvidence('s-all', Evidence::artifact('e', 'src/A.php', 't1'));
        $store->recordToolCall('s-all', 'fs:write', ['path' => 'a'], 'written', true, true);
        $store->recordToolCall('s-all', 'fs:read', ['path' => 'b'], 'nope', false, false);
        $store->recordTrialPromotion('s-all', ['workspace' => 'ws', 'paths' => ['a.php', 'b.php'], 'diff_digest' => 'd', 'by' => 'rod']);
        $store->recordTrialDiscard('s-all', ['workspace' => 'ws2']);
        $events->append(new Event(SessionStore::PREFIX . 's-all', 'session.todo_changed', ['id' => 't9', 'text' => 'old done', 'status' => 'done', 'evidenced' => false, 'version' => 1], $events->nextSeq()));
        $store->transferOpenTodos('s-all', 's-heir');
        $store->end('s-all', 'moved on');
        $events->append(new Event(SessionStore::PREFIX . 's-all', 'session.trial_run_recorded', ['workspace' => 'w', 'operation' => 'op', 'report' => []], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-all', 'session.tool_called', [], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-all', 'session.mystery', ['x' => 1], $events->nextSeq()));

        $timeline = (new DevToolsSource(new DIContainer(), $events))->timeline('s-all');

        $painted = array_map(static fn (array $e): array => [$e['kind'], $e['detail'], $e['flags']], $timeline['events']);
        self::assertSame([
            ['opened', 'everything', ['ask']],
            ['plan', 'v1: plan text', []],
            ['card', 't1: write tests [pending]', []],
            ['card', 't1: write tests [pending → in_progress]', []],
            ['prerequisite-set', 'plan, todo', []],
            ['option-removed', 'shell (denied)', []],
            ['goal-changed', 'new goal', []],
            ['goal-changed', '', []],
            ['message', 'parent: ' . str_repeat('x', 159) . '…', []],
            ['waiting', 'ok?', []],
            ['answered', 'yes — rod', []],
            ['evidence', 'artifact_created src/A.php · todo t1', []],
            ['tool', 'fs:write', ['mutating']],
            ['tool', 'fs:read', ['failed']],
            ['trial-promoted', 'ws · a.php, b.php', []],
            ['trial-discarded', 'ws2', []],
            ['card', 't9: old done [done]', ['unverified']],
            ['transferred', '→ s-heir (1)', []],
            ['open-work', '1', []],
            ['ended', 'moved on', []],
            ['trial', 'op · w', []],
            ['tool', '', []],
        ], $painted, 'an unknown type paints nothing; a bare tool call paints an empty tool; the done todo is not open work');
        self::assertSame(DevToolsSource::STATE_DONE, $timeline['session']['state'] ?? null);
    }

    public function testTheLogBlockReadsOnlyWhatTheAppDeclaredAndNamesWhatItCannotRead(): void
    {
        $root = $this->root();
        mkdir($root . '/var');
        $absolute = $root . '/var/abs.log';
        file_put_contents($absolute, implode("\n", array_map(static fn (int $i): string => 'line ' . $i, range(1, 250))) . "\n");
        file_put_contents($root . '/var/empty.log', '');
        file_put_contents($root . '/var/short.log', "one\ntwo");
        mkdir($root . '/var/dir.log');

        $log = static fn (mixed $declared, ?Kernel $kernel = null): array => (new DevToolsSource(self::container($declared, $kernel)))->log();

        self::assertSame(['declared' => false, 'path' => null, 'error' => null, 'lines' => [], 'truncated' => false], $log(null));
        self::assertFalse($log('   ')['declared'], 'blank is not a declaration');
        self::assertFalse($log(42)['declared']);

        $tail = $log($absolute);
        self::assertTrue($tail['declared']);
        self::assertSame($absolute, $tail['path']);
        self::assertNull($tail['error']);
        self::assertCount(DevToolsSource::LOG_LINES, $tail['lines']);
        self::assertSame('line 51', $tail['lines'][0]);
        self::assertSame('line 250', $tail['lines'][199]);
        self::assertTrue($tail['truncated']);

        $short = $log($root . '/var/short.log');
        self::assertSame(['one', 'two'], $short['lines'], 'no trailing newline still reads the last line');
        self::assertFalse($short['truncated']);

        $empty = $log($root . '/var/empty.log');
        self::assertNull($empty['error']);
        self::assertSame([], $empty['lines']);
        self::assertFalse($empty['truncated']);

        $missing = $log($root . '/var/nope.log');
        self::assertSame('missing', $missing['error']);
        self::assertSame($root . '/var/nope.log', $missing['path']);
        self::assertSame([], $missing['lines']);

        $dir = $log($root . '/var/dir.log');
        self::assertSame('unreadable', $dir['error'], 'a path that is not a readable file');

        $kernel = self::kernel($root, ['admin' => ['log' => 'var/short.log']]);
        $relative = $log('var/short.log', $kernel);
        self::assertSame($root . '/var/short.log', $relative['path'], 'relative to the app root when a kernel is there');
        self::assertSame(['one', 'two'], $relative['lines']);
        self::assertSame('var/short.log', $log('var/short.log')['path'], 'without a kernel a relative path stays as declared');
    }

    public function testWithAKernelItReadsTheLedgerUnderTheAppRootAndABrokenLedgerIsAnErrorPerBlockNotABlankPanel(): void
    {
        $root = $this->root();
        mkdir($root . '/var');
        file_put_contents($root . '/var/app.log', "alpha\nbeta\n");
        $kernel = self::kernel($root, ['admin' => ['log' => 'var/app.log']]);
        $source = new DevToolsSource(self::container('var/app.log', $kernel));

        self::assertSame(['available' => true, 'why' => null], $source->availability(), 'the package and the kernel are there');
        $fresh = $source->snapshot();
        self::assertNull($fresh['sessions']['error'], 'no ledger file yet is an empty ledger, not a failure');
        self::assertSame([], $fresh['sessions']['rows']);
        self::assertSame(['alpha', 'beta'], $fresh['log']['lines']);

        file_put_contents($root . '/var/agent-sessions.jsonl', self::jsonl('s-file'));
        $written = $source->snapshot();
        self::assertSame(['s-file'], array_column($written['sessions']['rows'], 'id'));
        self::assertSame(DevToolsSource::STATE_WAITING, $written['sessions']['rows'][0]['state']);
        self::assertSame(['reason' => 'target_not_named', 'question' => 'Which target?'], $written['sessions']['rows'][0]['pending']);
        self::assertSame(1200, $written['sessions']['rows'][0]['tokensIn']);
        self::assertSame(80, $written['sessions']['rows'][0]['tokensOut']);
        self::assertSame('2026-09-04T10:00:00Z', $written['sessions']['rows'][0]['startedAt']);
        self::assertSame(1, $written['debt']['kinds'][2]['count'], 'scope_fragility, from the fixture');
        self::assertSame('sha256:4c1e', $written['evidence']['items'][0]['reference']);
        self::assertTrue($source->timeline('s-file')['found']);
        self::assertSame(['opened', 'thinking', 'tool', 'debt', 'evidence', 'waiting'], array_column($source->timeline('s-file')['events'], 'kind'));
        self::assertSame('2026-09-04T10:00:00Z', $source->timeline('s-file')['events'][0]['when']);
        self::assertNull($source->timeline('s-file')['events'][1]['when'], 'a record written before recorded_at existed keeps its gap');

        file_put_contents($root . '/var/agent-sessions.jsonl', "{not json\n", FILE_APPEND);
        $broken = $source->snapshot();
        self::assertIsString($broken['sessions']['error'], 'the ledger is named as unreadable');
        self::assertSame([], $broken['sessions']['rows']);
        self::assertIsString($broken['debt']['error']);
        self::assertSame(DevToolsSource::DEBT_KINDS, array_column($broken['debt']['kinds'], 'kind'));
        self::assertIsString($broken['evidence']['error']);
        self::assertSame(['alpha', 'beta'], $broken['log']['lines'], 'the log reads from a different file and is unaffected');
        $timeline = $source->timeline('s-file');
        self::assertIsString($timeline['error']);
        self::assertFalse($timeline['found']);
    }

    /**
     * Five sessions in one ledger: running with real usage, waiting on a question, done with a closure
     * verdict, dead of silence, paused on a sequence — plus a debt signal, evidence and a trial run.
     */
    private static function seed(InMemoryEventStore $events, SessionStore $store): void
    {
        $store->start('s-run', 'greet the house', AutonomyMode::Auto);
        $store->recordTurn('s-run', 'user', 'hola');
        $store->recordModelReturn('s-run', ['model' => 'qwen', 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120]]);
        $store->recordToolCall('s-run', 'hola:greet', ['name' => 'Rod'], 'Hola Rod');
        $store->recordModelReturn('s-run', ['model' => 'qwen', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5, 'total_tokens' => 55]]);
        $store->recordModelReturn('s-run', ['model' => 'qwen']);
        $store->recordTurn('s-run', 'assistant', 'done');
        self::signal($events, 's-run', 'admitted_intent_skip', ['operation' => 'hola:greet']);
        $store->recordEvidence('s-run', Evidence::operationOk('e1', 'hola:greet', null, 'exit 0'));
        $store->recordTrialRun('s-run', ['workspace' => 'ws-1', 'operation' => 'make:crud', 'arguments_digest' => 'abc', 'bounds' => [], 'exit' => 0, 'report' => [], 'output_digest' => 'x']);

        $store->start('s-wait', 'a second goal');
        $store->ask('s-wait', new PendingQuestion('q1', 'Which target?', ['a', 'b'], null, null, 'target_not_named'));

        $store->start('s-done', 'third');
        $store->end('s-done', 'finished');
        $events->append(new Event(SessionStore::PREFIX . 's-done', DevToolsSource::CLOSURE_EVENT, ['verified' => false, 'reasons' => ['todo t1 done without evidence']], $events->nextSeq()));

        $store->start('s-dead', 'fourth');
        $store->ask('s-dead', new PendingQuestion('q2', 'May I?', [], null, '2020-01-01T00:00:00+00:00', 'permission'));
        self::assertTrue($store->expireIfDue('s-dead', new \DateTimeImmutable('2021-01-01T00:00:00+00:00')));

        $store->start('s-paused', 'fifth');
        $store->recordSequencePaused('s-paused', new PausedSequence('seq-1', 'digest', [['operation' => 'x', 'arguments' => []]], 1));
    }

    /**
     * @param array<string, string> $context
     */
    private static function signal(InMemoryEventStore $events, string $session, string $kind, array $context): void
    {
        $events->append(new Event(SessionStore::PREFIX . $session, DevToolsSource::DEBT_EVENT, ['signal' => $kind, 'context' => $context], $events->nextSeq()));
    }

    /** The JSONL the `agent` operation writes, by hand — one line per event, the first without `recorded_at`. */
    private static function jsonl(string $id): string
    {
        $stream = SessionStore::PREFIX . $id;
        $rows = [
            ['stream_id' => $stream, 'type' => 'session.started', 'payload' => ['goal' => 'greet the house', 'mode' => 'auto', 'parentId' => null], 'seq' => 1, 'recorded_at' => '2026-09-04T10:00:00.000000Z'],
            ['stream_id' => $stream, 'type' => 'session.turn', 'payload' => ['role' => 'user', 'content' => 'hola'], 'seq' => 2],
            ['stream_id' => $stream, 'type' => 'session.model_returned', 'payload' => ['model' => 'qwen', 'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 80, 'total_tokens' => 1280]], 'seq' => 3, 'recorded_at' => '2026-09-04T10:00:01.000000Z'],
            ['stream_id' => $stream, 'type' => 'session.tool_called', 'payload' => ['tool' => 'hola:greet', 'arguments' => [], 'result' => 'Hola', 'ok' => true, 'mutating' => false], 'seq' => 4, 'recorded_at' => '2026-09-04T10:00:02.000000Z'],
            ['stream_id' => $stream, 'type' => DevToolsSource::DEBT_EVENT, 'payload' => ['signal' => 'scope_fragility', 'context' => ['operation' => 'hola:greet']], 'seq' => 5, 'recorded_at' => '2026-09-04T10:00:03.000000Z'],
            ['stream_id' => $stream, 'type' => 'session.evidence_recorded', 'payload' => ['id' => 'e1', 'kind' => 'artifact_created', 'reference' => 'sha256:4c1e', 'todo' => null, 'detail' => null], 'seq' => 6, 'recorded_at' => '2026-09-04T10:00:04.000000Z'],
            ['stream_id' => $stream, 'type' => 'session.question_asked', 'payload' => ['id' => 'q1', 'question' => 'Which target?', 'options' => [], 'why' => null, 'expiresAt' => null, 'reason' => 'target_not_named'], 'seq' => 7, 'recorded_at' => '2026-09-04T10:00:05.000000Z'],
        ];

        return implode("\n", array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows)) . "\n";
    }

    private static function container(mixed $log, ?Kernel $kernel): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['admin' => ['log' => $log]]));
        if ($kernel !== null) {
            $container->registerService(Kernel::class, $kernel);
        }

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function kernel(string $root, array $config): Kernel
    {
        return Kernel::boot(['root' => $root, 'plugins' => [], 'config' => $config, 'container' => new DIContainer()]);
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-admin-devtools-' . bin2hex(random_bytes(4));
        mkdir($root);
        $this->roots[] = $root;

        return $root;
    }

    private static function remove(string $path): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
