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
use Milpa\Admin\Components\DevToolsComponent;
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Components\RoutesComponent;
use Milpa\Admin\Components\SettingsComponent;
use Milpa\Admin\Components\StackComponent;
use Milpa\Admin\Data\DevToolsSource;
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
        self::assertStringContainsString('Theme and density are this browser only — applied instantly, never sent to the server.', $html);
        self::assertStringContainsString('<form class="admin-prefs" data-prefs="">', $html);
        self::assertStringContainsString('<select class="mui-input mui-input--sm" data-pref="theme" id="admin-pref-theme"><option value="dark">dark</option><option value="light">light</option><option value="system">system</option></select>', $html);
        self::assertStringContainsString('data-pref="density"', $html);
        self::assertStringContainsString('<option value="comfortable">comfortable</option><option value="compact">compact</option>', $html);
        self::assertStringContainsString('<select class="mui-input mui-input--sm" data-pref="lang" id="admin-pref-lang"><option value="server">server (es)</option><option value="en">en</option><option value="es">es</option></select>', $html, 'server means admin.locale, which this app declared as es');
        self::assertStringContainsString('sent as ?lang= with each request, never stored on the server', $html, 'the override travels, it is not kept server-side');
        self::assertStringNotContainsString('data-pref="filters"', $html, 'nothing consumes a remembered-filters preference, so the panel does not offer one');
        self::assertStringNotContainsString('type="checkbox"', $html);
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
        self::assertStringNotContainsString('mui-badge--danger', $html);
        self::assertStringNotContainsString('admin-settings__declared', $html, 'nothing was refused, nothing to explain');
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
        self::assertStringContainsString("\\Milpa\\Admin\\Http\\LoopbackOnlyMiddleware::class]],\n// or, behind milpa/app-runtime&#039;s PasskeyPlugin (app-runtime &gt;= 0.117), replace the middleware entry — the same key with the passkey gate:\n&#039;admin&#039; =&gt; [&#039;route&#039; =&gt; &#039;/milpa/admin&#039;, &#039;locale&#039; =&gt; &#039;en&#039;, &#039;middleware&#039; =&gt; [\\Milpa\\AppRuntime\\Web\\PasskeyGateMiddleware::class]],</code></pre>", $html, 'the alternative is an instruction and then the whole key on its own line — code, not a comment');
        self::assertStringNotContainsString('Behind a passkey', $html, 'the notice is for a panel that IS behind one');
        self::assertSame(5, substr_count($html, '<span class="mui-badge">default</span>'), 'the five values, every one a default');
        self::assertStringNotContainsString('mui-badge--accent', $html);
        self::assertStringContainsString('●●●</span>derived</td>', $html);
        self::assertStringContainsString('<option value="server">server (en)</option>', $html);
        self::assertStringNotContainsString('mui-alert--danger', $html);
    }

    public function testSettingsNamesThePasskeyGateOnTheMiddlewareRowAndInANoticeInBothLanguages(): void
    {
        $html = self::renderer()->render(
            self::settings(['admin' => ['middleware' => [AdminSettings::PASSKEY_GATE]]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st20')),
        )->output;

        self::assertStringContainsString('<tr><td><code>middleware</code></td><td><code>PasskeyGateMiddleware</code> <span class="mui-badge mui-badge--success">passkey</span></td><td><span class="mui-badge mui-badge--accent">config</span></td></tr>', $html, 'the row names it');
        self::assertStringContainsString('<p class="mui-alert mui-alert--info admin-notice">Behind a passkey: milpa/app-runtime&#039;s PasskeyPlugin mints the session when you sign in at /webauthn/signin (the page posts to /webauthn/authenticate) and its gate checks the scope. The panel names the gate and shows who it let in — it reads no cookie itself.</p>', $html);
        self::assertStringNotContainsString('mui-badge--danger', $html, 'a gate the panel knows by name is nothing to fix');
        self::assertStringNotContainsString('admin-snippet', $html, 'the app declared: no snippet');
        self::assertSame('passkey', self::envelopeOf($html)->data['gate'], 'the state carries the name too');

        $spanish = self::renderer('es')->render(
            self::settings(['admin' => ['middleware' => [AdminSettings::PASSKEY_GATE]]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st21')),
        )->output;
        self::assertStringContainsString('<code>PasskeyGateMiddleware</code> <span class="mui-badge mui-badge--success">passkey</span>', $spanish);
        self::assertStringContainsString('Detrás de una passkey: el PasskeyPlugin de milpa/app-runtime acuña la sesión cuando inicias sesión en /webauthn/signin (la página envía a /webauthn/authenticate)', $spanish);

        $snippet = self::renderer('es')->render(self::settings([]), new RenderRequest(context: new ComponentContext(componentId: 'st22')))->output;
        self::assertStringContainsString("\n// o, detrás del PasskeyPlugin de milpa/app-runtime (app-runtime &gt;= 0.117), sustituye la entrada de middleware — la misma llave con la puerta passkey:\n&#039;admin&#039; =&gt; [&#039;route&#039; =&gt; &#039;/milpa/admin&#039;, &#039;locale&#039; =&gt; &#039;en&#039;, &#039;middleware&#039; =&gt; [\\Milpa\\AppRuntime\\Web\\PasskeyGateMiddleware::class]],</code></pre>", $snippet, 'the snippet\'s instruction speaks the catalog\'s language; the code line is the same in both');

        $custom = self::renderer()->render(
            self::settings(['admin' => ['middleware' => [AdminSettings::PASSKEY_GATE, LoopbackOnlyMiddleware::class]]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st23')),
        )->output;
        self::assertStringContainsString('<td><code>PasskeyGateMiddleware, LoopbackOnlyMiddleware</code></td>', $custom, 'the control: the passkey gate inside a bigger stack is a custom stack, not «passkey»');
        self::assertStringNotContainsString('mui-badge--success', $custom);
        self::assertStringNotContainsString('Behind a passkey', $custom);

        $stale = new StateSnapshot(componentId: 'st24', componentName: SettingsComponent::NAME, version: '1', data: ['declared' => false, 'gate' => 'loopback', 'locale' => 'en', 'rows' => [], 'unresolved' => [], 'malformed' => false, 'snippet' => 'x']);
        $carried = self::renderer()->render(self::settings([]), self::request($stale))->output;
        self::assertStringContainsString('<pre class="admin-snippet"><code>x</code></pre>', $carried, 'an envelope from before the alternative line paints no half a line');
    }

    public function testSettingsUnresolvedMiddlewareWearsTheDangerBadgeAndSaysThePanelFellBack(): void
    {
        $html = self::renderer()->render(
            self::settings(['admin' => ['middleware' => [LoopbackOnlyMiddleware::class, 'Acme\\Nope']]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st3')),
        )->output;

        self::assertStringContainsString(
            '<p class="mui-alert mui-alert--danger admin-notice">One configured value does not resolve: admin.middleware names «Acme\Nope (class does not exist)». Every entry must name a PSR-15 middleware class, so the panel fell back to the loopback-only gate for every request — never to open. Every other key loaded.</p>',
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
        self::assertStringContainsString('admin.middleware names «Acme\Nope (class does not exist)» and «Acme\Missing (class does not exist)». Every entry', $two);

        $int = self::renderer()->render(
            self::settings(['admin' => ['middleware' => [42]]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st4b')),
        )->output;
        self::assertStringContainsString('admin.middleware names «int (not a class name)». Every entry', $int);
        self::assertStringContainsString('<td><code>middleware</code></td><td><code>int</code> <span class="mui-badge mui-badge--danger">unresolved</span></td><td><span class="mui-badge mui-badge--accent">config</span></td>', $int, 'a list was declared: config, with the defect on the value');

        $empty = self::renderer()->render(
            self::settings(['admin' => ['middleware' => ['']]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st4c')),
        )->output;
        self::assertStringContainsString('admin.middleware names «(empty)». Every entry', $empty, 'never an empty name in the sentence');
        self::assertStringContainsString('<td><code>middleware</code></td><td><code>(empty)</code> <span class="mui-badge mui-badge--danger">unresolved</span></td>', $empty);

        $notMiddleware = self::renderer()->render(
            self::settings(['admin' => ['middleware' => [\stdClass::class]]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st4d')),
        )->output;
        self::assertStringContainsString('admin.middleware names «stdClass (not a PSR-15 middleware)». Every entry', $notMiddleware);
    }

    public function testSettingsRejectedRowsShowTheEffectiveValueWhatWasDeclaredAndADangerSource(): void
    {
        $component = self::settings(['admin' => ['route' => 42, 'locale' => 'fr', 'middleware' => 'Acme\\Nope', 'title' => '']]);

        $html = self::renderer()->render($component, new RenderRequest(context: new ComponentContext(componentId: 'st10')))->output;

        self::assertStringContainsString(
            '<p class="mui-alert mui-alert--danger admin-notice">One configured value is malformed: admin.middleware must be a list of PSR-15 middleware class names, but the app declared string (not a list). The panel fell back to the loopback-only gate for every request — never to open. Every other key loaded.</p>',
            $html,
        );
        self::assertStringContainsString(
            '<tr class="admin-settings__row--rejected"><td><code>route</code></td><td><code>/milpa/admin</code> <span class="admin-settings__declared">(declared: int)</span></td><td><span class="mui-badge mui-badge--danger">rejected</span></td></tr>',
            $html,
        );
        self::assertStringContainsString(
            '<tr class="admin-settings__row--rejected"><td><code>locale</code></td><td><code>en</code> <span class="admin-settings__declared">(declared: fr)</span></td><td><span class="mui-badge mui-badge--danger">rejected</span></td></tr>',
            $html,
            'the effective value in the row, what was declared next to it',
        );
        self::assertStringContainsString(
            '<tr class="admin-settings__row--rejected"><td><code>middleware</code></td><td><code>LoopbackOnlyMiddleware</code> <span class="admin-settings__declared">(declared: string)</span></td><td><span class="mui-badge mui-badge--danger">rejected</span></td></tr>',
            $html,
            'a non-list gate is rejected whole: the strict gate in the row, no per-entry badge',
        );
        self::assertStringContainsString(
            '<tr class="admin-settings__row--rejected"><td><code>title</code></td><td><code>Milpa Admin</code> <span class="admin-settings__declared">(declared: (empty))</span></td><td><span class="mui-badge mui-badge--danger">rejected</span></td></tr>',
            $html,
        );
        self::assertStringContainsString('<tr><td><code>secret</code></td><td><span class="admin-settings__secret" aria-hidden="true">●●●</span>derived</td><td><span class="mui-badge">default</span></td></tr>', $html);
        self::assertSame(4, substr_count($html, 'mui-badge--danger'), 'four rejected sources, no unresolved badge');
        self::assertSame(1, substr_count($html, '<span class="mui-badge">default</span>'), 'only the secret is a plain default: nothing the app wrote is painted default');
        self::assertStringNotContainsString('has no admin key', $html, 'declared, so no empty state');
        self::assertStringContainsString('<option value="server">server (en)</option>', $html, 'the server locale is the one in effect, not the one refused');

        $spanish = self::renderer('es')->render($component, new RenderRequest(context: new ComponentContext(componentId: 'st11')))->output;
        self::assertStringContainsString('Un valor configurado está mal formado: admin.middleware debe ser una lista de nombres de clase de middleware PSR-15, pero la app declaró string (not a list).', $spanish);
        self::assertStringContainsString('<code>en</code> <span class="admin-settings__declared">(declarado: fr)</span></td><td><span class="mui-badge mui-badge--danger">rechazado</span>', $spanish);

        $map = self::renderer()->render(
            self::settings(['admin' => ['middleware' => [LoopbackOnlyMiddleware::class => true]]]),
            new RenderRequest(context: new ComponentContext(componentId: 'st12')),
        )->output;
        self::assertStringContainsString('but the app declared array (not a list).', $map);
        self::assertStringContainsString('<code>LoopbackOnlyMiddleware</code> <span class="admin-settings__declared">(declared: array)</span>', $map);
    }

    public function testSettingsEmptyStateSaysEntirelyOnDefaultsOnlyWhenEverySourceIsADefault(): void
    {
        $liveSecret = self::renderer()->render(self::settings(['live' => ['secret' => 'live-hunter2']]), new RenderRequest(context: new ComponentContext(componentId: 'st13')))->output;

        self::assertStringContainsString(
            '<p class="mui-alert mui-alert--info admin-notice">config/app.php has no admin key: the panel runs on defaults, except the secret it takes from live.secret. Add one to change the route, the locale or the gate:</p>',
            $liveSecret,
        );
        self::assertStringNotContainsString('Running entirely on defaults', $liveSecret, 'the secret is not a default, so the panel does not say it is');
        self::assertStringContainsString('<pre class="admin-snippet"><code>', $liveSecret, 'the snippet to paste is still offered');
        self::assertStringContainsString('●●●</span>declared (live.secret)</td><td><span class="mui-badge mui-badge--accent">config</span>', $liveSecret);
        self::assertSame(4, substr_count($liveSecret, '<span class="mui-badge">default</span>'));
        self::assertStringNotContainsString('hunter2', $liveSecret);

        $spanish = self::renderer('es')->render(self::settings(['live' => ['secret' => 'live-hunter2']]), new RenderRequest(context: new ComponentContext(componentId: 'st14')))->output;
        self::assertStringContainsString('config/app.php no tiene llave admin: el panel corre en defaults, salvo el secreto que toma de live.secret.', $spanish);
        self::assertStringNotContainsString('Corriendo enteramente en defaults', $spanish);
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
        self::assertStringContainsString('Un valor configurado no resuelve: admin.middleware nombra «Acme\Nope (class does not exist)». Cada entrada debe nombrar una clase de middleware PSR-15, así que el panel cayó a la puerta sólo-loopback', $spanish);
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

    public function testDevToolsOverviewPaintsEveryLedgerWithLinksIntoTheTimelineAndNotOneControl(): void
    {
        $state = new StateSnapshot('d1', DevToolsComponent::NAME, '1', [
            'view' => DevToolsComponent::VIEW_OVERVIEW,
            'session' => null,
            'available' => true,
            'why' => null,
            'source' => '/srv/app/var/agent-sessions.jsonl',
            'sessions' => ['error' => null, 'total' => 7, 'more' => 2, 'unstarted' => 1, 'unreadable' => 3, 'rows' => [
                ['id' => 's_7f21', 'goal' => 'greet <the> house', 'mode' => 'auto', 'state' => 'running', 'tokensIn' => 18204, 'tokensOut' => 3911, 'pending' => ['reason' => 'target_not_named', 'question' => 'Which "target"?']],
                ['id' => 's_7e0c', 'goal' => 'second', 'mode' => 'ask', 'state' => 'waiting', 'tokensIn' => null, 'tokensOut' => null, 'pending' => ['reason' => '', 'question' => 'ok?']],
                ['id' => 's_7dfa', 'goal' => 'third', 'mode' => 'ask', 'state' => 'done', 'tokensIn' => 1022, 'tokensOut' => 0, 'pending' => null],
                ['id' => 's_7d00', 'goal' => 'fourth', 'mode' => 'ask', 'state' => 'interrupted', 'tokensIn' => 0, 'tokensOut' => 0, 'pending' => null],
                ['id' => 's_zzzz', 'goal' => 'fifth', 'mode' => 'ask', 'state' => 'odd', 'tokensIn' => 1, 'tokensOut' => 1, 'pending' => ['reason' => 'sequence_paused', 'question' => '']],
                'garbage',
            ]],
            'debt' => ['error' => null, 'total' => 3, 'kinds' => [
                ['kind' => 'admitted_intent_skip', 'count' => 2, 'sessions' => ['s_7f21', 's_7e0c', 42]],
                ['kind' => 'framework_gap', 'count' => 1, 'sessions' => ['s_7dfa']],
                ['kind' => 'scope_fragility', 'count' => 0, 'sessions' => []],
                ['kind' => 'weird', 'count' => 1, 'sessions' => ['s_7dfa']],
                'garbage',
            ]],
            'evidence' => ['error' => null, 'items' => [
                ['seq' => 9, 'session' => 's_7f21', 'when' => '2026-09-04T14:02:11Z', 'kind' => 'operation_ok', 'reference' => 'capabilities:enable', 'todo' => 't1', 'detail' => 'exit 0'],
                ['seq' => 8, 'session' => 's_7e0c', 'when' => null, 'kind' => 'artifact_created', 'reference' => 'sha256:4c1e', 'todo' => null, 'detail' => null],
                'garbage',
            ]],
            'log' => ['declared' => true, 'path' => '/srv/app/var/app.log', 'root' => '/srv/app', 'error' => null, 'lines' => ['14:02:11 info  operation completed', '14:01:29 warn  probe timeout <postgres:5432>'], 'truncated' => true],
        ]);

        $result = self::renderer()->render(self::devtools(), self::request($state));
        $html = $result->output;

        self::assertStringContainsString('admin-section--admin-devtools', $html);
        self::assertStringContainsString('<h2 class="mui-h2">Dev tools — the ledgers the house writes <span class="mui-badge">read-only</span></h2>', $html);
        self::assertStringContainsString('Nothing here runs anything.', $html);
        self::assertStringContainsString('<h3 class="mui-h3">Agent sessions</h3>', $html);
        self::assertStringContainsString('<th scope="col">Session</th><th scope="col">State</th><th scope="col">Goal</th><th scope="col">Mode</th><th scope="col">tokens in / out</th><th scope="col">Pending</th>', $html);
        self::assertStringContainsString('<td><a href="/milpa/admin/s/devtools?session=s_7f21"><code>s_7f21</code></a></td><td><span class="mui-badge mui-badge--success" data-state="running">running</span></td><td>greet &lt;the&gt; house</td><td><code>auto</code></td><td><code>18,204 / 3,911</code></td><td><span class="mui-badge mui-badge--accent">target_not_named</span> <small>Which &quot;target&quot;?</small></td>', $html, 'the question is inline beside the reason, not hidden in a title');
        self::assertStringContainsString('<span class="mui-badge mui-badge--accent" data-state="waiting">waiting</span></td><td>second</td><td><code>ask</code></td><td><code>not reported</code></td><td><span class="mui-badge mui-badge--accent">decision</span> <small>ok?</small></td>', $html, 'absent tokens are not zero; a pending without a reason code is a decision');
        self::assertStringContainsString('<span class="mui-badge" data-state="done">done</span></td><td>third</td><td><code>ask</code></td><td><code>1,022 / 0</code></td><td>—</td>', $html);
        self::assertStringContainsString('<span class="mui-badge mui-badge--warning" data-state="interrupted">interrupted</span>', $html);
        self::assertStringContainsString('<span class="mui-badge" data-state="odd">odd</span>', $html, 'a state the catalog lacks is painted as it came');
        self::assertStringContainsString('<td><span class="mui-badge mui-badge--accent">sequence_paused</span></td>', $html, 'no question text, no small');
        self::assertSame(5, substr_count($html, 'data-state="'), 'garbage rows are skipped');
        self::assertStringContainsString('</table></div>' . "\n" . '<p class="admin-devtools__hint">Read from /srv/app/var/agent-sessions.jsonl · 3 line(s) of the ledger could not be read and were skipped · 1 stream(s) without a session.started, not listed · 2 older session(s) not listed</p>', $html, 'under the table: where it was read from and what the read left out');

        self::assertStringContainsString('<h3 class="mui-h3">Debt signals</h3>', $html);
        self::assertStringContainsString('DebtSignal', $html);
        self::assertStringNotContainsString('No debt signal recorded yet', $html);
        self::assertStringContainsString('<th scope="col">Kind</th><th scope="col">Count</th><th scope="col">Sessions</th>', $html);
        self::assertStringContainsString('<tr><td><code>admitted_intent_skip</code><br><small>Ceremony was skipped because an exact confirmed intent claim admitted the call — visible as a signal, never as authority.</small></td><td>2</td><td><a href="/milpa/admin/s/devtools?session=s_7f21"><code>s_7f21</code></a> <a href="/milpa/admin/s/devtools?session=s_7e0c"><code>s_7e0c</code></a></td></tr>', $html, 'each real kind carries its one-line gloss');
        self::assertStringContainsString('<tr><td><code>scope_fragility</code><br><small>A consent denial landed', $html);
        self::assertStringContainsString('<tr><td><code>framework_gap</code><br><small>The model declared a stalled leg', $html);
        self::assertStringContainsString('<tr><td><code>weird</code></td><td>1</td>', $html, 'a kind the catalog does not know has no gloss');

        self::assertStringContainsString('<h3 class="mui-h3">Evidence</h3>', $html);
        self::assertStringContainsString('<th scope="col">Time</th><th scope="col">Session</th><th scope="col">Kind</th><th scope="col">Reference</th>', $html);
        self::assertStringContainsString('<tr><td><time datetime="2026-09-04T14:02:11Z">2026-09-04T14:02:11Z</time></td><td><a href="/milpa/admin/s/devtools?session=s_7f21"><code>s_7f21</code></a></td><td><code>operation_ok</code></td><td><code>capabilities:enable</code> <small>closes todo t1</small> <small>exit 0</small></td></tr>', $html);
        self::assertStringContainsString('<tr><td>—</td><td><a href="/milpa/admin/s/devtools?session=s_7e0c"><code>s_7e0c</code></a></td><td><code>artifact_created</code></td><td><code>sha256:4c1e</code></td></tr>', $html, 'a record without its instant keeps the gap');

        self::assertStringContainsString('<h3 class="mui-h3">Logs</h3>', $html);
        self::assertStringContainsString('<p class="admin-devtools__hint">last 2 lines of /srv/app/var/app.log · older lines not shown</p>', $html);
        self::assertStringContainsString("<pre class=\"admin-log\"><code>14:02:11 info  operation completed\n14:01:29 warn  probe timeout &lt;postgres:5432&gt;</code></pre>", $html);

        self::assertStringNotContainsString('<form', $html, 'the doctrine control: nothing here acts');
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('<input', $html);
        self::assertStringContainsString('data-milpa-state="d1"', $html);

        self::assertSame(['view' => 'overview', 'session' => null], self::envelopeOf($html)->data, 'the signed envelope carries the view and the session — never the rows, the log or the evidence');
        self::assertSame(['view' => 'overview', 'session' => null], $result->state?->data);
        self::assertStringNotContainsString(base64_encode('greet <the> house'), $html);
        self::assertStringNotContainsString(base64_encode('"tokensIn"'), $html);

        $spanish = self::renderer()->render(self::devtools(), self::request($state, 'es'))->output;
        self::assertStringContainsString('<a href="/milpa/admin/s/devtools?session=s_7f21&amp;lang=es"><code>s_7f21</code></a>', $spanish, 'a request that overrode the locale gets links that carry it');
        self::assertStringContainsString('Leído de /srv/app/var/agent-sessions.jsonl · 3 línea(s) del ledger no se pudieron leer y se saltaron · 1 stream(s) sin session.started, no listados · 2 sesión(es) más antiguas no listadas', $spanish);
        self::assertStringContainsString('<small>Se saltó la ceremonia porque', $spanish);
        self::assertStringNotContainsString('lang=es', self::renderer('es')->render(self::devtools(), self::request($state))->output, 'a panel whose own locale is Spanish overrode nothing: no lang on its links');
    }

    public function testDevToolsEmptyErrorAndNotAvailableStatesEachSayWhatTheyMean(): void
    {
        $component = self::devtools();
        $log = ['declared' => false, 'path' => null, 'root' => null, 'error' => null, 'lines' => [], 'truncated' => false];
        $kinds = array_map(static fn (string $kind): array => ['kind' => $kind, 'count' => 0, 'sessions' => []], DevToolsSource::DEBT_KINDS);

        $empty = new StateSnapshot('d2', DevToolsComponent::NAME, '1', [
            'view' => 'overview', 'session' => null, 'available' => true, 'why' => null, 'source' => 'Milpa\EventStore\InMemoryEventStore',
            'sessions' => ['error' => null, 'rows' => [], 'total' => 0, 'more' => 0, 'unstarted' => 0, 'unreadable' => 0],
            'debt' => ['error' => null, 'total' => 0, 'kinds' => $kinds],
            'evidence' => ['error' => null, 'items' => []],
            'log' => $log,
        ]);
        $html = self::renderer()->render($component, self::request($empty))->output;
        self::assertStringContainsString('Nothing recorded yet — sessions appear as the agent runs. An empty ledger is a fresh install, not a fault.', $html);
        self::assertStringContainsString('<p class="admin-devtools__hint">Read from Milpa\EventStore\InMemoryEventStore</p>', $html, 'the empty state still says where it looked');
        self::assertStringContainsString('No debt signal recorded yet', $html);
        self::assertSame(4, substr_count($html, '<td>0</td>'), 'the four real kinds are listed at zero: the honest empty');
        self::assertStringContainsString('<code>high_tier_double_ceremony</code><br><small>A question was asked although', $html);
        self::assertStringContainsString('No evidence recorded yet', $html);
        self::assertStringContainsString('No log file is declared. Declare admin.log in config/app.php', $html);
        self::assertStringNotContainsString('<pre', $html);

        $errors = new StateSnapshot('d3', DevToolsComponent::NAME, '1', [
            'view' => 'overview', 'session' => null, 'available' => true, 'why' => null, 'source' => '/srv/app/var/agent-sessions.jsonl',
            'sessions' => ['error' => 'Unable to lock', 'rows' => []],
            'debt' => ['error' => 'Unable to lock', 'total' => 0, 'kinds' => $kinds],
            'evidence' => ['error' => 'Unable to lock', 'items' => []],
            'log' => ['declared' => true, 'path' => 'var/coa.log', 'root' => null, 'error' => 'unreadable', 'lines' => [], 'truncated' => false],
        ]);
        $html = self::renderer()->render($component, self::request($errors))->output;
        self::assertStringContainsString('<p class="mui-alert mui-alert--danger admin-notice">The session ledger could not be read: Unable to lock</p>' . "\n" . '<p class="admin-devtools__hint">Read from /srv/app/var/agent-sessions.jsonl</p>', $html, 'the error names the store it tried');
        self::assertStringContainsString('The debt signals could not be read: Unable to lock', $html);
        self::assertStringContainsString('The evidence ledger could not be read: Unable to lock', $html);
        self::assertStringContainsString('The log file could not be read: var/coa.log. Sessions, debt and evidence read from a different store and are unaffected.', $html);
        self::assertStringContainsString('<p class="mui-alert mui-alert--warning admin-notice">The app root is unknown — the kernel is not in the container — so the declared path is neither resolved nor confined, and nothing is read. Register the kernel in public/index.php.</p>', $html, 'no root: the log says why nothing was read');
        self::assertSame(4, substr_count($html, 'mui-alert--danger'), 'one notice per block, no block blanked');

        $missing = new StateSnapshot('d4', DevToolsComponent::NAME, '1', [
            'view' => 'overview', 'session' => null, 'available' => true, 'why' => null, 'source' => 'x',
            'sessions' => ['error' => null, 'rows' => []], 'debt' => ['error' => null, 'total' => 0, 'kinds' => $kinds], 'evidence' => ['error' => null, 'items' => []],
            'log' => ['declared' => true, 'path' => '/srv/nope.log', 'root' => '/srv', 'error' => 'missing', 'lines' => [], 'truncated' => false],
        ]);
        $html = self::renderer()->render($component, self::request($missing))->output;
        self::assertStringContainsString('The declared log file does not exist: /srv/nope.log.', $html);
        self::assertStringNotContainsString('The app root is unknown', $html, 'a root was known: the notice is not painted');

        $outside = new StateSnapshot('d4b', DevToolsComponent::NAME, '1', [
            'view' => 'overview', 'session' => null, 'available' => true, 'why' => null, 'source' => 'x',
            'sessions' => ['error' => null, 'rows' => []], 'debt' => ['error' => null, 'total' => 0, 'kinds' => $kinds], 'evidence' => ['error' => null, 'items' => []],
            'log' => ['declared' => true, 'path' => '/etc/passwd', 'root' => '/srv/app', 'error' => DevToolsSource::LOG_OUTSIDE, 'lines' => [], 'truncated' => false],
        ]);
        $html = self::renderer()->render($component, self::request($outside))->output;
        self::assertStringContainsString('<p class="mui-alert mui-alert--danger admin-notice">The declared log file is outside the app root and is not read: /etc/passwd. Declare a path under the root.</p>', $html);

        $blank = new StateSnapshot('d5', DevToolsComponent::NAME, '1', [
            'view' => 'overview', 'session' => null, 'available' => true, 'why' => null, 'source' => 'x',
            'sessions' => ['error' => null, 'rows' => []], 'debt' => ['error' => null, 'total' => 0, 'kinds' => $kinds], 'evidence' => ['error' => null, 'items' => []],
            'log' => ['declared' => true, 'path' => '/srv/empty.log', 'root' => '/srv', 'error' => null, 'lines' => [], 'truncated' => false],
        ]);
        $html = self::renderer()->render($component, self::request($blank))->output;
        self::assertStringContainsString('<p class="mui-alert mui-alert--info admin-notice">Log file is empty: /srv/empty.log</p>', $html);

        $noAgent = new StateSnapshot('d6', DevToolsComponent::NAME, '1', ['view' => 'overview', 'session' => null, 'available' => false, 'why' => DevToolsSource::WHY_AGENT, 'source' => '/srv/app/var/agent-sessions.jsonl', 'log' => $log]);
        $html = self::renderer()->render($component, self::request($noAgent))->output;
        self::assertStringContainsString('Install milpa/agent to read the agent ledger at /srv/app/var/agent-sessions.jsonl: sessions, their timeline, debt signals and evidence live there, and only the agent writes it.', $html, 'the not-available state names the package and where the ledger is');
        self::assertStringNotContainsString('Agent sessions', $html);
        self::assertStringContainsString('<h3 class="mui-h3">Logs</h3>', $html, 'the log block is independent of the agent');
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<button', $html);
        $noAgentNoWhere = new StateSnapshot('d6b', DevToolsComponent::NAME, '1', ['view' => 'overview', 'session' => null, 'available' => false, 'why' => DevToolsSource::WHY_AGENT, 'source' => null, 'log' => $log]);
        self::assertStringContainsString('read the agent ledger at —:', self::renderer()->render($component, self::request($noAgentNoWhere))->output, 'nowhere to read from is the none glyph, not an invented path');

        $noKernel = new StateSnapshot('d7', DevToolsComponent::NAME, '1', ['view' => 'overview', 'session' => null, 'available' => false, 'why' => DevToolsSource::WHY_KERNEL, 'source' => null, 'log' => $log]);
        $html = self::renderer()->render($component, self::request($noKernel))->output;
        self::assertStringContainsString('No event store is registered in the container and the kernel is not there either: register the kernel in public/index.php so the panel can find the app root the agent ledger (var/agent-sessions.jsonl) lives under.', $html);

        $spanish = self::renderer('es')->render($component, self::request($empty))->output;
        self::assertStringContainsString('Dev tools — los ledgers que la casa escribe <span class="mui-badge">sólo lectura</span>', $spanish);
        self::assertStringContainsString('<h3 class="mui-h3">Sesiones del agente</h3>', $spanish);
        self::assertStringContainsString('Nada registrado todavía', $spanish);
        self::assertStringContainsString('Leído de Milpa\EventStore\InMemoryEventStore', $spanish);
        self::assertStringContainsString('Ninguna señal de deuda registrada todavía', $spanish);
        self::assertStringContainsString('No hay archivo de log declarado.', $spanish);
        self::assertStringContainsString('<th scope="col">Tipo</th><th scope="col">Cuenta</th><th scope="col">Sesiones</th>', $spanish);
        $spanishNoAgent = self::renderer('es')->render($component, self::request($noAgent))->output;
        self::assertStringContainsString('Instala milpa/agent para leer el ledger del agente en /srv/app/var/agent-sessions.jsonl', $spanishNoAgent);
        self::assertStringContainsString('La raíz de la app es desconocida', self::renderer('es')->render($component, self::request($errors))->output);
    }

    public function testDevToolsDrillDownPaintsTheHeaderTheWayBackAndTheTimelineWithoutOneControl(): void
    {
        $component = self::devtools();
        $state = new StateSnapshot('d8', DevToolsComponent::NAME, '1', [
            'view' => DevToolsComponent::VIEW_SESSION, 'session' => 's_7f21', 'available' => true, 'why' => null, 'source' => '/srv/app/var/agent-sessions.jsonl', 'id' => 's_7f21', 'found' => true, 'error' => null, 'unreadable' => 1,
            'row' => ['id' => 's_7f21', 'goal' => 'greet & wave', 'mode' => 'auto', 'state' => 'done', 'endedBecause' => 'finished <cleanly>', 'tokensIn' => 18204, 'tokensOut' => 3911, 'pending' => null, 'events' => 7, 'debt' => 3, 'closure' => ['verified' => false, 'reasons' => 2], 'startedAt' => '2026-09-04T13:51:02Z', 'lastAt' => '2026-09-04T14:02:11Z'],
            'events' => [
                ['seq' => 1, 'when' => '2026-09-04T13:51:02Z', 'kind' => 'opened', 'detail' => 'greet & wave', 'flags' => ['auto']],
                ['seq' => 2, 'when' => '2026-09-04T13:51:40Z', 'kind' => 'tool', 'detail' => 'fs.write var/log/coa.log', 'flags' => ['mutating']],
                ['seq' => 3, 'when' => '2026-09-04T13:52:40Z', 'kind' => 'tool', 'detail' => 'fs.read', 'flags' => ['failed', 42]],
                ['seq' => 4, 'when' => '2026-09-04T13:54:18Z', 'kind' => 'debt', 'detail' => 'admitted_intent_skip — operation=fs.write', 'flags' => []],
                ['seq' => 5, 'when' => null, 'kind' => 'waiting', 'detail' => 'waiting on a human', 'flags' => []],
                ['seq' => 6, 'when' => '2026-09-04T14:02:11Z', 'kind' => 'closure', 'detail' => 'todo t1 done without evidence; t2 open', 'flags' => ['unverified']],
                ['seq' => 7, 'when' => '2026-09-04T14:02:12Z', 'kind' => 'mystery', 'detail' => '', 'flags' => ['verified']],
                ['seq' => 8, 'when' => '2026-09-04T14:02:13Z', 'kind' => 'operation', 'detail' => 'make:crud · rod@cli/rod@passkey · sha256:args', 'flags' => ['verified']],
                ['seq' => 9, 'when' => '2026-09-04T14:02:14Z', 'kind' => 'sequence-paused', 'detail' => 'seq-1 · 1/3', 'flags' => []],
                ['seq' => 10, 'when' => '2026-09-04T14:02:15Z', 'kind' => 'sequence-resumed', 'detail' => 'seq-1', 'flags' => []],
                'garbage',
            ],
        ]);

        $result = self::renderer()->render($component, self::request($state));
        $html = $result->output;

        self::assertStringContainsString('<h2 class="mui-h2">Session s_7f21 <span class="mui-badge" data-state="done">done</span></h2>', $html);
        self::assertStringContainsString('<p class="admin-devtools__actions"><a class="mui-btn mui-btn--ghost" href="/milpa/admin/s/devtools">Back to ledgers</a></p>', $html, 'the way back is a link, not a button');
        self::assertStringContainsString('<dl class="admin-devtools__facts"><dt>Goal</dt><dd>greet &amp; wave</dd><dt>Mode</dt><dd><code>auto</code></dd><dt>Tokens in</dt><dd>18,204</dd><dt>Tokens out</dt><dd>3,911</dd><dt>Debt signals</dt><dd>3</dd><dt>Events</dt><dd>7</dd><dt>Started</dt><dd><time datetime="2026-09-04T13:51:02Z">2026-09-04T13:51:02Z</time></dd><dt>Last event</dt><dd><time datetime="2026-09-04T14:02:11Z">2026-09-04T14:02:11Z</time></dd><dt>Ended because</dt><dd>finished &lt;cleanly&gt;</dd><dt>Closure</dt><dd><span class="mui-badge mui-badge--danger">unverified</span> 2 reason(s) in the timeline</dd></dl>', $html);
        self::assertStringContainsString('<h3 class="mui-h3">Timeline</h3>' . "\n" . '<p class="admin-devtools__hint">What SessionProjector paints of this stream, read from /srv/app/var/agent-sessions.jsonl, plus the audit facts it leaves to audit surfaces: the opening, debt signals, the closure verdict, trial runs, executed operations, paused and resumed sequences. · 1 line(s) of the ledger could not be read and were skipped</p>', $html, 'one line under the heading says where the stream comes from');
        self::assertStringContainsString('<th scope="col">Time</th><th scope="col">Event</th><th scope="col">Detail</th>', $html);
        self::assertStringContainsString('<tr><td><time datetime="2026-09-04T13:51:02Z">2026-09-04T13:51:02Z</time></td><td>session opened <span class="mui-badge">auto</span></td><td>greet &amp; wave</td></tr>', $html);
        self::assertStringContainsString('<td>tool call <span class="mui-badge mui-badge--warning">mutating</span></td><td>fs.write var/log/coa.log</td>', $html);
        self::assertStringContainsString('<td>tool call <span class="mui-badge mui-badge--danger">failed</span></td><td>fs.read</td>', $html, 'a flag that is not a string is skipped');
        self::assertStringContainsString('<td>debt signal</td><td>admitted_intent_skip — operation=fs.write</td>', $html);
        self::assertStringContainsString('<tr><td>—</td><td>decision pending</td><td>waiting on a human</td></tr>', $html);
        self::assertStringContainsString('<td>closure verdict <span class="mui-badge mui-badge--danger">unverified</span></td><td>todo t1 done without evidence; t2 open</td>', $html);
        self::assertStringContainsString('<td>mystery <span class="mui-badge mui-badge--success">verified</span></td><td></td>', $html, 'a kind the catalog lacks is painted as it came');
        self::assertStringContainsString('<td>operation executed <span class="mui-badge mui-badge--success">verified</span></td><td>make:crud · rod@cli/rod@passkey · sha256:args</td>', $html);
        self::assertStringContainsString('<td>sequence paused</td><td>seq-1 · 1/3</td>', $html);
        self::assertStringContainsString('<td>sequence resumed</td><td>seq-1</td>', $html);
        self::assertSame(10, substr_count($html, '<tr><td>'), 'garbage rows are skipped');
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertSame(['view' => 'session', 'session' => 's_7f21'], self::envelopeOf($html)->data, 'the envelope names the session and carries no event');
        self::assertSame(['view' => 'session', 'session' => 's_7f21'], $result->state?->data);

        $spanish = self::renderer('es')->render($component, self::request($state))->output;
        self::assertStringContainsString('<h2 class="mui-h2">Sesión s_7f21 <span class="mui-badge" data-state="done">terminada</span></h2>', $spanish);
        self::assertStringContainsString('href="/milpa/admin/s/devtools">Volver a los ledgers</a>', $spanish);
        self::assertStringContainsString('<dt>Tokens de entrada</dt><dd>18,204</dd>', $spanish);
        self::assertStringContainsString('<dt>Cierre</dt><dd><span class="mui-badge mui-badge--danger">sin verificar</span> 2 motivo(s) en la línea de tiempo</dd>', $spanish);
        self::assertStringContainsString('<td>llamada a herramienta <span class="mui-badge mui-badge--warning">muta</span></td>', $spanish);
        self::assertStringContainsString('<td>operación ejecutada <span class="mui-badge mui-badge--success">verificado</span></td>', $spanish);
        self::assertStringContainsString('Lo que SessionProjector pinta de este stream, leído de /srv/app/var/agent-sessions.jsonl', $spanish);

        $overridden = self::renderer()->render($component, self::request($state, 'es'))->output;
        self::assertStringContainsString('<a class="mui-btn mui-btn--ghost" href="/milpa/admin/s/devtools?lang=es">Volver a los ledgers</a>', $overridden, 'the way back keeps the language the page was opened in');

        $verified = new StateSnapshot('d9', DevToolsComponent::NAME, '1', [
            'view' => 'session', 'session' => 's_1', 'available' => true, 'why' => null, 'source' => 'x', 'id' => 's_1', 'found' => true, 'error' => null, 'unreadable' => 0,
            'row' => ['id' => 's_1', 'goal' => 'g', 'mode' => 'ask', 'state' => 'running', 'endedBecause' => null, 'tokensIn' => null, 'tokensOut' => null, 'events' => 1, 'debt' => 0, 'closure' => ['verified' => true, 'reasons' => 0], 'startedAt' => null, 'lastAt' => null],
            'events' => [],
        ]);
        $html = self::renderer()->render($component, self::request($verified))->output;
        self::assertStringContainsString('<dt>Tokens in</dt><dd>not reported</dd><dt>Tokens out</dt><dd>not reported</dd>', $html);
        self::assertStringContainsString('<dt>Started</dt><dd>—</dd><dt>Last event</dt><dd>—</dd><dt>Closure</dt><dd><span class="mui-badge mui-badge--success">verified</span></dd></dl>', $html);
        self::assertStringNotContainsString('Ended because', $html);
        self::assertStringContainsString('read from x, plus the audit facts', $html);
        self::assertStringNotContainsString('could not be read', $html, 'zero unreadable lines is not a sentence');
        self::assertStringContainsString('This session has no painted event yet.', $html);
    }

    public function testDevToolsDrillDownOfAnUnknownOrUnreadableOrUnavailableSessionIsANoticeWithTheWayBack(): void
    {
        $component = self::devtools();

        $unknown = new StateSnapshot('d10', DevToolsComponent::NAME, '1', ['view' => 'session', 'session' => 'ghost <1>', 'available' => true, 'why' => null, 'source' => '/srv/app/var/agent-sessions.jsonl', 'id' => 'ghost <1>', 'found' => false, 'error' => null, 'unreadable' => 2, 'row' => null, 'events' => []]);
        $html = self::renderer()->render($component, self::request($unknown))->output;
        self::assertStringContainsString('<h2 class="mui-h2">Session ghost &lt;1&gt;</h2>', $html);
        self::assertStringContainsString('href="/milpa/admin/s/devtools">Back to ledgers</a>', $html);
        self::assertStringContainsString('No session is named «ghost &lt;1&gt;». The ledger holds no stream under that id.</p>' . "\n" . '<p class="admin-devtools__hint">Read from /srv/app/var/agent-sessions.jsonl · 2 line(s) of the ledger could not be read and were skipped</p>', $html, 'unknown where? the hint says which ledger was searched, and that some of it could not be read');
        self::assertStringNotContainsString('Timeline', $html);

        $broken = new StateSnapshot('d11', DevToolsComponent::NAME, '1', ['view' => 'session', 'session' => 's_1', 'available' => true, 'why' => null, 'source' => 'x', 'id' => 's_1', 'found' => false, 'error' => 'Unable to lock', 'unreadable' => 0, 'row' => null, 'events' => []]);
        $html = self::renderer()->render($component, self::request($broken))->output;
        self::assertStringContainsString('<p class="mui-alert mui-alert--danger admin-notice">The session ledger could not be read: Unable to lock</p>', $html);
        self::assertStringNotContainsString('No session is named', $html);

        $noAgent = new StateSnapshot('d12', DevToolsComponent::NAME, '1', ['view' => 'session', 'session' => 's_1', 'available' => false, 'why' => DevToolsSource::WHY_AGENT, 'source' => 'x', 'id' => 's_1', 'found' => false, 'error' => null, 'unreadable' => 0, 'row' => null, 'events' => []]);
        $html = self::renderer()->render($component, self::request($noAgent))->output;
        self::assertStringContainsString('Install milpa/agent', $html);
        self::assertStringContainsString('Back to ledgers', $html);

        $spanish = self::renderer('es')->render($component, self::request($unknown))->output;
        self::assertStringContainsString('No hay ninguna sesión llamada «ghost &lt;1&gt;».', $spanish);
    }

    public function testDevToolsComponentMountsFromItsQueryPropTravelsLightAndRefusesEveryAction(): void
    {
        $component = self::devtools();

        $overview = $component->mount(['title' => 'T'], new ComponentContext(componentId: 'd13'));
        self::assertSame(DevToolsComponent::VIEW_OVERVIEW, $overview->data['view']);
        self::assertNull($overview->data['session']);
        self::assertFalse($overview->data['available'], 'no kernel, no registered store');
        self::assertSame(DevToolsSource::WHY_KERNEL, $overview->data['why']);
        self::assertSame('T', $overview->meta['title']);
        self::assertArrayHasKey('log', $overview->data);
        self::assertFalse(DevToolsComponent::travels($overview), 'a mounted state carries the projection');

        $session = $component->mount(['query' => ['session' => 's_1', 'lang' => 'es']], new ComponentContext(componentId: 'd14'));
        self::assertSame(DevToolsComponent::VIEW_SESSION, $session->data['view']);
        self::assertSame('s_1', $session->data['session']);
        self::assertSame('s_1', $session->data['id']);
        self::assertFalse($session->data['found']);

        self::assertSame(DevToolsComponent::VIEW_OVERVIEW, $component->mount(['query' => ['session' => '']], new ComponentContext(componentId: 'd15'))->data['view'], 'an empty session is no session');
        self::assertSame(DevToolsComponent::VIEW_OVERVIEW, $component->mount(['query' => 'session=s_1'], new ComponentContext(componentId: 'd16'))->data['view'], 'a query that is not an array is no query');
        self::assertSame(DevToolsComponent::VIEW_OVERVIEW, $component->mount(['query' => ['session' => ['s_1']]], new ComponentContext(componentId: 'd17'))->data['view'], 'a session that is not a string is no session');

        $envelope = DevToolsComponent::envelope($session);
        self::assertSame(['view' => 'session', 'session' => 's_1'], $envelope->data);
        self::assertSame('d14', $envelope->componentId);
        self::assertSame(['title' => ''], $envelope->meta);
        self::assertTrue(DevToolsComponent::travels($envelope));
        self::assertSame(['view' => 'overview', 'session' => null], DevToolsComponent::envelope($overview)->data);
        self::assertSame(['view' => 'overview', 'session' => null], DevToolsComponent::envelope(new StateSnapshot('x', DevToolsComponent::NAME, '1', ['view' => 'session', 'session' => '']))->data, 'a session view without a session is the overview');
        self::assertSame(['query' => ['session' => 's_1'], 'title' => ''], DevToolsComponent::propsOf($envelope));
        self::assertSame(['query' => [], 'title' => 'T'], DevToolsComponent::propsOf(DevToolsComponent::envelope($overview)));

        self::assertSame('admin-devtools', DevToolsComponent::contract()->name);
        self::assertArrayHasKey('query', DevToolsComponent::contract()->propsSchema);
        self::assertSame(['view', 'session'], array_keys(DevToolsComponent::contract()->stateSchema), 'the contract declares what travels');
        self::assertSame([], DevToolsComponent::contract()->actions, 'no action is declared');
        self::assertArrayHasKey('action', $component->handle(new InteractionRequest('d13', DevToolsComponent::NAME, 'run', $overview))->errors, 'and every one is refused');

        $rendered = self::renderer()->render($component, new RenderRequest(context: new ComponentContext(componentId: 'd18')));
        self::assertNotNull($rendered->state);
        self::assertStringContainsString('No event store is registered in the container', $rendered->output, 'mounts when no state is given');

        $carried = self::renderer()->render($component, new RenderRequest(context: new ComponentContext(componentId: 'd19'), state: $envelope));
        self::assertStringContainsString('<h2 class="mui-h2">Session s_1</h2>', $carried->output, 'a request carrying the travelling envelope is re-mounted from it');
        self::assertStringContainsString('No event store is registered in the container', $carried->output);
        self::assertSame(['view' => 'session', 'session' => 's_1'], $carried->state?->data);
    }

    private static function devtools(): DevToolsComponent
    {
        return new DevToolsComponent(new DevToolsSource(new DIContainer()));
    }

    /** The state the section's signed envelope carries, decoded with the same codec that signed it. */
    private static function envelopeOf(string $html): StateSnapshot
    {
        self::assertSame(1, preg_match('#<script type="application/milpa\+xhtml"[^>]*>(.*?)</script>#s', $html, $match), 'the envelope is on the page');

        return self::codec()->decodeState($match[1]);
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
        return new AdminHtmlRenderer(self::codec(), new Catalog($locale), AdminSettings::fromConfig(null));
    }

    /** The codec the renderer signs with — and the tests decode with. */
    private static function codec(): SignedXhtmlStateTransferCodec
    {
        return new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null);
    }

    /** A render request carrying the state — and, when given, the locale the request asked for (`?lang=`). */
    private static function request(StateSnapshot $state, ?string $locale = null): RenderRequest
    {
        return new RenderRequest(context: new ComponentContext(componentId: $state->componentId, locale: $locale), state: $state);
    }
}
