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

namespace Milpa\Admin\Tests\Fixtures;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * A guest component that ACTS: it declares a `bump` action, so a live interaction through the panel's own
 * wire can be measured end to end (greenhouse decisions/0211). Stateless — two instances under one name in
 * two layers are one definition, which is what lets a view share a component with another section.
 */
final class CounterComponent implements ComponentDefinitionInterface
{
    public const NAME = 'lab-counter';

    /** The context the last `mount()` received — what the host handed a node of a declared view. */
    public static ?ComponentContext $lastContext = null;

    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '1',
            propsSchema: ['count' => ['type' => 'integer', 'default' => 0]],
            stateSchema: ['count' => ['type' => 'integer']],
            actions: ['bump' => []],
        );
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        self::$lastContext = $context;

        return new StateSnapshot($context->componentId, self::NAME, '1', ['count' => (int) ($props['count'] ?? 0)]);
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        $count = (int) ($request->state->data['count'] ?? 0) + 1;

        return new InteractionResult(new StateSnapshot(
            $request->state->componentId,
            self::NAME,
            '1',
            ['count' => $count],
            $request->state->meta,
        ));
    }
}
