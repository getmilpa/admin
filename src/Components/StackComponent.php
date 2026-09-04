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

use Milpa\Admin\Data\StackSource;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Stack section as a Milpa Component: its state is every backing service the booted plugins declared
 * they need, with the state the panel observed for each.
 *
 * Image, ports, environment (secrets masked), volumes, command, the declaring plugin, the compose fragment
 * and whether the probe port answers — read-only. Declaring a service is a plugin's job
 * (`StackProviderInterface`); running it is the operator's; the panel shows what was declared, by whom,
 * and whether it is reachable.
 */
final class StackComponent implements ComponentDefinitionInterface
{
    public const NAME = 'admin-stack';

    public function __construct(private readonly StackSource $source)
    {
    }

    /** The contract: no props, a read-only state, no actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '1',
            summary: 'Every backing service the booted plugins declared they need, with its reachability.',
            stateSchema: [
                'kernel' => ['type' => 'boolean'],
                'services' => ['type' => 'array'],
            ],
        );
    }

    /** Mounts with the services the booted plugins declare right now, probed right now. */
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
