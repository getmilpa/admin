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

namespace Milpa\Admin\Components;

use Milpa\Admin\Data\DevToolsSource;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Dev tools section as a Milpa Component: the ledgers the house already writes, read — the agent's
 * sessions with their state and real token cost, the debt signals by kind, the evidence ledger and the
 * declared log's tail; or, when the section's query names a session, that session's timeline.
 *
 * The one prop it reads is `query` — the request's query params the shell hands every active section —
 * and the one key it looks at is `session`: set, the mounted state carries the drill-down (`view:
 * session`); absent, the overview (`view: overview`). The STATE THAT TRAVELS is only `{view, session}`
 * ({@see self::envelope()}): the ledgers are a projection re-read on every mount, never signed and sent
 * back to the browser — an envelope with a thousand rows would be a second copy of the ledger with a
 * signature on it. Read-only in both views: it declares no action and refuses every one, because every
 * mutation of the house is a governed operation, never a button here (greenhouse decisions/0205).
 */
final class DevToolsComponent implements ComponentDefinitionInterface
{
    public const NAME = 'admin-devtools';

    /** The id the panel's own Dev tools section is declared under — where a session row links to. */
    public const SECTION = 'devtools';

    public const VIEW_OVERVIEW = 'overview';
    public const VIEW_SESSION = 'session';

    /** The query parameter that opens one session's timeline inside the section. */
    public const SESSION_PARAM = 'session';

    public function __construct(private readonly DevToolsSource $source)
    {
    }

    /** The contract: the request's `query` as its one prop; the state that travels is `{view, session}`; no actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '1',
            summary: 'The ledgers the house writes — agent sessions, debt signals, evidence, a declared log — read; nothing runs.',
            propsSchema: [
                'query' => ['type' => 'array'],
            ],
            stateSchema: [
                'view' => ['type' => 'string'],
                'session' => ['type' => 'string'],
            ],
        );
    }

    /**
     * Mounts with the overview, or with one session's timeline when `query.session` names it. The
     * mounted data carries the whole projection for the renderer; what travels is {@see self::envelope()}.
     */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $query = \is_array($props['query'] ?? null) ? $props['query'] : [];
        $id = $query[self::SESSION_PARAM] ?? null;
        $data = \is_string($id) && $id !== ''
            ? ['view' => self::VIEW_SESSION, 'session' => $id, ...$this->source->timeline($id)]
            : ['view' => self::VIEW_OVERVIEW, 'session' => null, ...$this->source->snapshot()];

        return new StateSnapshot(
            componentId: $context->componentId,
            componentName: self::NAME,
            version: '1',
            data: $data,
            meta: ['title' => (string) ($props['title'] ?? '')],
        );
    }

    /** Refuses every action: the section reads; it never acts. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => \sprintf('«%s» is read-only: it declares no actions — every mutation is a governed operation.', self::NAME)],
        );
    }

    /**
     * The state that travels in the signed envelope: `{view, session}` and nothing else — the two facts a
     * re-mount needs ({@see self::propsOf()}), never the rows, the log or the evidence.
     */
    public static function envelope(StateSnapshot $state): StateSnapshot
    {
        $session = $state->data['session'] ?? null;

        return new StateSnapshot(
            componentId: $state->componentId,
            componentName: self::NAME,
            version: $state->version,
            data: [
                'view' => ($state->data['view'] ?? null) === self::VIEW_SESSION && \is_string($session) && $session !== '' ? self::VIEW_SESSION : self::VIEW_OVERVIEW,
                'session' => \is_string($session) && $session !== '' ? $session : null,
            ],
            meta: $state->meta,
        );
    }

    /**
     * Whether a state is the travelling envelope — `{view, session}` and nothing else — as opposed to a
     * mounted state that carries the projection to paint.
     */
    public static function travels(StateSnapshot $state): bool
    {
        return array_diff_key($state->data, ['view' => true, 'session' => true]) === [];
    }

    /**
     * The props that re-mount the page a carried envelope names — so a renderer handed the travelling
     * `{view, session}` reads the ledgers again instead of painting an envelope that holds none.
     *
     * @return array<string, mixed>
     */
    public static function propsOf(StateSnapshot $carried): array
    {
        $session = $carried->data['session'] ?? null;

        return [
            'query' => \is_string($session) && $session !== '' ? [self::SESSION_PARAM => $session] : [],
            'title' => (string) ($carried->meta['title'] ?? ''),
        ];
    }
}
