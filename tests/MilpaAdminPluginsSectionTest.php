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
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\Console\Contracts\RouteTableSource;
use Milpa\Admin\Tests\Fixtures\FixedRouteTable;
use Milpa\Admin\Controllers\PluginsController;
use Milpa\Admin\Http\ShellRenderHandler;
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
 * La sección "Plugins" del Milpa Admin, de punta a punta.
 *
 * El GET pinta la tabla dentro del chrome del admin detrás del gate `milpa.admin`; el POST conmuta
 * un plugin y hace PRG. Lo que se prueba acá y no en los unitarios es lo que sólo existe cuando
 * las piezas están juntas: que la ruta esté gateada, que el POST exija CSRF, y sobre todo que
 * conmutar desde la web mueva EL MISMO almacén que lee la terminal — que es la única razón por la
 * que estas operaciones se definieron una sola vez.
 *
 * Cada test corre en su propio proceso por la misma razón que {@see MilpaAdminSystemGetTest}:
 * `rootPath` es una constante global y otro test puede haberla definido apuntando a otro lado.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MilpaAdminPluginsSectionTest extends TestCase
{
    private const COOKIE = 'milpa_session';

    private InMemoryPluginRegistry $registry;

    protected function setUp(): void
    {
        if (!defined('rootPath')) {
            define('rootPath', dirname(__DIR__, 4));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        $this->registry = new InMemoryPluginRegistry();
        $this->registry->register($this->record('OAuthPlugin', enabled: true));
        $this->registry->register($this->record('MailPlugin', enabled: false));
    }

    private function record(string $name, bool $enabled): PluginRecord
    {
        return new PluginRecord(
            name: $name,
            version: '1.0.0',
            author: 'Acme',
            site: 'https://teamx.agency',
            type: 'Service',
            installed: true,
            enabled: $enabled,
        );
    }

    /** @param list<string> $scopes */
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
        $container->registerService(PluginRegistryInterface::class, $this->registry);

        $plugins = new PluginsSectionFakePluginsManager([
            'milpa-admin' => new AdminPlugin($container),
            'neighbour' => new NeighbourPlugin($container),
        ]);
        $container->registerService(PluginsManagerInterface::class, $plugins);

        $container->registerService(RouteTableSource::class, FixedRouteTable::withOneRoute());

        $container->registerService(
            \Milpa\Http\Symfony\HttpResponderInterface::class,
            new \Milpa\Admin\Tests\Fixtures\EchoResponder(),
        );

        return $container;
    }

    private function loggedIn(string $id): DIContainer
    {
        $store = new InMemorySessionStore();
        $store->write($this->session($id, ['milpa.admin']));

        return $this->container($store);
    }

    // ---- GET ---------------------------------------------------------------------------

    public function test_el_get_pinta_los_plugins_del_host_dentro_del_chrome_del_admin(): void
    {
        $controller = new PluginsController($this->loggedIn('admin-plugins-get'));
        $request = Request::create('/milpa/admin/plugins', 'GET', [], [self::COOKIE => 'admin-plugins-get']);

        $response = $controller->show($request);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<td>OAuthPlugin</td>', $html);
        self::assertStringContainsString('<td>MailPlugin</td>', $html);
        // El chrome del admin: la sección aparece en la navegación junto a las otras.
        self::assertStringContainsString('/milpa/admin/settings', $html);
        self::assertStringContainsString('/milpa/admin/system', $html);
    }

    public function test_el_get_emite_la_cookie_csrf_que_el_post_va_a_exigir(): void
    {
        // Sin esto el POST nunca podría pasar: el guard compara el hidden del form contra la
        // cookie, y quien pinta el form es esta misma respuesta.
        $controller = new PluginsController($this->loggedIn('admin-plugins-cookie'));
        $request = Request::create('/milpa/admin/plugins', 'GET', [], [self::COOKIE => 'admin-plugins-cookie']);

        $response = $controller->show($request);

        $setCookie = implode("\n", $response->headers->all()['set-cookie'] ?? []);
        self::assertStringContainsString(ShellRenderHandler::CSRF_COOKIE, $setCookie);
    }

    public function test_sin_sesion_el_get_manda_al_login(): void
    {
        $controller = new PluginsController($this->container(new InMemorySessionStore()));

        $response = $controller->show(Request::create('/milpa/admin/plugins', 'GET'));

        self::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith('/agency/login?next=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('/milpa/admin/plugins', $query['next'] ?? null);
    }

    // ---- POST --------------------------------------------------------------------------

    public function test_apagar_un_plugin_mueve_el_almacen_y_hace_prg(): void
    {
        // El PRG importa: sin él, un refresh después de apagar vuelve a enviar el POST.
        $controller = new PluginsController($this->loggedIn('admin-plugins-off'));
        $request = Request::create(
            '/milpa/admin/plugins',
            'POST',
            ['name' => 'OAuthPlugin', 'action' => 'disable', 'csrf' => 'tok'],
            [self::COOKIE => 'admin-plugins-off', ShellRenderHandler::CSRF_COOKIE => 'tok'],
        );

        $response = $controller->toggle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/milpa/admin/plugins', $response->headers->get('Location'));
        self::assertFalse($this->registry->find('OAuthPlugin')?->enabled);
    }

    public function test_prender_un_plugin_apagado_tambien_mueve_el_almacen(): void
    {
        $controller = new PluginsController($this->loggedIn('admin-plugins-on'));
        $request = Request::create(
            '/milpa/admin/plugins',
            'POST',
            ['name' => 'MailPlugin', 'action' => 'enable', 'csrf' => 'tok'],
            [self::COOKIE => 'admin-plugins-on', ShellRenderHandler::CSRF_COOKIE => 'tok'],
        );

        $response = $controller->toggle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertTrue($this->registry->find('MailPlugin')?->enabled);
    }

    public function test_un_post_sin_csrf_valido_no_conmuta_nada(): void
    {
        // Conmutar cambia lo que este host ejecuta en la siguiente petición: no puede dispararse
        // desde otro sitio.
        $controller = new PluginsController($this->loggedIn('admin-plugins-csrf'));
        $request = Request::create(
            '/milpa/admin/plugins',
            'POST',
            ['name' => 'OAuthPlugin', 'action' => 'disable', 'csrf' => 'equivocado'],
            [self::COOKIE => 'admin-plugins-csrf', ShellRenderHandler::CSRF_COOKIE => 'tok'],
        );

        $response = $controller->toggle($request);

        self::assertNotSame(303, $response->getStatusCode());
        self::assertTrue($this->registry->find('OAuthPlugin')?->enabled, 'El plugin sigue activo.');
    }

    public function test_conmutar_algo_que_no_existe_repinta_la_pagina_con_el_motivo(): void
    {
        $controller = new PluginsController($this->loggedIn('admin-plugins-ghost'));
        $request = Request::create(
            '/milpa/admin/plugins',
            'POST',
            ['name' => 'Fantasma', 'action' => 'enable', 'csrf' => 'tok'],
            [self::COOKIE => 'admin-plugins-ghost', ShellRenderHandler::CSRF_COOKIE => 'tok'],
        );

        $response = $controller->toggle($request);
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode(), 'Redisplay, no redirect: el motivo tiene que verse.');
        self::assertStringContainsString('Fantasma', $html);
        self::assertStringContainsString('<td>OAuthPlugin</td>', $html, 'Y la tabla sigue ahí.');
    }

    public function test_un_post_incompleto_se_rechaza_sin_tocar_nada(): void
    {
        $controller = new PluginsController($this->loggedIn('admin-plugins-partial'));
        $request = Request::create(
            '/milpa/admin/plugins',
            'POST',
            ['action' => 'disable', 'csrf' => 'tok'],
            [self::COOKIE => 'admin-plugins-partial', ShellRenderHandler::CSRF_COOKIE => 'tok'],
        );

        $response = $controller->toggle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Petición incompleta', (string) $response->getContent());
        self::assertTrue($this->registry->find('OAuthPlugin')?->enabled);
    }

    public function test_una_accion_inventada_no_conmuta_nada(): void
    {
        // `action` sólo admite enable/disable. Cualquier otra cosa es una petición que no se
        // interpreta a medias.
        $controller = new PluginsController($this->loggedIn('admin-plugins-bogus'));
        $request = Request::create(
            '/milpa/admin/plugins',
            'POST',
            ['name' => 'OAuthPlugin', 'action' => 'remove', 'csrf' => 'tok'],
            [self::COOKIE => 'admin-plugins-bogus', ShellRenderHandler::CSRF_COOKIE => 'tok'],
        );

        $response = $controller->toggle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->registry->find('OAuthPlugin')?->enabled);
    }

    public function test_sin_sesion_el_post_tampoco_conmuta(): void
    {
        $controller = new PluginsController($this->container(new InMemorySessionStore()));
        $request = Request::create(
            '/milpa/admin/plugins',
            'POST',
            ['name' => 'OAuthPlugin', 'action' => 'disable', 'csrf' => 'tok'],
            [ShellRenderHandler::CSRF_COOKIE => 'tok'],
        );

        $response = $controller->toggle($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertTrue($this->registry->find('OAuthPlugin')?->enabled);
    }
}

/**
 * @see \Tests\Integration\Plugins\MilpaAdminPlugin\SystemGetFakePluginsManager el mismo doble; vive
 *      aparte porque cada archivo de test corre en su propio proceso aislado.
 */
final class PluginsSectionFakePluginsManager implements PluginsManagerInterface
{
    /** @param array<string, \Milpa\Interfaces\Plugin\PluginInterface> $plugins */
    public function __construct(private readonly array $plugins)
    {
    }

    public function addPluginPath(string $path): void
    {
    }

    public function loadPlugins(): void
    {
    }

    /** @return array<string, mixed> */
    public function getToolProviderPromptSections(): array
    {
        return [];
    }

    /** @return array<string, \Milpa\Interfaces\Plugin\PluginInterface> */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function getPlugin(string $name): ?\Milpa\Interfaces\Plugin\PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    public function isEnabled(string $name): bool
    {
        return isset($this->plugins[$name]);
    }
}
