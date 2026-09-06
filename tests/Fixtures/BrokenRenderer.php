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
 * Mounts {@see BrokenComponent} and therefore throws — the ordinary path by which a guest's failure
 * reaches the host, since a renderer mounts what it was not handed.
 */
final class BrokenRenderer implements ComponentRendererInterface
{
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $state = $request->state ?? $component->mount($request->props, $request->context);

        return new RenderResult(output: '<div>unreachable</div>', state: $state, format: RenderTarget::HTML);
    }
}
