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
use Milpa\Console\Contracts\RouteTableSource;
use Milpa\Admin\Tests\Fixtures\FixedRouteTable;
use Milpa\Admin\Controllers\SystemController;
use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Tests\Fixtures\NeighbourPlugin;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * La sección "Sistema" (P5.7): un GET read-only, gateado por `milpa.admin`, que pinta las rutas
 * registradas en una tabla server-rendered dentro del chrome del admin. Sin sesión → 302 login.
 * Corre contra una tabla de rutas fija que entra por {@see \Milpa\Console\Contracts\RouteTableSource}:
 * el panel no sabe cómo su host arma las rutas, y probarlo contra el ensamblador de una app
 * concreta ataba este test a que esa app siguiera teniendo los controllers de siempre.
 *
 * Cada test corre en su propio proceso: `rootPath` es una constante global y, en una corrida de
 * la suite completa en un solo proceso, un test anterior (p. ej. tests/Unit/PluginsTest.php) puede
 * definirla apuntando a otro directorio antes de que este test arranque — el guard de `setUp` la
 * respeta si ya está definida, así que sin aislamiento heredaría el valor equivocado (mismo patrón
 * que {@see \Tests\Unit\App\Persistence\EntityManagerFactoryCacheTest}).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MilpaAdminSystemGetTest extends TestCase
{
    private const COOKIE = 'milpa_session';

    protected function setUp(): void
    {
        if (!defined('rootPath')) {
            define('rootPath', dirname(__DIR__, 4));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
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

    private function container(SessionStore $store): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(LoggerInterface::class, new NullLogger());
        $container->registerService(EventDispatcherInterface::class, new EventDispatcher());
        $container->registerService(SessionStore::class, $store);
        $plugins = new SystemGetFakePluginsManager([
            'milpa-admin' => new AdminPlugin($container),
            'neighbour' => new NeighbourPlugin($container),
        ]);
        $container->registerService(PluginsManagerInterface::class, $plugins);

        // La tabla de rutas entra por el puerto, no por el ensamblador de una app concreta.
        $container->registerService(RouteTableSource::class, FixedRouteTable::withOneRoute());

        // Ola 5b: HttpResponderInterface, misma construcción que los extintos WebManager/CliManager
        // registraban tras loadPlugins() (Ola 7c) — SystemController lo resuelve vía BaseController (el paquete).
        $container->registerService(
            \Milpa\Http\Symfony\HttpResponderInterface::class,
            new \Milpa\Admin\Tests\Fixtures\EchoResponder(),
        );

        return $container;
    }

    public function test_get_renders_the_routes_table_inside_the_admin_chrome(): void
    {
        $store = new InMemorySessionStore();
        $store->write($this->session('admin-system-get', ['milpa.admin']));

        $controller = new SystemController($this->container($store));
        $request = Request::create('/milpa/admin/system', 'GET', [], [self::COOKIE => 'admin-system-get']);
        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        self::assertStringContainsString('<table class="mui-table mui-table--compact">', $html);
        self::assertStringContainsString('<th>Ruta</th>', $html);
        // La ruta `/` de HomeController (src/app/Controllers) se carga vía loadFromControllers.
        self::assertStringContainsString('<td>/</td>', $html);
        // Es read-only: sin form ni CSRF.
        self::assertStringNotContainsString('<form method="post"', $html);
        self::assertStringNotContainsString('name="csrf"', $html);
    }

    public function test_without_session_redirects_to_login(): void
    {
        $controller = new SystemController($this->container(new InMemorySessionStore()));
        $request = Request::create('/milpa/admin/system', 'GET');
        $response = $controller->show($request);

        self::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith('/agency/login?next=', $location);

        // `next` viaja percent-encoded en el query string (la SALIDA de `LocalRedirectTarget::resolve()`
        // se le aplica `rawurlencode()` antes de concatenarse en `GatedAdminController::runGated()`) —
        // mismo idioma de decode que {@see \Tests\Unit\Plugins\MilpaAdminPlugin\Controllers\MilpaAdminGateTest}.
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('/milpa/admin/system', $query['next'] ?? null);
    }
}

/**
 * Fake mínimo de PluginsManagerInterface para el discovery de secciones (mismo idioma que el harness
 * de settings, {@see \Tests\Integration\Plugins\MilpaAdminPlugin\SettingsGetFakePluginsManager}):
 * expone las instancias de plugin que el test registró e implementa el set COMPLETO de la interfaz
 * (no solo `getPlugins()`).
 */
final class SystemGetFakePluginsManager implements PluginsManagerInterface
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
