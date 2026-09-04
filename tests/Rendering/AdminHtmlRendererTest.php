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
use Milpa\Admin\Components\SettingsComponent;
use Milpa\Admin\Components\StackComponent;
use Milpa\Admin\Data\PluginsSource;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\Data\SettingsSource;
use Milpa\Admin\Data\StackSource;
use Milpa\Admin\Http\LoopbackOnlyMiddleware;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Rendering\AdminHtmlRenderer;
use Milpa\Admin\Stack\ComposeProjection;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Admin\Tests\Fixtures\FakeProbe;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
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

    public function testSettingsDeclaredTableWithSourcesPreferencesAndTheSecretNeverAppears(): void
    {
        $secret = 'hunter2-never-shown-0123456789';
        $component = self::settings([
            'admin' => ['route' => '/panel', 'locale' => 'es', 'middleware' => [LoopbackOnlyMiddleware::class], 'secret' => $secret, 'title' => 'Casa'],
        ]);

        $html = self::renderer()->render($component, new RenderRequest(context: new ComponentContext(componentId: 'st1')))->output;

        self::assertStringContainsString('admin-section--admin-settings', $html);
        self::assertStringContainsString('What this app declared about its panel', $html);
        self::assertStringContainsString('<h3 class="mui-h3">Panel preferences</h3>', $html);
        self::assertStringContainsString('This browser only — applied instantly, never sent to the server.', $html);
        self::assertStringContainsString('<form class="admin-prefs" data-prefs="">', $html);
        self::assertStringContainsString('<select class="mui-input mui-input--sm" data-pref="theme" id="admin-pref-theme"><option value="dark">dark</option><option value="light">light</option><option value="system">system</option></select>', $html);
        self::assertStringContainsString('data-pref="density"', $html);
        self::assertStringContainsString('<option value="comfortable">comfortable</option><option value="compact">compact</option>', $html);
        self::assertStringContainsString('<select class="mui-input mui-input--sm" data-pref="lang" id="admin-pref-lang"><option value="server">server (es)</option><option value="en">en</option><option value="es">es</option></select>', $html, 'server means admin.locale, which this app declared as es');
        self::assertStringContainsString('overrides admin.locale in this browser only', $html);
        self::assertStringContainsString('<input type="checkbox" data-pref="filters" id="admin-pref-filters"> Remember table filters', $html);
        self::assertStringNotContainsString('x-data', $html, 'no per-instance state: the page\'s delegated script owns the controls');
        self::assertStringNotContainsString('type="submit"', $html, 'nothing to save');

        self::assertStringContainsString('<h3 class="mui-h3">Configuration</h3>', $html);
        self::assertStringContainsString('Read-only — the admin key of config/app.php.', $html);
        self::assertStringContainsString('<th scope="col">Key</th><th scope="col">Value</th><th scope="col">Source</th>', $html);
        self::assertStringContainsString('<tr><td><code>route</code></td><td><code>/panel</code></td><td><span class="mui-badge mui-badge--accent">config</span></td></tr>', $html);
        self::assertStringContainsString('<tr><td><code>locale</code></td><td><code>es</code></td><td><span class="mui-badge mui-badge--accent">config</span></td></tr>', $html);
        self::assertStringContainsString('<tr><td><code>middleware</code></td><td><code>LoopbackOnlyMiddleware</code></td><td><span class="mui-badge mui-badge--accent">config</span></td></tr>', $html);
        self::assertStringContainsString('<tr><td><code>secret</code></td><td><span class="admin-settings__secret" aria-hidden="true">●●●</span>declared (admin.secret)</td><td><span class="mui-badge mui-badge--accent">config</span></td></tr>', $html);
        self::assertStringContainsString('<tr><td><code>title</code></td><td><code>Casa</code></td><td><span class="mui-badge mui-badge--accent">config</span></td></tr>', $html);
        self::assertStringNotContainsString($secret, $html, 'the secret is nowhere — not in the table, not in the state envelope');
        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringNotContainsString('has no admin key', $html);
        self::assertStringNotContainsString('mui-alert--danger', $html);
        self::assertStringNotContainsString('admin-snippet', $html);
        self::assertStringContainsString('data-milpa-state="st1"', $html);

        $mounted = $component->mount([], new ComponentContext(componentId: 'st1'));
        self::assertArrayHasKey('action', $component->handle(new InteractionRequest('st1', SettingsComponent::NAME, 'save', $mounted))->errors, 'read-only');
    }

    public function testSettingsEmptyStateShowsTheDefaultsAndTheSnippetToPaste(): void
    {
        $html = self::renderer()->render(self::settings([]), new RenderRequest(context: new ComponentContext(componentId: 'st2')))->output;

        self::assertStringContainsString('<p class="mui-alert mui-alert--info admin-notice">Running entirely on defaults: config/app.php has no admin key. Add one to change the route, the locale or the gate:</p>', $html);
        self::assertStringContainsString('<pre class="admin-snippet"><code>', $html);
        self::assertStringContainsString('\Milpa\Admin\Http\LoopbackOnlyMiddleware::class]],</code></pre>', $html);
        self::assertSame(5, substr_count($html, '<span class="mui-badge">default</span>'), 'the five values, every one a default');
        self::assertStringNotContainsString('mui-badge--accent', $html);
        self::assertStringContainsString('●●●</span>derived</td>', $html);
        self::assertStringContainsString('<option value="server">server (en)</option>', $html);
        self::assertStringNotContainsString('mui-alert--danger', $html);
    }

    public function testSettingsUnresolvedMiddlewareWearsTheDangerBadgeAndSaysThePanelFellBack(): void
    {
        $html = self::renderer()->render(
            self::settings(['admin' => ['middleware' => [LoopbackOnlyMiddleware::class, 'Acme\\Nope']]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st3')),
        )->output;

        self::assertStringContainsString(
            '<p class="mui-alert mui-alert--danger admin-notice">One configured value does not resolve: admin.middleware names Acme\Nope, which does not exist. The panel fell back to the loopback-only gate for every request — never to open. Every other key loaded.</p>',
            $html,
        );
        self::assertStringContainsString(
            '<tr class="admin-settings__row--unresolved"><td><code>middleware</code></td><td><code>LoopbackOnlyMiddleware, Acme\Nope</code> <span class="mui-badge mui-badge--danger">unresolved</span></td><td><span class="mui-badge mui-badge--accent">config</span></td></tr>',
            $html,
        );
        self::assertStringNotContainsString('has no admin key', $html, 'declared, so no empty state');
        self::assertSame(1, substr_count($html, 'mui-badge--danger'), 'only the middleware row is broken');

        $two = self::renderer()->render(
            self::settings(['admin' => ['middleware' => ['Acme\\Nope', 'Acme\\Missing']]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st4')),
        )->output;
        self::assertStringContainsString('admin.middleware names Acme\Nope and Acme\Missing, which do', $two);
    }

    public function testSettingsSpanishTwinAndTheContextLocaleChoosesTheCatalog(): void
    {
        $component = self::settings(['admin' => ['middleware' => ['Acme\\Nope'], 'secret' => 'hunter2-nope']]);

        $spanish = self::renderer('es')->render($component, new RenderRequest(context: new ComponentContext(componentId: 'st5')))->output;
        self::assertStringContainsString('Lo que esta app declaró sobre su panel', $spanish);
        self::assertStringContainsString('<h3 class="mui-h3">Preferencias del panel</h3>', $spanish);
        self::assertStringContainsString('<option value="dark">oscuro</option>', $spanish);
        self::assertStringContainsString('<option value="server">servidor (en)</option>', $spanish);
        self::assertStringContainsString('<h3 class="mui-h3">Configuración</h3>', $spanish);
        self::assertStringContainsString('<th scope="col">Llave</th><th scope="col">Valor</th><th scope="col">Origen</th>', $spanish);
        self::assertStringContainsString('Un valor configurado no resuelve: admin.middleware nombra Acme\Nope, que no existe. El panel cayó a la puerta sólo-loopback', $spanish);
        self::assertStringContainsString('<span class="mui-badge mui-badge--danger">no resuelve</span>', $spanish);
        self::assertStringContainsString('●●●</span>declarado (admin.secret)', $spanish);
        self::assertStringNotContainsString('hunter2', $spanish);

        $empty = self::renderer('es')->render(self::settings([]), new RenderRequest(context: new ComponentContext(componentId: 'st6')))->output;
        self::assertStringContainsString('Corriendo enteramente en defaults', $empty);
        self::assertStringContainsString('●●●</span>derivado', $empty);

        $viaContext = self::renderer('en')->render($component, new RenderRequest(context: new ComponentContext(componentId: 'st7', locale: 'es')))->output;
        self::assertStringContainsString('Preferencias del panel', $viaContext, 'the context locale wins over the catalog the renderer booted with');
        self::assertStringNotContainsString('Panel preferences', $viaContext);

        $unknown = self::renderer('en')->render($component, new RenderRequest(context: new ComponentContext(componentId: 'st8', locale: 'xx')))->output;
        self::assertStringContainsString('Panel preferences', $unknown, 'a locale the catalog lacks falls back to the renderer\'s own');

        $liveSecret = self::renderer()->render(self::settings(['live' => ['secret' => 'live-hunter2']]), new RenderRequest(context: new ComponentContext(componentId: 'st9')))->output;
        self::assertStringContainsString('●●●</span>declared (live.secret)</td><td><span class="mui-badge mui-badge--accent">config</span>', $liveSecret);
        self::assertStringNotContainsString('hunter2', $liveSecret);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function settings(array $config): SettingsComponent
    {
        return new SettingsComponent(new SettingsSource(AdminSettings::fromConfig($config === [] ? null : new Config($config))));
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
