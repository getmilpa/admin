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
 * A custom component a foreign plugin brings: its state is whatever `text` prop it mounted with.
 */
final class EchoComponent implements ComponentDefinitionInterface
{
    public const NAME = 'echo-panel';

    public static function contract(): ComponentContract
    {
        return new ComponentContract(name: self::NAME, contractVersion: '1', propsSchema: ['text' => ['type' => 'string']]);
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, self::NAME, '1', ['text' => (string) ($props['text'] ?? '')]);
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult($request->state);
    }
}
