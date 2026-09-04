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
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The ledgers read through the real `milpa/agent` reducer and projector over an in-memory event store
 * registered in the container — the way the `agent` operation finds its store — and the file paths
 * through a kernel rooted in a temp dir. Nothing is mocked, nothing is run.
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

    public function testWithoutTheAgentPackageItIsNotAvailableAndNamesItAndWhereTheLedgerWouldBeRead(): void
    {
        $source = new DevToolsSource(self::registered(new InMemoryEventStore()), 'Acme\\NotInstalled');

        self::assertSame(['available' => false, 'why' => DevToolsSource::WHY_AGENT, 'source' => InMemoryEventStore::class], $source->availability());
        $snapshot = $source->snapshot();
        self::assertFalse($snapshot['available']);
        self::assertSame(DevToolsSource::WHY_AGENT, $snapshot['why']);
        self::assertSame(InMemoryEventStore::class, $snapshot['source'], 'the store it would read is named even when the package is not there');
        self::assertSame([], $snapshot['sessions']['rows']);
        self::assertSame(0, $snapshot['debt']['total']);
        self::assertSame(DevToolsSource::DEBT_KINDS, array_column($snapshot['debt']['kinds'], 'kind'), 'the four real kinds, at zero');
        self::assertSame([], $snapshot['evidence']['items']);
        self::assertFalse($snapshot['log']['declared'], 'the log block is still read: nothing declared');
        self::assertFalse($source->timeline('x')['available']);
        self::assertSame('x', $source->timeline('x')['id']);

        $root = $this->root();
        $file = new DevToolsSource(self::container(null, self::kernel($root, [])), 'Acme\\NotInstalled');
        self::assertSame($root . '/' . DevToolsSource::LEDGER, $file->availability()['source'], 'with a kernel and no store, the file path');
    }

    public function testWithoutAStoreAndWithoutAKernelItIsNotAvailableAndNamesTheKernel(): void
    {
        $source = new DevToolsSource(new DIContainer());

        self::assertSame(['available' => false, 'why' => DevToolsSource::WHY_KERNEL, 'source' => null], $source->availability());
        self::assertSame(DevToolsSource::WHY_KERNEL, $source->snapshot()['why']);
        self::assertFalse($source->timeline('x')['found']);
    }

    public function testTheLedgerIsResolvedTheWayTheAgentOperationResolvesIt(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s-1', 'through the store');

        $registeredStore = new DIContainer();
        $registeredStore->registerService(SessionStore::class, $store);
        $viaStore = (new DevToolsSource($registeredStore))->snapshot();
        self::assertSame(SessionStore::class, $viaStore['source'], 'a registered SessionStore, read through its own ids() and stream()');
        self::assertSame(['s-1'], array_column($viaStore['sessions']['rows'], 'id'));
        self::assertTrue((new DevToolsSource($registeredStore))->timeline('s-1')['found']);

        $both = new DIContainer();
        $both->registerService(SessionStore::class, $store);
        $both->registerService(EventStoreInterface::class, $events);
        self::assertSame(InMemoryEventStore::class, (new DevToolsSource($both))->snapshot()['source'], 'the event store first — one replayAll() reads it whole');

        $root = $this->root();
        $storeAndKernel = self::container(null, self::kernel($root, []));
        $storeAndKernel->registerService(EventStoreInterface::class, $events);
        self::assertSame(InMemoryEventStore::class, (new DevToolsSource($storeAndKernel))->snapshot()['source'], 'the store wins over the file');
        self::assertSame($root . '/' . DevToolsSource::LEDGER, (new DevToolsSource(self::container(null, self::kernel($root, []))))->snapshot()['source'], 'the file is the fallback');
    }

    public function testSessionsCarryAnHonestStateRealTokensAndWhatTheyWaitOn(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        self::seed($events, $store);

        $source = new DevToolsSource(self::registered($events));
        $snapshot = $source->snapshot();

        self::assertTrue($snapshot['available']);
        self::assertNull($snapshot['why']);
        self::assertNull($snapshot['sessions']['error']);
        self::assertSame(5, $snapshot['sessions']['total']);
        self::assertSame(0, $snapshot['sessions']['more']);
        self::assertSame(0, $snapshot['sessions']['unstarted']);
        self::assertSame(0, $snapshot['sessions']['unreadable']);
        $rows = array_column($snapshot['sessions']['rows'], null, 'id');
        self::assertSame(['s-paused', 's-dead', 's-done', 's-wait', 's-run'], array_keys($rows), 'newest activity first');

        $run = $rows['s-run'];
        self::assertSame(DevToolsSource::STATE_RUNNING, $run['state']);
        self::assertSame('greet the house', $run['goal']);
        self::assertSame('auto', $run['mode']);
        self::assertSame(150, $run['tokensIn'], 'the provider\'s prompt tokens, summed over the calls that carried an integer count');
        self::assertSame(25, $run['tokensOut']);
        self::assertNull($run['pending']);
        self::assertSame(1, $run['debt']);
        self::assertNull($run['closure']);
        self::assertNull($run['endedBecause']);
        self::assertSame(11, $run['events']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $run['startedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $run['lastAt']);

        $wait = $rows['s-wait'];
        self::assertSame(DevToolsSource::STATE_WAITING, $wait['state']);
        self::assertSame(['reason' => 'target_not_named', 'question' => 'Which target?'], $wait['pending']);
        self::assertNull($wait['tokensIn'], 'no call reported an integer count: absent, not zero');
        self::assertNull($wait['tokensOut']);

        $done = $rows['s-done'];
        self::assertSame(DevToolsSource::STATE_DONE, $done['state']);
        self::assertSame('finished', $done['endedBecause']);
        self::assertSame(['verified' => false, 'reasons' => 1], $done['closure']);
        self::assertNull($done['pending']);

        $dead = $rows['s-dead'];
        self::assertSame(DevToolsSource::STATE_INTERRUPTED, $dead['state'], 'the end fact follows the closed answer window: the store\'s own «died of silence»');
        self::assertNull($dead['pending'], 'nothing is pending on a dead session');

        $paused = $rows['s-paused'];
        self::assertSame(DevToolsSource::STATE_WAITING, $paused['state']);
        self::assertSame(['reason' => 'sequence_paused', 'question' => 'seq-1'], $paused['pending']);
    }

    public function testTokensCountOnlyWhatTheProviderReportedAsAnInteger(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s-t', 'tokens');
        $store->recordModelReturn('s-t', ['model' => 'm', 'usage' => ['prompt_tokens' => '12', 'completion_tokens' => 3.5]]);
        $store->recordModelReturn('s-t', ['model' => 'm', 'usage' => ['total_tokens' => 99]]);
        $store->recordModelReturn('s-t', ['model' => 'm', 'usage' => []]);
        $store->recordModelReturn('s-t', ['model' => 'm']);

        $row = (new DevToolsSource(self::registered($events)))->snapshot()['sessions']['rows'][0];
        self::assertNull($row['tokensIn'], 'a string, a float, a total without its parts, an empty usage: none is a reported count');
        self::assertNull($row['tokensOut']);

        $store->recordModelReturn('s-t', ['model' => 'm', 'usage' => ['prompt_tokens' => 7]]);
        $store->recordModelReturn('s-t', ['model' => 'm', 'usage' => ['completion_tokens' => 2, 'prompt_tokens' => 'x']]);
        $row = (new DevToolsSource(self::registered($events)))->snapshot()['sessions']['rows'][0];
        self::assertSame(7, $row['tokensIn'], 'one integer side is enough for the call to count; the other side counts as nothing, not as zero reported');
        self::assertSame(2, $row['tokensOut']);
    }

    public function testInterruptedIsDerivedFromTheEndFactNotFromAFlagAnywhereInTheStream(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);

        $store->start('s-silence', 'a question expired and the store ended the session on the spot');
        $store->ask('s-silence', new PendingQuestion('q', 'go?', [], null, '2020-01-01T00:00:00+00:00'));
        self::assertTrue($store->expireIfDue('s-silence', new \DateTimeImmutable('2021-01-01T00:00:00+00:00')));

        $store->start('s-said', 'the end fact says it');
        $store->end('s-said', 'interrupted by the human');

        $store->start('s-reopened', 'died of silence, then ended again later for another reason');
        $store->ask('s-reopened', new PendingQuestion('q', 'go?', [], null, '2020-01-01T00:00:00+00:00'));
        self::assertTrue($store->expireIfDue('s-reopened', new \DateTimeImmutable('2021-01-01T00:00:00+00:00')));
        $events->append(new Event(SessionStore::PREFIX . 's-reopened', 'session.turn', ['role' => 'user', 'content' => 'again'], $events->nextSeq()));
        $store->end('s-reopened', 'finished');

        $rows = array_column((new DevToolsSource(self::registered($events)))->snapshot()['sessions']['rows'], 'state', 'id');
        self::assertSame(DevToolsSource::STATE_INTERRUPTED, $rows['s-silence'], 'the store itself ended it right after the window closed');
        self::assertSame(DevToolsSource::STATE_INTERRUPTED, $rows['s-said']);
        self::assertSame(DevToolsSource::STATE_DONE, $rows['s-reopened'], 'the LAST end fact decides, and it followed a turn, not a closed window');

        $events2 = new InMemoryEventStore();
        $store2 = new SessionStore($events2);
        $store2->start('s-later', 'a window closed once; the session was ended for another reason later');
        $events2->append(new Event(SessionStore::PREFIX . 's-later', 'session.question_asked', ['id' => 'q', 'question' => 'go?', 'options' => [], 'why' => null, 'expiresAt' => null, 'reason' => null], $events2->nextSeq()));
        $events2->append(new Event(SessionStore::PREFIX . 's-later', 'session.answer_window_closed', ['id' => 'q', 'at' => '2021-01-01T00:00:00+00:00'], $events2->nextSeq()));
        $events2->append(new Event(SessionStore::PREFIX . 's-later', 'session.turn', ['role' => 'assistant', 'content' => 'moving on'], $events2->nextSeq()));
        $store2->end('s-later', 'done');
        self::assertSame(DevToolsSource::STATE_DONE, (new DevToolsSource(self::registered($events2)))->snapshot()['sessions']['rows'][0]['state'], 'a closed window earlier in the stream is not an interruption at the end');
    }

    public function testAStreamWithoutAStartIsNotASessionItIsCountedAndTheTableIsCappedAtTheNewestFifty(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        for ($i = 1; $i <= 53; ++$i) {
            $store->start(\sprintf('s-%02d', $i), 'goal ' . $i);
        }
        $events->append(new Event(SessionStore::PREFIX . 'ghost', 'session.turn', ['role' => 'user', 'content' => 'no start'], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 'ghost', DevToolsSource::DEBT_EVENT, ['signal' => 'framework_gap', 'context' => []], $events->nextSeq()));
        $events->append(new Event('other:stream', 'something.else', [], $events->nextSeq()));

        $source = new DevToolsSource(self::registered($events));
        $sessions = $source->snapshot()['sessions'];

        self::assertNull($sessions['error']);
        self::assertSame(53, $sessions['total']);
        self::assertCount(DevToolsSource::SESSIONS_LIMIT, $sessions['rows']);
        self::assertSame(3, $sessions['more']);
        self::assertSame('s-53', $sessions['rows'][0]['id'], 'newest first');
        self::assertSame('s-04', $sessions['rows'][49]['id'], 'the three oldest are the ones not listed');
        self::assertSame(1, $sessions['unstarted'], 'the stream without a session.started');
        self::assertNotContains('ghost', array_column($sessions['rows'], 'id'));
        self::assertSame(['ghost'], $source->snapshot()['debt']['kinds'][3]['sessions'], 'its facts are still facts of the ledger');
        self::assertTrue($source->timeline('ghost')['found'], 'and its stream can still be read');
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

        $debt = (new DevToolsSource(self::registered($events)))->snapshot()['debt'];

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

        $evidence = (new DevToolsSource(self::registered($events)))->snapshot()['evidence'];

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
        $source = new DevToolsSource(self::registered($events));

        $run = $source->timeline('s-run');
        self::assertTrue($run['found']);
        self::assertNull($run['error']);
        self::assertSame('s-run', $run['id']);
        self::assertSame(InMemoryEventStore::class, $run['source']);
        self::assertSame(0, $run['unreadable']);
        self::assertSame('s-run', $run['row']['id'] ?? null);
        self::assertSame(150, $run['row']['tokensIn'] ?? null);
        $painted = array_map(static fn (array $e): array => [$e['kind'], $e['detail'], $e['flags']], $run['events']);
        self::assertSame([
            ['opened', 'greet the house', ['auto']],
            ['thinking', '', []],
            ['tool', 'hola:greet', []],
            ['ready', '', []],
            ['debt', 'admitted_intent_skip — operation=hola:greet', []],
            ['evidence', 'operation_ok hola:greet', []],
            ['trial', 'make:crud · exit 0 · ws-1', []],
            ['operation', 'make:crud · rod@cli/rod@passkey · sha256:args', ['verified']],
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

        $paused = $source->timeline('s-paused');
        self::assertSame(
            [['opened', 'fifth', ['ask']], ['sequence-paused', 'seq-1 · 1/1', []], ['sequence-resumed', 'seq-1', []], ['sequence-paused', 'seq-1 · 1/1', []]],
            array_map(static fn (array $e): array => [$e['kind'], $e['detail'], $e['flags']], $paused['events']),
        );

        $nope = $source->timeline('nope');
        self::assertFalse($nope['found']);
        self::assertNull($nope['row']);
        self::assertSame([], $nope['events']);
        self::assertSame('nope', $nope['id']);
    }

    public function testOnlyTheBoundedAuditListIsPaintedLocallyAndTheProjectorGoesFirst(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s-a', 'audit');
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.ownership_asserted', ['assertion' => ['payload' => 'p', 'signature' => 's', 'fingerprint' => 'f', 'uid' => null]], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.ceiling_composed', ['x' => 1], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.model_called', ['x' => 1], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.model_reasoned', ['reasoning' => 'hmm'], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.system_set', ['system' => 'x'], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.compacted', ['summary' => 's', 'through' => 1], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.permission_granted', ['operation' => 'op'], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.mode_changed', ['mode' => 'auto'], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.somebody_elses', ['x' => 1], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.operation_executed', ['operation' => 'x:y', 'executed_by' => ['principal' => null, 'source' => 'cli', 'verified' => false], 'authorized_by' => null, 'arguments_digest' => ''], $events->nextSeq()));
        $events->append(new Event(SessionStore::PREFIX . 's-a', 'session.sequence_paused', [], $events->nextSeq()));

        $timeline = (new DevToolsSource(self::registered($events)))->timeline('s-a');

        self::assertSame(
            [
                ['opened', 'audit', ['ask']],
                ['operation', 'x:y · —@cli/—', []],
                ['sequence-paused', '', []],
            ],
            array_map(static fn (array $e): array => [$e['kind'], $e['detail'], $e['flags']], $timeline['events']),
            'what the projector leaves unpainted and the list does not name stays unpainted — including an unknown type',
        );
        self::assertSame(
            ['session.started', 'session.debt_signaled', 'session.closure_derived', 'session.trial_run_recorded', 'session.trial_promoted', 'session.trial_discarded', 'session.operation_executed', 'session.sequence_paused', 'session.sequence_resumed'],
            DevToolsSource::AUDIT_EVENTS,
        );
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

        $timeline = (new DevToolsSource(self::registered($events)))->timeline('s-all');

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
        self::assertSame(DevToolsSource::STATE_DONE, $timeline['row']['state'] ?? null);
    }

    public function testTheLogBlockReadsOnlyWhatTheAppDeclaredConfinedToTheAppRootAndNamesWhatItCannotRead(): void
    {
        $root = $this->root();
        mkdir($root . '/var');
        $absolute = $root . '/var/abs.log';
        file_put_contents($absolute, implode("\n", array_map(static fn (int $i): string => 'line ' . $i, range(1, 250))) . "\n");
        file_put_contents($root . '/var/empty.log', '');
        file_put_contents($root . '/var/short.log', "one\ntwo");
        mkdir($root . '/var/dir.log');
        $outside = $this->root();
        file_put_contents($outside . '/secret.log', "not yours\n");
        symlink($outside . '/secret.log', $root . '/var/link.log');
        $kernel = self::kernel($root, []);

        $log = static fn (mixed $declared, ?Kernel $kernel = null): array => (new DevToolsSource(self::container($declared, $kernel)))->log();

        self::assertSame(['declared' => false, 'path' => null, 'root' => null, 'error' => null, 'lines' => [], 'truncated' => false], $log(null));
        self::assertFalse($log('   ')['declared'], 'blank is not a declaration');
        self::assertFalse($log(42)['declared']);

        $tail = $log($absolute, $kernel);
        self::assertTrue($tail['declared']);
        self::assertSame($absolute, $tail['path']);
        self::assertSame($root, $tail['root']);
        self::assertNull($tail['error']);
        self::assertCount(DevToolsSource::LOG_LINES, $tail['lines']);
        self::assertSame('line 51', $tail['lines'][0]);
        self::assertSame('line 250', $tail['lines'][199]);
        self::assertTrue($tail['truncated']);

        $short = $log($root . '/var/short.log', $kernel);
        self::assertSame(['one', 'two'], $short['lines'], 'no trailing newline still reads the last line');
        self::assertFalse($short['truncated']);

        $relative = $log('var/short.log', $kernel);
        self::assertSame($root . '/var/short.log', $relative['path'], 'relative to the app root');
        self::assertSame(['one', 'two'], $relative['lines']);
        self::assertSame(['one', 'two'], $log('./var/../var/short.log', $kernel)['lines'], 'dots that stay inside the root are fine');

        $empty = $log($root . '/var/empty.log', $kernel);
        self::assertNull($empty['error']);
        self::assertSame([], $empty['lines']);
        self::assertFalse($empty['truncated']);

        $missing = $log($root . '/var/nope.log', $kernel);
        self::assertSame('missing', $missing['error']);
        self::assertSame($root . '/var/nope.log', $missing['path']);
        self::assertSame([], $missing['lines']);

        $dir = $log($root . '/var/dir.log', $kernel);
        self::assertSame('unreadable', $dir['error'], 'a path that is not a readable file');

        self::assertSame(DevToolsSource::LOG_OUTSIDE, $log($outside . '/secret.log', $kernel)['error'], 'an absolute path outside the root is not read');
        self::assertSame(DevToolsSource::LOG_OUTSIDE, $log('../' . basename($outside) . '/secret.log', $kernel)['error'], 'nor a relative path that climbs out');
        self::assertSame(DevToolsSource::LOG_OUTSIDE, $log('var/../../' . basename($outside) . '/secret.log', $kernel)['error']);
        $link = $log('var/link.log', $kernel);
        self::assertSame(DevToolsSource::LOG_OUTSIDE, $link['error'], 'nor a symlink inside the root that points outside');
        self::assertSame([], $link['lines']);
        self::assertSame(DevToolsSource::LOG_OUTSIDE, $log('/etc/passwd', $kernel)['error']);
        self::assertSame(DevToolsSource::LOG_OUTSIDE, $log($root . '-nope/x.log', $kernel)['error'], 'a lexical sibling of the root is outside before anything asks the filesystem whether it exists');

        $noRoot = $log('var/short.log');
        self::assertSame('missing', $noRoot['error'], 'without a kernel a relative path is never resolved against the working directory');
        self::assertNull($noRoot['root']);
        self::assertSame('var/short.log', $noRoot['path']);
        self::assertSame(DevToolsSource::LOG_OUTSIDE, $log($absolute)['error'], 'without a root nothing confines an absolute path, so it is not read');
    }

    public function testTheTailIsBoundedByLinesAndByBytesAndStaysLinearOnAFileWithoutNewlines(): void
    {
        $root = $this->root();
        $kernel = self::kernel($root, []);
        $log = static fn (string $path): array => (new DevToolsSource(self::container($path, $kernel)))->log();

        file_put_contents($root . '/huge.log', str_repeat('x', 5 * 1024 * 1024));
        $started = hrtime(true);
        $huge = $log($root . '/huge.log');
        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertNull($huge['error']);
        self::assertCount(1, $huge['lines'], 'no newline in the last MiB: the tail is one cut line');
        self::assertSame(DevToolsSource::LOG_BYTES, \strlen($huge['lines'][0]));
        self::assertTrue($huge['truncated'], 'the byte cap was hit');
        self::assertLessThan(2.0, $elapsed, 'collected once and joined once, never re-prepended');

        file_put_contents($root . '/wide.log', implode("\n", array_map(static fn (int $i): string => str_pad((string) $i, 20_000, '.'), range(1, 100))) . "\n");
        $wide = $log($root . '/wide.log');
        self::assertTrue($wide['truncated'], 'a hundred 20 KB lines exceed the byte cap before the line cap');
        self::assertLessThan(100, \count($wide['lines']));
        self::assertGreaterThan(50, \count($wide['lines']));
        self::assertStringStartsWith('100', $wide['lines'][\count($wide['lines']) - 1], 'the newest line is whole');

        file_put_contents($root . '/blank.log', "\n\n\n");
        self::assertSame(['lines' => [], 'truncated' => false], array_intersect_key($log($root . '/blank.log'), ['lines' => 1, 'truncated' => 1]));
    }

    public function testWithAKernelItReadsTheLedgerFileTolerantlyACorruptLineIsCountedNotFatal(): void
    {
        $root = $this->root();
        mkdir($root . '/var');
        file_put_contents($root . '/var/app.log', "alpha\nbeta\n");
        $kernel = self::kernel($root, ['admin' => ['log' => 'var/app.log']]);
        $source = new DevToolsSource(self::container('var/app.log', $kernel));

        self::assertSame(['available' => true, 'why' => null, 'source' => $root . '/var/agent-sessions.jsonl'], $source->availability(), 'the package and the kernel are there; the file is named');
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
        self::assertSame(0, $written['sessions']['unreadable']);
        self::assertSame(1, $written['debt']['kinds'][2]['count'], 'scope_fragility, from the fixture');
        self::assertSame('sha256:4c1e', $written['evidence']['items'][0]['reference']);
        self::assertTrue($source->timeline('s-file')['found']);
        self::assertSame(['opened', 'thinking', 'tool', 'debt', 'evidence', 'waiting'], array_column($source->timeline('s-file')['events'], 'kind'));
        self::assertSame('2026-09-04T10:00:00Z', $source->timeline('s-file')['events'][0]['when']);
        self::assertNull($source->timeline('s-file')['events'][1]['when'], 'a record written before recorded_at existed keeps its gap');

        $bad = [
            "{not json\n",
            "\n",
            "   \n",
            "[1,2,3]\n",
            json_encode(['stream_id' => 5, 'type' => 'x', 'payload' => [], 'seq' => 1]) . "\n",
            json_encode(['stream_id' => 's', 'type' => 'x', 'payload' => 'nope', 'seq' => 1]) . "\n",
            json_encode(['stream_id' => 's', 'type' => 'x', 'payload' => [], 'seq' => '1']) . "\n",
            json_encode(['stream_id' => 's', 'type' => 'x', 'payload' => [], 'seq' => 1, 'recorded_at' => 12]) . "\n",
            json_encode(['stream_id' => 's', 'type' => 'x', 'payload' => [], 'seq' => 1, 'recorded_at' => 'not a date']) . "\n",
            json_encode(['stream_id' => SessionStore::PREFIX . 's-file', 'type' => 'session.debt_signaled', 'payload' => ['signal' => 'framework_gap', 'context' => []], 'seq' => 8]) . "\n",
        ];
        file_put_contents($root . '/var/agent-sessions.jsonl', implode('', $bad), FILE_APPEND);
        $tolerant = $source->snapshot();
        self::assertNull($tolerant['sessions']['error'], 'corrupt lines do not blank the ledger blocks');
        self::assertSame(['s-file'], array_column($tolerant['sessions']['rows'], 'id'));
        self::assertSame(7, $tolerant['sessions']['unreadable'], 'the lines that are not events are counted — blank lines are not among them');
        self::assertSame(2, $tolerant['sessions']['rows'][0]['debt'], 'the good line after the corrupt ones still counts');
        self::assertNull($tolerant['debt']['error']);
        self::assertSame(1, $tolerant['debt']['kinds'][3]['count']);
        self::assertNull($tolerant['evidence']['error']);
        self::assertSame(['alpha', 'beta'], $tolerant['log']['lines'], 'the log reads from a different file and is unaffected');
        $timeline = $source->timeline('s-file');
        self::assertNull($timeline['error']);
        self::assertTrue($timeline['found']);
        self::assertSame(7, $timeline['unreadable']);
        self::assertSame('debt', $timeline['events'][6]['kind']);

        $store = new SessionStore($events = new InMemoryEventStore());
        $store->start('s-mem', 'in the container');
        $registered = self::container('var/app.log', $kernel);
        $registered->registerService(EventStoreInterface::class, $events);
        $viaStore = (new DevToolsSource($registered))->snapshot();
        self::assertSame(InMemoryEventStore::class, $viaStore['source'], 'a registered store is read as it gives itself — the file under the root is not touched');
        self::assertSame(['s-mem'], array_column($viaStore['sessions']['rows'], 'id'));
    }

    public function testAnUnreadableLedgerFileIsAnErrorOnEveryLedgerBlockAndNotOnTheLog(): void
    {
        $root = $this->root();
        mkdir($root . '/var');
        mkdir($root . '/var/agent-sessions.jsonl');
        file_put_contents($root . '/var/app.log', "alpha\n");
        $source = new DevToolsSource(self::container('var/app.log', self::kernel($root, [])));

        $snapshot = $source->snapshot();
        self::assertNull($snapshot['sessions']['error'], 'a directory where the file should be is not a file: an empty ledger');
        self::assertSame(['alpha'], $snapshot['log']['lines']);
        self::assertFalse($source->timeline('x')['found']);
    }

    /**
     * Five sessions in one ledger: running with real usage, waiting on a question, done with a closure
     * verdict, dead of silence, paused on a sequence — plus a debt signal, evidence, a trial run and an
     * executed operation.
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
        $store->recordExecution('s-run', 'make:crud', new Principal('rod', true), 'cli', ['principal' => 'rod', 'provenance' => 'passkey', 'session' => null], 'sha256:args');

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
        $store->recordSequenceResumed('s-paused', 'seq-1');
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

    /** A container with the event store registered under its interface — the first place the `agent` operation looks. */
    private static function registered(EventStoreInterface $events): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $events);

        return $container;
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
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
