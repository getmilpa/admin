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
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * The OTHER half of the containment falsifier (greenhouse decisions/0211, H6): a component that mounts
 * perfectly and throws while being PAINTED.
 *
 * {@see BrokenRenderer} throws because {@see BrokenComponent} throws in `mount()`, so it only exercises
 * the mount half. This one mounts a real state — the component is sound, the snapshot exists — and then
 * fails in `render()`, which is where a guest's renderer actually breaks in the field: a missing template,
 * a codec that refuses a value, a seam that answers nothing. The panel must contain it identically.
 *
 * It DECLARES client assets and never gets to return them: what a surface declared is collected only when
 * it rendered, so a page must not carry the files of a region nobody can see.
 */
final class UnpaintableRenderer implements ComponentRendererInterface, DeclaresClientAssets
{
    /** What it throws — asserted verbatim in the failure region. */
    public const BOOM = 'the guest blew up while painting';

    /** Declared, and never emitted: the throw happens before the result carries them. */
    public const SCRIPT = '/lab/assets/unpaintable.js';

    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    public function clientAssets(): ClientAssets
    {
        return new ClientAssets(scripts: [self::SCRIPT]);
    }

    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        // The state is real: this component mounts. Only the painting fails.
        $request->state ?? $component->mount($request->props, $request->context);

        throw new \RuntimeException(self::BOOM);
    }
}
