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

use Milpa\Admin\Data\RoutesSource;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Routes section as a Milpa Component: its state is the table of every declared route.
 *
 * Method, path, name, handler, per-route middleware and the declaring plugin — the whole declaration,
 * read-only. Declaring routes is a plugin's job (`RouteProviderInterface`); the panel shows what was
 * declared and by whom.
 */
final class RoutesComponent implements ComponentDefinitionInterface
{
    public const NAME = 'admin-routes';

    public function __construct(private readonly RoutesSource $source)
    {
    }

    /** The contract: no props, a read-only state, no actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '1',
            summary: 'Every route the booted plugins declared, with its handler and middleware.',
            stateSchema: [
                'kernel' => ['type' => 'boolean'],
                'routes' => ['type' => 'array'],
            ],
        );
    }

    /** Mounts with the routes the booted plugins declare right now. */
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

    /** Refuses every action: the section is read-only. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => \sprintf('«%s» is read-only: it declares no actions.', self::NAME)],
        );
    }
}
