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
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Paints {@see EchoComponent}: a div carrying a marker and the mounted text.
 */
final class EchoRenderer implements ComponentRendererInterface
{
    public const MARKER = 'ECHO-FROM-A-FOREIGN-PLUGIN';

    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $state = $request->state ?? $component->mount($request->props, $request->context);
        $text = htmlspecialchars((string) ($state->data['text'] ?? ''), ENT_QUOTES, 'UTF-8');

        return new RenderResult(
            output: '<div data-echo="' . self::MARKER . '" id="' . $state->componentId . '">' . $text . '</div>',
            state: $state,
        );
    }
}
