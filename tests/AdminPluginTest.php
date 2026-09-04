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

use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Controllers\AdminController;
use Milpa\Admin\Controllers\AssetsController;
use Milpa\Admin\Http\LoopbackOnlyMiddleware;
use Milpa\Admin\Tests\Fixtures\DuplicatePlugin;
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
use Milpa\Container\DIContainer;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The panel booted by the real kernel next to a plugin it never heard of — the refuting slice, in-process.
 */
final class AdminPluginTest extends TestCase
{
    public function testRoutesCarryTheDeclaredMiddlewareAndTheMountPoint(): void
    {
        $container = new DIContainer();
        $plugin = new AdminPlugin($container);
        $plugin->boot();

        $routes = $plugin->routes();
        self::assertSame(['/milpa/admin', '/milpa/admin/s/{id}', '/milpa/admin/assets/{file}'], array_map(static fn (Route $r): string => $r->path, $routes));
        foreach ($routes as $route) {
            self::assertSame([LoopbackOnlyMiddleware::class], $route->middleware);
            self::assertTrue($route->isBound());
        }
        self::assertTrue($container->has(AdminController::class));
        self::assertTrue($container->has(AssetsController::class));
        self::assertTrue($container->has(LoopbackOnlyMiddleware::class));
        self::assertSame(['plugins', 'routes'], array_map(static fn ($s): string => $s->id, $plugin->adminSections()));
        self::assertSame('/milpa/admin', $plugin->settings()->route);

        $plugin->install();
        $plugin->uninstall();
        $plugin->enable();
        $plugin->disable();
        self::assertSame('/milpa/admin', (new AdminPlugin(new DIContainer()))->settings()->route, 'defaults before boot');
        self::assertCount(3, (new AdminPlugin(new DIContainer()))->routes(), 'routes exist before boot too');
    }

    public function testAForeignPluginGetsItsSectionsWithoutThePanelKnowingIt(): void
    {
        [$container] = self::boot([AdminPlugin::class, HolaPlugin::class]);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $index = $controller->index(new ServerRequest('GET', '/milpa/admin'));
        self::assertSame(200, $index->getStatusCode());
        $html = (string) $index->getBody();
        self::assertStringContainsString('href="/milpa/admin/s/hola"', $html, 'the foreign section is in the sidebar');
        self::assertStringContainsString('href="/milpa/admin/s/plugins"', $html);
        self::assertStringContainsString('href="/milpa/admin/s/routes"', $html);
        self::assertStringContainsString('id="milpa-admin-section-hola"', $html, 'order 5 puts the foreign section first');

        $echo = $controller->section(self::sectionRequest('echo'));
        self::assertSame(200, $echo->getStatusCode());
        self::assertStringContainsString(EchoRenderer::MARKER, (string) $echo->getBody(), 'a foreign custom component renders');

        $routes = $controller->section(self::sectionRequest('routes'));
        $body = (string) $routes->getBody();
        self::assertSame(200, $routes->getStatusCode());
        self::assertStringContainsString('<code>/hola</code>', $body, 'the foreign route is in the table');
        self::assertStringContainsString('HolaPlugin', $body);
        self::assertStringContainsString('LoopbackOnlyMiddleware', $body, 'its per-route middleware too');
        self::assertStringNotContainsString('kernel is not in the container', $body);

        $plugins = $controller->section(self::sectionRequest('plugins'));
        self::assertStringContainsString('<td>Hola</td>', (string) $plugins->getBody(), 'the foreign plugin is in the plugins table');

        $missing = $controller->section(self::sectionRequest('ghost'));
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringContainsString('No section is named «ghost»', (string) $missing->getBody());
        self::assertSame(404, $controller->section(new ServerRequest('GET', '/x'))->getStatusCode(), 'no route result → empty id → nothing is named that');
    }

    public function testADuplicateSectionIdIsA500ThatNamesBothPlugins(): void
    {
        [$container] = self::boot([AdminPlugin::class, DuplicatePlugin::class]);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $response = $controller->index(new ServerRequest('GET', '/milpa/admin'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('declared twice', (string) $response->getBody());
        self::assertStringContainsString('DuplicatePlugin', (string) $response->getBody());
    }

    public function testWithoutAKernelThePanelStillServesItsOwnSections(): void
    {
        $container = new DIContainer();
        $plugin = new AdminPlugin($container);
        $plugin->boot();
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $response = $controller->index(new ServerRequest('GET', '/milpa/admin'));

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('href="/milpa/admin/s/plugins"', $html);
        self::assertStringContainsString('kernel is not in the container', $controller->section(self::sectionRequest('routes'))->getBody()->__toString());
    }

    public function testDeclaredConfigMovesTheMountPointAndTheLanguage(): void
    {
        [$container] = self::boot([AdminPlugin::class], ['admin' => ['route' => '/panel', 'locale' => 'es', 'middleware' => []]]);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $html = (string) $controller->index(new ServerRequest('GET', '/panel'))->getBody();

        self::assertStringContainsString('<html lang="es"', $html);
        self::assertStringContainsString('href="/panel/s/plugins"', $html);
        self::assertStringContainsString('Rutas', $html);
        $kernel = $container->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        $admin = null;
        foreach ($kernel->plugins() as $plugin) {
            if ($plugin instanceof AdminPlugin) {
                $admin = $plugin;
            }
        }
        self::assertNotNull($admin);
        self::assertSame([], $admin->routes()[0]->middleware, 'the app opened the panel on purpose');
    }

    /**
     * @param list<class-string>   $plugins
     * @param array<string, mixed> $config
     *
     * @return array{0: DIContainer, 1: Kernel}
     */
    private static function boot(array $plugins, array $config = []): array
    {
        $container = new DIContainer();
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => $plugins, 'config' => $config, 'container' => $container]);
        $container->registerService(Kernel::class, $kernel);

        return [$container, $kernel];
    }

    private static function sectionRequest(string $id): ServerRequest
    {
        $route = new Route(path: '/milpa/admin/s/{id}', handler: HandlerReference::method(AdminController::class, 'section'));

        return (new ServerRequest('GET', '/milpa/admin/s/' . $id))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route, ['id' => $id]));
    }
}
