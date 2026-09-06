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
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Paints {@see CounterComponent} and DECLARES what it needs on the client — the shape a guest's renderer
 * has under greenhouse decisions/0211: the module and the stylesheet the plugin serves from its own routes,
 * which the host emits once through `LiveBoot::html()`.
 *
 * Every instance declares the same two URLs, so a view that names the component twice still costs one
 * `<script>` and one `<link>`.
 */
final class CounterRenderer implements ComponentRendererInterface, DeclaresClientAssets
{
    public const SCRIPT = '/lab/assets/counter.js';
    public const STYLE = '/lab/assets/counter.css';

    public function __construct(private readonly StateTransferCodecInterface $codec)
    {
    }

    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    public function clientAssets(): ClientAssets
    {
        return new ClientAssets(scripts: [self::SCRIPT], styles: [self::STYLE]);
    }

    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $state = $request->state ?? $component->mount($request->props, $request->context);
        $html = '<div class="lab-counter" id="' . Html::escape($state->componentId) . '" data-count="' . (int) ($state->data['count'] ?? 0) . '">'
            . (int) ($state->data['count'] ?? 0)
            . '</div>'
            . '<script type="application/milpa+xhtml" data-milpa-state="' . Html::escape($state->componentId) . '">'
            . $this->codec->encodeState($state)
            . '</script>';

        return new RenderResult(output: $html, state: $state, format: RenderTarget::HTML);
    }
}
