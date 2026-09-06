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
 * A component that CARRIES STATE — one instance property is enough. Two instances of it under one name in
 * two layers are two definitions and therefore a conflict, which is milpa/live 0.18's rule and the reason
 * the panel can report one section shadowing another instead of letting order decide
 * (greenhouse decisions/0211).
 */
final class StatefulComponent implements ComponentDefinitionInterface
{
    public const NAME = 'stateful-panel';

    public function __construct(private readonly string $text = '')
    {
    }

    public static function contract(): ComponentContract
    {
        return new ComponentContract(name: self::NAME, contractVersion: '1', propsSchema: ['text' => ['type' => 'string']]);
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, self::NAME, '1', ['text' => (string) ($props['text'] ?? $this->text)]);
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult($request->state);
    }
}
