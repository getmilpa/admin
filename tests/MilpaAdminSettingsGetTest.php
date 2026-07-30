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

namespace Milpa\Admin\Tests;

use Milpa\Auth\ActorType;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\SessionRecord;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Admin\Controllers\SettingsController;
use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Settings\SettingsEntity;
use Milpa\Admin\Settings\SettingsRepository;
use Milpa\Admin\Tests\Fixtures\NeighbourPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fake `PluginsManagerInterface` mínimo para este archivo: la Tarea 5 hace que
 * {@see SettingsController::show()} resuelva el discovery (ADR#12, mismo idioma que el Hub) —
 * envuelve los dos providers REALES (mismo par que
 * {@see \Tests\Unit\Plugins\MilpaAdminPlugin\Section\SectionProvidersTest}) para que el
 * sidebar renderizado traiga las DOS secciones reales, no un fixture inventado.
 */
final class SettingsGetFakePluginsManager implements PluginsManagerInterface
{
    /** @param array<string, PluginInterface> $plugins */
    public function __construct(private readonly array $plugins)
    {
    }

    public function addPluginPath(string $path): void
    {
    }

    public function loadPlugins(): void
    {
    }

    public function getToolProviderPromptSections(): array
    {
        return [];
    }

    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function getPlugin(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    public function isEnabled(string $name): bool
    {
        return true;
    }
}

/**
 * Tarea 6 — {@see \Milpa\Admin\Http\ShellRenderHandler} detrás del gate ya NO
 * renderiza el placeholder de la Tarea 5 ("Settings llega en P5.3.") sino el form REAL de settings,
 * sin JS: un `<form method=post action=/milpa/admin/settings>` con el hidden `csrf`, los campos
 * estilizados de {@see \Milpa\Live\Rendering\SchemaFormHtmlRenderer} y un submit nativo. El sidebar
 * `brand` refleja el `siteName` guardado, y la respuesta trae la cookie CSRF (`milpa_admin_csrf`,
 * el mismo nombre que la Tarea 7's `CsrfGuard('milpa_admin_csrf', 'csrf')` verifica).
 *
 * `MILPA_ADMIN_SETTINGS_PATH` apunta a un archivo temporal por test (mismo idioma que
 * `MILPA_DESIGN_PATH` en {@see MilpaAdminShellTest}) para no leer/escribir la store real de
 * producción del checkout.
 *
 * Tarea 5 (P5.5) — el sidebar YA NO es el único item hardcoded: se descubre (ADR#12) vía
 * {@see \Milpa\Console\Section\SectionDiscovery} sobre los plugins booteados,
 * así que el shell renderizado trae las DOS secciones reales — Settings (order 10) y Arquitectura
 * (order 20) — en ese orden, con `aria-current="page"` en la sección activa.
 */
final class MilpaAdminSettingsGetTest extends TestCase
{
    private const COOKIE = 'milpa_session';

    private string|false $previousSettingsPath = false;
    private ?string $settingsFile = null;

    protected function setUp(): void
    {
        if (!defined('rootPath')) {
            define('rootPath', dirname(__DIR__, 4));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        $this->previousSettingsPath = getenv('MILPA_ADMIN_SETTINGS_PATH');
        $this->settingsFile = sys_get_temp_dir() . '/milpa-admin-settings-get-' . bin2hex(random_bytes(4)) . '.json';
        putenv('MILPA_ADMIN_SETTINGS_PATH=' . $this->settingsFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousSettingsPath === false) {
            putenv('MILPA_ADMIN_SETTINGS_PATH');
        } else {
            putenv('MILPA_ADMIN_SETTINGS_PATH=' . $this->previousSettingsPath);
        }

        if ($this->settingsFile !== null && is_file($this->settingsFile)) {
            unlink($this->settingsFile);
        }
    }

    private function session(string $sessionId, array $scopes): SessionRecord
    {
        $now = new \DateTimeImmutable();

        return new SessionRecord(
            id: $sessionId,
            actorId: 'actor-' . $sessionId,
            actorType: ActorType::User,
            createdAt: $now,
            expiresAt: $now->modify('+1 hour'),
            scopes: $scopes,
        );
    }

    /**
     * El container compartido de este archivo: registra los DOS providers reales de
     * `ui.admin.section` (ADR#12) — mismo idioma que el Hub — para que `SettingsController::show()`
     * pinte el sidebar completo, no un fixture inventado.
     */
    private function container(SessionStore $store): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(LoggerInterface::class, new NullLogger());
        $container->registerService(SessionStore::class, $store);
        $container->registerService(PluginsManagerInterface::class, new SettingsGetFakePluginsManager([
            'milpa-admin' => new AdminPlugin($container),
            'neighbour' => new NeighbourPlugin($container),
        ]));

        // Ola 5b: HttpResponderInterface, misma construcción que los extintos WebManager/CliManager
        // registraban tras loadPlugins() (Ola 7c) — BaseController (el paquete) lo resuelve en el constructor.
        $container->registerService(
            \Milpa\Http\Symfony\HttpResponderInterface::class,
            new \Milpa\Admin\Tests\Fixtures\EchoResponder(),
        );

        return $container;
    }

    public function test_get_renders_the_settings_form_no_js_with_csrf_and_brand(): void
    {
        (new SettingsRepository((string) $this->settingsFile))->save(new SettingsEntity('Acme Corp', false, 'light'));

        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-get', ['milpa.admin']));

        $controller = new SettingsController($this->container($store));
        $request = Request::create('/milpa/admin/settings', 'GET', [], [self::COOKIE => 'admin-settings-get']);
        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        self::assertStringContainsString('<form method="post" action="/milpa/admin/settings"', $html);
        self::assertStringContainsString('name="csrf"', $html);
        self::assertStringContainsString('name="siteName"', $html);
        // El submit lleva la clase de botón del design-system (mui-btn), no un botón nativo suelto
        // entre campos estilizados (B5).
        self::assertStringContainsString('<button type="submit" class="mui-btn mui-btn--primary mui-btn--sm">', $html);

        // El sidebar brand refleja el siteName guardado, no el default 'Milpa Admin'.
        self::assertStringContainsString('Acme Corp', $html);

        // La cookie CSRF existe y sus atributos de seguridad quedan CONGELADOS por este test — el
        // contrato del que depende la Tarea 7 (mismo par nombre/campo, HttpOnly, SameSite=Lax,
        // Path acotado a /milpa/admin, con expiración): un refactor futuro no puede degradarlos en
        // silencio.
        $csrfCookie = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'milpa_admin_csrf') {
                $csrfCookie = $cookie;
                break;
            }
        }

        self::assertNotNull($csrfCookie, 'la respuesta debe traer la cookie milpa_admin_csrf');
        self::assertTrue($csrfCookie->isHttpOnly(), 'la cookie CSRF debe ser HttpOnly');
        self::assertSame('lax', $csrfCookie->getSameSite());
        self::assertSame('/milpa/admin', $csrfCookie->getPath());
        self::assertGreaterThan(0, $csrfCookie->getMaxAge(), 'la cookie CSRF debe tener una expiración acotada');

        // Y su valor es EXACTAMENTE el mismo que el hidden `csrf` del form — nunca derivado de
        // milpa_session, mismo secreto en ambos lados.
        self::assertSame(1, preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $matches));
        self::assertSame($matches[1], (string) $csrfCookie->getValue());
    }

    public function test_get_reflects_default_brand_when_no_settings_saved_yet(): void
    {
        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-default', ['milpa.admin']));

        $controller = new SettingsController($this->container($store));
        $request = Request::create('/milpa/admin/settings', 'GET', [], [self::COOKIE => 'admin-settings-default']);
        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        self::assertStringContainsString('Milpa Admin', $html);
        self::assertStringContainsString('name="siteName"', $html);
    }

    /**
     * P5.3b — el shell autenticado ahora COMPONE `dashboard-topbar` (antes omitido a propósito en
     * P5.2/Tarea 5, ver el docblock de {@see \Milpa\Admin\Http\ShellRenderHandler})
     * y la página emite los dos `<script defer>` del runtime de cliente: `/milpa-live.js` PRIMERO
     * (registra los factories de componente) y `/vendor/alpine.min.js` DESPUÉS (boot de Alpine).
     * El toggle de navegación del topbar (`mui-topbar__nav-toggle`) existe en el markup pero queda
     * JS-gated por la regla `html:not(.milpa-js)` que {@see \Milpa\Admin\View\AdminPage}
     * emite (Tarea 3) — no es control muerto, ADR#5 saldado. Su `aria-controls` apunta al `id` real
     * del sidebar renderizado.
     */
    public function testRenderedShellComposesTheTopbarAndEmitsTheClientRuntimeScripts(): void
    {
        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-topbar', ['milpa.admin']));

        $controller = new SettingsController($this->container($store));
        $request = Request::create('/milpa/admin/settings', 'GET', [], [self::COOKIE => 'admin-settings-topbar']);
        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        // runtime scripts: milpa-live.js ANTES que alpine (registra factories antes del boot).
        self::assertStringContainsString('<script src="/milpa-live.js" defer></script>', $html);
        self::assertStringContainsString('<script src="/vendor/alpine.min.js" defer></script>', $html);
        self::assertLessThan(
            strpos($html, '/vendor/alpine.min.js'),
            strpos($html, '/milpa-live.js'),
            'milpa-live.js debe cargar antes que Alpine',
        );

        // topbar compuesto (antes omitido en P5.2): su toggle JS-only presente.
        self::assertStringContainsString('mui-topbar', $html);
        self::assertStringContainsString('mui-topbar__nav-toggle', $html);

        // aria-controls apunta al id real del sidebar renderizado.
        self::assertStringContainsString('aria-controls="milpa-admin-sidebar"', $html);
        self::assertStringContainsString('id="milpa-admin-sidebar"', $html);
    }

    /**
     * Tarea 5 (P5.5) — el sidebar se DESCUBRE (ADR#12): ya no es el único item hardcoded de la
     * Tarea 4, sino las DOS secciones reales que aportan
     * {@see \Milpa\Admin\MilpaAdminPlugin::sections()} (`settings`, order 10)
     * y {@see \Milpa\Plugins\NeighbourPlugin\NeighbourPlugin::sections()} (`architecture`, order 20),
     * en ESE orden — el discovery ordena, este handler nunca re-ordena. `aria-current="page"` marca
     * la sección activa por `key` estricto (el componente lo emite, no este test lo inventa).
     */
    public function testRenderedSidebarShowsBothDiscoveredSectionsInOrderWithSettingsActive(): void
    {
        $store = new InMemorySessionStore();
        $store->write($this->session('admin-settings-sidebar', ['milpa.admin']));

        $controller = new SettingsController($this->container($store));
        $request = Request::create('/milpa/admin/settings', 'GET', [], [self::COOKIE => 'admin-settings-sidebar']);
        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        self::assertStringContainsString('Settings', $html);
        self::assertStringContainsString('Arquitectura', $html);
        self::assertStringContainsString('/vecino/arquitectura', $html);
        self::assertLessThan(
            strpos($html, 'Arquitectura'),
            strpos($html, 'Settings'),
            'Settings (order 10) debe aparecer antes que Arquitectura (order 20)',
        );

        // La sección activa (Settings) trae aria-current="page" — el componente lo emite cuando
        // `key === active`.
        self::assertStringContainsString('aria-current="page"', $html);

        // aria-controls/id del sidebar intactos (P5.3b).
        self::assertStringContainsString('aria-controls="milpa-admin-sidebar"', $html);
        self::assertStringContainsString('id="milpa-admin-sidebar"', $html);
    }
}
