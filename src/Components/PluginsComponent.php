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

use Milpa\Admin\Data\PluginsSource;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ActionContract;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Plugins section as a Milpa Component: its state is the plugins table and the capability catalogue.
 *
 * Read-only in this slice — it declares no actions, so any interaction is refused by contract. Toggling
 * and installing are governed operations (`plugins.enable`, `capabilities:enable`) and enter as actions
 * when their slice lands, not as buttons wired around them.
 */
final class PluginsComponent implements ComponentDefinitionInterface
{
    public const NAME = 'admin-plugins';

    public function __construct(private readonly PluginsSource $source)
    {
    }

    /**
     * The contract: no props, a read-only state, and ONE action that is a button for an operation.
     *
     * This section showed the capability catalogue and could not enable anything — the panel could look and
     * not install, so an app that declines the agent could see what it might grow and had no way to grow it.
     *
     * `enable` does NOT carry an effect profile, and that is the design: it names `capabilities:enable`, which
     * already declares that it downloads third-party code, that its authority is privileged and that its
     * reversibility is manual recovery. A copy here would be a second source of truth about one act, and the
     * gate reads the operation's — so the copy could only ever be the wrong one (greenhouse decisions/0220).
     */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '2',
            summary: 'Every plugin the app declared or installed, and the capabilities it can grow.',
            stateSchema: [
                'registry' => ['type' => 'boolean'],
                'plugins' => ['type' => 'array'],
                'capabilities' => ['type' => 'array|null'],
            ],
            actions: [
                'enable' => new ActionContract(
                    summary: 'Install an opt-in capability this app lists as available.',
                    mutating: true,
                    namedTarget: 'capability',
                    invokes: 'capabilities:enable',
                    payload: ['capability' => 'string'],
                ),
            ],
        );
    }

    /** Mounts with the current snapshot of the plugin registry and the declared list. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            componentId: $context->componentId,
            componentName: self::NAME,
            version: '1',
            data: $this->source->snapshot(),
            meta: ['title' => (string) ($props['title'] ?? '')],
        );
    }

    /**
     * The section does not RUN the operation, and that is not a limitation — it is the seam.
     *
     * `capabilities:enable` is governed: over HTTP it answers `428` with a confirmation token before it does
     * anything, and only a second request carrying that token proceeds. A component that called the handler
     * would step around the very ceremony the panel exists to present. So the action is DECLARED here — so the
     * catalogue, the renderer and any gate can see what this button is — and the act itself travels the
     * operation's own surface.
     *
     * Anything else is still refused, by name.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $action = $request->state->componentName === self::NAME
            ? self::contract()->action($request->action)
            : null;

        if ($action?->invokes === null) {
            return new InteractionResult(
                state: $request->state,
                errors: ['action' => \sprintf('«%s» declares no action named «%s».', self::NAME, $request->action)],
            );
        }

        return new InteractionResult(
            state: $request->state,
            errors: ['action' => \sprintf(
                '«%s» is performed by the operation «%s», not by this component: it is governed and asks for '
                . 'confirmation before it runs. Post to its own surface.',
                $request->action,
                $action->invokes,
            )],
        );
    }
}
