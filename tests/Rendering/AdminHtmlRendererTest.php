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

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Components\RoutesComponent;
use Milpa\Admin\Components\StackComponent;
use Milpa\Admin\Data\PluginsSource;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\Data\StackSource;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Rendering\AdminHtmlRenderer;
use Milpa\Admin\Stack\ComposeProjection;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Admin\Tests\Fixtures\FakeProbe;
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
        $this->expectExceptionMessage('admin-stack');

        self::renderer()->render(new EchoComponent(), new RenderRequest(context: new ComponentContext(componentId: 'e')));
    }

    public function testStackCardsCarryTheStateBadgeTheMaskedSecretAndTheComposeFragment(): void
    {
        $state = new StateSnapshot('k1', StackComponent::NAME, '1', ['kernel' => true, 'services' => [
            [
                'name' => 'hub', 'image' => 'example/hub:1', 'ports' => ['3000:80'], 'volumes' => ['hub-data:/data'], 'command' => [],
                'env' => [
                    ['name' => 'SERVER_NAME', 'source' => 'literal', 'display' => ':80', 'configKey' => null],
                    ['name' => 'HUB_PUBLIC_URL', 'source' => 'config', 'display' => 'http://localhost:3000', 'configKey' => 'hub.public_url'],
                    ['name' => 'HUB_JWT_KEY', 'source' => 'secret', 'display' => null, 'configKey' => 'hub.key'],
                    ['name' => 'HUB_MODE', 'source' => 'unset', 'display' => null, 'configKey' => null],
                    'garbage',
                ],
                'summary' => 'Pushes shell changes.', 'plugin' => 'HubPlugin', 'probeHost' => 'fake.loopback', 'probePort' => 3000, 'state' => 'up', 'conflictsWith' => [],
                'compose' => "services:\n  hub:\n    image: 'example/hub:1'\n",
            ],
            ['name' => 'db', 'image' => 'postgres', 'ports' => ['5432:5432'], 'env' => [], 'volumes' => [], 'command' => ['postgres', '-c', 'x=y'], 'summary' => '', 'plugin' => 'DbPlugin', 'probeHost' => 'fake.loopback', 'probePort' => 5432, 'state' => 'down', 'conflictsWith' => [], 'compose' => ''],
            ['name' => 'cache', 'image' => 'redis', 'ports' => ['6379'], 'env' => [], 'volumes' => [], 'command' => [], 'summary' => '', 'plugin' => 'CachePlugin', 'probeHost' => 'fake.loopback', 'probePort' => null, 'state' => 'unknown', 'conflictsWith' => [], 'compose' => ''],
            'garbage',
        ]]);

        $html = self::renderer()->render(new StackComponent(new StackSource(new DIContainer(), new FakeProbe(), new ComposeProjection())), self::request($state))->output;

        self::assertStringContainsString('admin-section--admin-stack', $html);
        self::assertStringContainsString('href="/milpa/admin/stack/compose.yml"', $html);
        self::assertStringContainsString('Download compose.yml', $html);
        self::assertSame(3, substr_count($html, '<article class="mui-card admin-stack__service">'), 'one card per service, garbage skipped');
        self::assertStringContainsString('<span class="mui-badge mui-badge--success">up</span>', $html);
        self::assertStringContainsString('<span class="mui-badge mui-badge--warning">down</span>', $html);
        self::assertStringContainsString('<span class="mui-badge">unknown</span>', $html);
        self::assertStringContainsString('probed on fake.loopback:3000', $html, 'the host comes from the state, not from a constant');
        self::assertStringNotContainsString('127.0.0.1', $html);
        self::assertStringContainsString('no published port to probe', $html);
        self::assertStringNotContainsString('mui-alert--danger', $html, 'no collision, no notice');
        self::assertStringContainsString('<code>example/hub:1</code>', $html);
        self::assertStringContainsString('<code>3000:80</code>', $html);
        self::assertStringContainsString('<code>hub-data:/data</code>', $html);
        self::assertStringContainsString('<code>postgres</code> <code>-c</code> <code>x=y</code>', $html, 'the command as chips');
        self::assertStringContainsString('Pushes shell changes.', $html);
        self::assertStringContainsString('<td><code>SERVER_NAME</code></td><td>literal</td><td><code>:80</code></td>', $html);
        self::assertStringContainsString('<td>config <code>hub.public_url</code></td><td><code>http://localhost:3000</code></td>', $html);
        self::assertStringContainsString('<td>secret <code>hub.key</code></td><td><span class="admin-stack__secret">●●●</span></td>', $html, 'the mask glyph is the catalog\'s, painted from the source');
        self::assertStringContainsString('<td>unset</td><td><em>(unset)</em></td>', $html, 'so is the unset glyph');
        self::assertStringContainsString('Declared by HubPlugin', $html);
        self::assertStringContainsString('<pre class="admin-compose"><code>services:' . "\n" . '  hub:', $html);
        self::assertStringContainsString('data-milpa-state="k1"', $html);
        self::assertStringNotContainsString('kernel is not in the container', $html);
        self::assertStringNotContainsString('No plugin declared a service', $html);
    }

    public function testStackEmptyAndNoKernelNoticesAndTheSpanishTwin(): void
    {
        $component = new StackComponent(new StackSource(new DIContainer(), new FakeProbe(), new ComposeProjection()));

        $empty = new StateSnapshot('k2', StackComponent::NAME, '1', ['kernel' => false, 'services' => []]);
        $html = self::renderer()->render($component, self::request($empty))->output;
        self::assertStringContainsString('kernel is not in the container', $html);
        self::assertStringContainsString('Milpa\Runtime\Stack\StackProviderInterface', $html, 'the empty state names the contract');
        self::assertStringContainsString('href="/milpa/admin/stack/compose.yml"', $html, 'the compose link is there even when empty');

        $spanish = new StateSnapshot('k3', StackComponent::NAME, '1', ['kernel' => false, 'services' => [
            ['name' => 'hub', 'image' => 'x', 'ports' => [], 'env' => [['name' => 'K', 'source' => 'secret', 'display' => null, 'configKey' => null], ['name' => 'U', 'source' => 'unset', 'display' => null, 'configKey' => null]], 'volumes' => [], 'command' => [], 'summary' => '', 'plugin' => 'HubPlugin', 'probeHost' => '127.0.0.1', 'probePort' => 3000, 'state' => 'up', 'conflictsWith' => [], 'compose' => ''],
        ]]);
        $html = self::renderer('es')->render($component, self::request($spanish))->output;
        self::assertStringContainsString('El kernel no está en el container', $html);
        self::assertStringContainsString('Descargar compose.yml', $html);
        self::assertStringContainsString('>arriba</span>', $html);
        self::assertStringContainsString('sondeado en 127.0.0.1:3000', $html);
        self::assertStringContainsString('Declarado por HubPlugin', $html);
        self::assertStringContainsString('<td>secreto</td><td><span class="admin-stack__secret">●●●</span></td>', $html);
        self::assertStringContainsString('<td>sin valor</td><td><em>(sin valor)</em></td>', $html);
        self::assertStringContainsString('Servicios que necesitan los plugins arrancados', $html);
        self::assertStringContainsString('<dt>Imagen</dt>', $html);

        $mounted = $component->mount([], new ComponentContext(componentId: 'k4'));
        self::assertSame(['kernel' => false, 'services' => []], $mounted->data);
        self::assertArrayHasKey('action', $component->handle(new InteractionRequest('k4', StackComponent::NAME, 'x', $mounted))->errors);
    }

    public function testAConflictingServiceGetsADangerBadgeAndANoticeNamingTheOtherPlugins(): void
    {
        $row = static fn (string $plugin, array $others): array => [
            'name' => 'hub', 'image' => 'x', 'ports' => ['3000:80'], 'env' => [], 'volumes' => [], 'command' => [], 'summary' => '',
            'plugin' => $plugin, 'probeHost' => '127.0.0.1', 'probePort' => 3000, 'state' => 'conflict', 'conflictsWith' => $others, 'compose' => '',
        ];
        $state = new StateSnapshot('k5', StackComponent::NAME, '1', ['kernel' => true, 'services' => [
            $row('HubPlugin', ['RivalHubPlugin', 'ThirdPlugin']),
            $row('RivalHubPlugin', ['HubPlugin', 'ThirdPlugin']),
            $row('ThirdPlugin', ['HubPlugin', 'RivalHubPlugin']),
            $row('LonePlugin', ['OtherPlugin', 42]),
        ]]);
        $component = new StackComponent(new StackSource(new DIContainer(), new FakeProbe(), new ComposeProjection()));

        $html = self::renderer()->render($component, self::request($state))->output;

        self::assertSame(4, substr_count($html, '<span class="mui-badge mui-badge--danger">conflict</span>'), 'every colliding row wears the danger badge');
        self::assertStringContainsString(
            '<p class="mui-alert mui-alert--danger admin-notice">«hub» is also declared by RivalHubPlugin and ThirdPlugin — rename one or disable a plugin; no compose.yml is served while ids collide.</p>',
            $html,
        );
        self::assertStringContainsString('«hub» is also declared by HubPlugin and ThirdPlugin —', $html);
        self::assertStringContainsString('«hub» is also declared by OtherPlugin —', $html, 'one other plugin reads without a conjunction; garbage in the list is skipped');
        self::assertStringNotContainsString('mui-badge--success', $html);
        self::assertStringNotContainsString('mui-badge--warning', $html);

        $spanish = self::renderer('es')->render($component, self::request($state))->output;
        self::assertStringContainsString('<span class="mui-badge mui-badge--danger">conflicto</span>', $spanish);
        self::assertStringContainsString('«hub» también lo declara RivalHubPlugin y ThirdPlugin —', $spanish);
    }

    private static function renderer(string $locale = 'en'): AdminHtmlRenderer
    {
        return new AdminHtmlRenderer(
            new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null),
            new Catalog($locale),
            AdminSettings::fromConfig(null),
        );
    }

    private static function request(StateSnapshot $state): RenderRequest
    {
        return new RenderRequest(context: new ComponentContext(componentId: $state->componentId), state: $state);
    }
}
