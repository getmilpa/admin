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
 * A custom component a foreign plugin brings: its state is whatever `text` prop it mounted with — and it
 * remembers the {@see ComponentContext} the host handed its last `mount()`, so a test can see what a
 * guest receives (the principal, the locale, the route) without the guest painting it.
 */
final class EchoComponent implements ComponentDefinitionInterface
{
    public const NAME = 'echo-panel';

    /** The context the last `mount()` received, whichever instance mounted — what the host handed a foreign section. */
    public static ?ComponentContext $lastContext = null;

    public static function contract(): ComponentContract
    {
        return new ComponentContract(name: self::NAME, contractVersion: '1', propsSchema: ['text' => ['type' => 'string']]);
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        self::$lastContext = $context;

        return new StateSnapshot($context->componentId, self::NAME, '1', ['text' => (string) ($props['text'] ?? '')]);
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult($request->state);
    }
}
