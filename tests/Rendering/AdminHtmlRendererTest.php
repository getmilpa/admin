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

namespace Milpa\Admin\Tests\Rendering;

use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Components\RoutesComponent;
use Milpa\Admin\Data\PluginsSource;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Rendering\AdminHtmlRenderer;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Container\DIContainer;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

final class AdminHtmlRendererTest extends TestCase
{
    public function testPluginsTableWithRowsAndCapabilities(): void
    {
        $state = new StateSnapshot('s1', PluginsComponent::NAME, '1', [
            'registry' => true,
            'plugins' => [
                ['name' => 'Hola', 'version' => '1.2.3', 'type' => 'Web', 'enabled' => true, 'source' => 'declared', 'class' => 'App\\Hola'],
                ['name' => 'Off', 'version' => '0.1', 'type' => 'CLI', 'enabled' => false, 'source' => 'packagist', 'class' => null],
                'garbage',
            ],
            'capabilities' => [
                'installed' => [['id' => 'persistence', 'title' => 'Keep data'], 'garbage'],
                'available' => [['package' => 'milpa/web-search', 'title' => 'Search the web', 'command' => 'capabilities:enable --capability=web-search']],
                'source' => 'index',
            ],
        ]);

        $html = self::renderer()->render(new PluginsComponent(new PluginsSource(new DIContainer())), self::request($state))->output;

        self::assertStringContainsString('admin-section--admin-plugins', $html);
        self::assertStringContainsString('<td>Hola</td>', $html);
        self::assertStringContainsString('mui-badge--success', $html);
        self::assertStringContainsString('mui-badge--warning', $html);
        self::assertStringContainsString('<code>App\\Hola</code>', $html);
        self::assertStringContainsString('<code>persistence</code> — Keep data', $html);
        self::assertStringContainsString('capabilities:enable --capability=web-search', $html);
        self::assertStringContainsString('data-milpa-state="s1"', $html);
        self::assertStringNotContainsString('no plugin registry', $html);
    }

    public function testPluginsEmptyStatesInSpanish(): void
    {
        $state = new StateSnapshot('s2', PluginsComponent::NAME, '1', ['registry' => false, 'plugins' => [], 'capabilities' => null]);

        $html = self::renderer('es')->render(new PluginsComponent(new PluginsSource(new DIContainer())), self::request($state))->output;

        self::assertStringContainsString('no lleva registro de plugins', $html);
        self::assertStringContainsString('No hay ningún plugin declarado', $html);
        self::assertStringContainsString('Instala milpa/app-runtime', $html);
    }

    public function testRoutesTableAndEmptyStates(): void
    {
        $renderer = self::renderer();
        $component = new RoutesComponent(new RoutesSource(new DIContainer()));

        $full = new StateSnapshot('r1', RoutesComponent::NAME, '1', ['kernel' => true, 'routes' => [
            ['method' => 'GET', 'path' => '/x', 'name' => 'x', 'handler' => 'XController::show', 'middleware' => ['Gate'], 'plugin' => 'XPlugin'],
            ['method' => 'POST', 'path' => '/y', 'name' => '', 'handler' => '', 'middleware' => [], 'plugin' => 'YPlugin'],
            'garbage',
        ]]);
        $html = $renderer->render($component, self::request($full))->output;
        self::assertStringContainsString('<code>/x</code>', $html);
        self::assertStringContainsString('<code>Gate</code>', $html);
        self::assertStringContainsString('<td>—</td>', $html, 'no middleware shows the none glyph');
        self::assertStringNotContainsString('kernel is not in the container', $html);

        $empty = new StateSnapshot('r2', RoutesComponent::NAME, '1', ['kernel' => false, 'routes' => []]);
        $html = $renderer->render($component, self::request($empty))->output;
        self::assertStringContainsString('kernel is not in the container', $html);
        self::assertStringContainsString('No plugin declared a route', $html);
    }

    public function testMountsWhenNoStateIsGivenAndRefusesActions(): void
    {
        $component = new RoutesComponent(new RoutesSource(new DIContainer()));
        $result = self::renderer()->render($component, new RenderRequest(context: new ComponentContext(componentId: 'r3')));

        self::assertNotNull($result->state);
        self::assertSame('r3', $result->state->componentId);
        self::assertTrue(self::renderer()->supportsTarget(RenderTarget::HTML));
        self::assertFalse(self::renderer()->supportsTarget(RenderTarget::TUI));

        $refused = $component->handle(new InteractionRequest('r3', RoutesComponent::NAME, 'sort', $result->state));
        self::assertArrayHasKey('action', $refused->errors);

        $plugins = new PluginsComponent(new PluginsSource(new DIContainer()));
        $mounted = $plugins->mount(['title' => 'T'], new ComponentContext(componentId: 'p1'));
        self::assertSame('T', $mounted->meta['title']);
        self::assertArrayHasKey('action', $plugins->handle(new InteractionRequest('p1', PluginsComponent::NAME, 'x', $mounted))->errors);
    }

    public function testRefusesAComponentItDoesNotPaint(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::renderer()->render(new EchoComponent(), new RenderRequest(context: new ComponentContext(componentId: 'e')));
    }

    private static function renderer(string $locale = 'en'): AdminHtmlRenderer
    {
        return new AdminHtmlRenderer(
            new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null),
            new Catalog($locale),
        );
    }

    private static function request(StateSnapshot $state): RenderRequest
    {
        return new RenderRequest(context: new ComponentContext(componentId: $state->componentId), state: $state);
    }
}
