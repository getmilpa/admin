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
use Milpa\Admin\Controllers\StackController;
use Milpa\Admin\Http\LoopbackOnlyMiddleware;
use Milpa\Admin\Tests\Fixtures\AllowAllMiddleware;
use Milpa\Admin\Tests\Fixtures\DuplicatePlugin;
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
use Milpa\Admin\Tests\Fixtures\HubPlugin;
use Milpa\Admin\Tests\Fixtures\RivalHubPlugin;
use Milpa\Agent\SessionStore;
use Milpa\Container\DIContainer;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

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
        self::assertSame(
            ['/milpa/admin', '/milpa/admin/s/{id}', '/milpa/admin/assets/{file}', '/milpa/admin/stack/compose.yml'],
            array_map(static fn (Route $r): string => $r->path, $routes),
        );
        foreach ($routes as $route) {
            self::assertSame([LoopbackOnlyMiddleware::class], $route->middleware);
            self::assertTrue($route->isBound());
        }
        self::assertSame('milpa_admin_stack_compose', $routes[3]->name);
        self::assertTrue($container->has(AdminController::class));
        self::assertTrue($container->has(AssetsController::class));
        self::assertTrue($container->has(StackController::class));
        self::assertTrue($container->has(LoopbackOnlyMiddleware::class));
        self::assertSame(['plugins', 'routes', 'settings', 'stack', 'devtools'], array_map(static fn ($s): string => $s->id, $plugin->adminSections()));
        self::assertSame([10, 20, 25, 30, 40], array_map(static fn ($s): int => $s->order, $plugin->adminSections()));
        self::assertSame('nav.devtools', $plugin->adminSections()[4]->title);
        self::assertSame('admin', $plugin->adminSections()[4]->group);
        self::assertSame('/milpa/admin', $plugin->settings()->route);

        $plugin->install();
        $plugin->uninstall();
        $plugin->enable();
        $plugin->disable();
        self::assertSame('/milpa/admin', (new AdminPlugin(new DIContainer()))->settings()->route, 'defaults before boot');
        self::assertCount(4, (new AdminPlugin(new DIContainer()))->routes(), 'routes exist before boot too');
    }

    public function testAPluginThatDeclaresAServiceShowsUpInTheStackSectionAndInTheComposeFile(): void
    {
        [$container] = self::boot([AdminPlugin::class, HubPlugin::class], ['hub' => ['public_url' => 'http://localhost:3000', 'key' => 'config-secret']]);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $index = (string) $controller->index(new ServerRequest('GET', '/milpa/admin'))->getBody();
        self::assertStringContainsString('href="/milpa/admin/s/stack"', $index, 'the Stack section is in the sidebar');

        $stack = $controller->section(self::sectionRequest('stack'));
        self::assertSame(200, $stack->getStatusCode());
        $html = (string) $stack->getBody();
        self::assertStringContainsString('<article class="mui-card admin-stack__service">', $html);
        self::assertStringContainsString('<code>example/hub:1</code>', $html);
        self::assertStringContainsString('<code>3000:80</code>', $html);
        self::assertStringContainsString('Declared by HubPlugin', $html);
        self::assertStringContainsString('<code>http://localhost:3000</code>', $html, 'the config value the plugin pointed at');
        self::assertStringContainsString('●●●', $html, 'the secret is masked');
        self::assertStringNotContainsString('config-secret', $html);
        self::assertMatchesRegularExpression('~<span class="mui-badge[^"]*">(up|down)</span>~', $html, 'the real probe on 127.0.0.1:3000 said one or the other');
        self::assertStringContainsString('probed on 127.0.0.1:3000', $html, 'the real probe reports the host it tried');
        self::assertStringNotContainsString('kernel is not in the container', $html);

        $compose = $container->get(StackController::class);
        \assert($compose instanceof StackController);
        $response = $compose->compose(new ServerRequest('GET', '/milpa/admin/stack/compose.yml'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/yaml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="compose.yml"', $response->getHeaderLine('Content-Disposition'));
        $yaml = (string) $response->getBody();
        self::assertStringStartsWith("services:\n  hub:\n", $yaml);
        self::assertStringContainsString("HUB_PUBLIC_URL: 'http://localhost:3000'", $yaml);
        self::assertStringContainsString('HUB_JWT_KEY: ${HUB_JWT_KEY}', $yaml);
        self::assertStringNotContainsString('config-secret', $yaml);
    }

    public function testTwoPluginsDeclaringTheSameServiceMakeTheComposeRouteA409AndTheSectionSaysWhy(): void
    {
        [$container] = self::boot([AdminPlugin::class, HubPlugin::class, RivalHubPlugin::class]);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $html = (string) $controller->section(self::sectionRequest('stack'))->getBody();
        self::assertSame(2, substr_count($html, '<span class="mui-badge mui-badge--danger">conflict</span>'), 'both rows are kept and both are the conflict');
        self::assertStringContainsString('«hub» is also declared by RivalHubPlugin', $html);
        self::assertStringContainsString('«hub» is also declared by HubPlugin', $html);
        self::assertStringContainsString('<code>example/rival-hub:2</code>', $html, 'nothing was dropped');
        self::assertStringNotContainsString('mui-badge--success', $html, 'a colliding service has no reachability state, only the conflict');

        $compose = $container->get(StackController::class);
        \assert($compose instanceof StackController);
        $response = $compose->compose(new ServerRequest('GET', '/milpa/admin/stack/compose.yml'));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Content-Disposition'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('Service «hub» is declared by HubPlugin and RivalHubPlugin — rename one or disable a plugin; no compose.yml is served while ids collide.', $body);
        self::assertStringNotContainsString('services:', $body);
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
        $body = (string) $missing->getBody();
        self::assertStringContainsString('No section is named «ghost». The sections present are: ', $body);
        foreach (['hola', 'echo', 'plugins', 'routes', 'settings', 'stack', 'devtools'] as $present) {
            self::assertMatchesRegularExpression('~The sections present are: [^.]*\b' . $present . '\b~', $body, 'the 404 lists ' . $present);
        }
        self::assertSame(404, $controller->section(new ServerRequest('GET', '/x'))->getStatusCode(), 'no route result → empty id → nothing is named that');
        self::assertStringContainsString('Las secciones presentes son: ', (string) $controller->section(self::sectionRequest('ghost', 'lang=es'))->getBody());
    }

    public function testAMisdeclaredGateFallsBackToLoopbackOnlyAndSettingsSaysSo(): void
    {
        [$container] = self::boot([AdminPlugin::class], ['admin' => ['middleware' => [AllowAllMiddleware::class, 'Acme\\Nope']]]);
        $admin = self::admin($container);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        foreach ($admin->routes() as $route) {
            self::assertSame([LoopbackOnlyMiddleware::class], $route->middleware, $route->path . ' carries the strict gate, and only it');
        }
        self::assertSame('fallback', $admin->settings()->gateKind());
        self::assertSame(['plugins', 'routes', 'settings', 'stack', 'devtools'], array_map(static fn ($s): string => $s->id, $admin->adminSections()));

        $index = (string) $controller->index(new ServerRequest('GET', '/milpa/admin'))->getBody();
        self::assertStringContainsString('href="/milpa/admin/s/settings"', $index, 'the Settings section is in the sidebar');
        self::assertStringContainsString('data-gate="fallback">gate: fallback</span>', $index, 'the chip says so on every page');
        self::assertStringContainsString('admin-chip--gate mui-badge--warning', $index);

        $settings = $controller->section(self::sectionRequest('settings'));
        self::assertSame(200, $settings->getStatusCode(), 'the panel keeps serving');
        $html = (string) $settings->getBody();
        self::assertStringContainsString('admin-section--admin-settings', $html);
        self::assertStringContainsString('admin.middleware names «Acme\Nope (class does not exist)». Every entry must name a PSR-15 middleware class, so the panel fell back to the loopback-only gate', $html);
        self::assertStringContainsString('<code>AllowAllMiddleware, Acme\Nope</code> <span class="mui-badge mui-badge--danger">unresolved</span>', $html);
        self::assertStringContainsString('<title>Settings · Milpa Admin</title>', $html);
    }

    /**
     * The shapes the first form of the rule let through — each one either opened the panel to the LAN
     * or killed every panel route with a 500. Now each one is the strict gate, served.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function misdeclaredGatesEndToEnd(): iterable
    {
        yield 'a non-string entry' => [[42]];
        yield 'a class that exists but is not a middleware' => [[\stdClass::class]];
        yield 'a string, not a list' => ['Acme\\Nope'];
        yield 'an associative map' => [[AllowAllMiddleware::class => true]];
        yield 'half a gate' => [[AllowAllMiddleware::class, 42]];
    }

    #[DataProvider('misdeclaredGatesEndToEnd')]
    public function testThroughTheKernelAMisdeclaredGateIs403FromTheLanAnd200FallbackFromLoopback(mixed $declared): void
    {
        $lan = self::dispatch(['admin' => ['middleware' => $declared]], '10.0.0.5');
        self::assertSame(403, $lan->getStatusCode(), 'the LAN is refused: the strict gate is what runs');
        self::assertStringNotContainsString('data-gate=', (string) $lan->getBody(), 'nothing of the panel is served past the gate');

        $loopback = self::dispatch(['admin' => ['middleware' => $declared]], '127.0.0.1');
        self::assertSame(200, $loopback->getStatusCode(), 'loopback is served: the panel did not die with the cause hidden');
        self::assertStringContainsString('data-gate="fallback">gate: fallback</span>', (string) $loopback->getBody(), 'and the topbar says so');
    }

    public function testThroughTheKernelALiterallyEmptyListIsTheOneDeclarationThatOpensThePanel(): void
    {
        $open = self::dispatch(['admin' => ['middleware' => []]], '10.0.0.5');
        self::assertSame(200, $open->getStatusCode(), 'the control: [] opens, on purpose');
        self::assertStringContainsString('data-gate="open">gate: open</span>', (string) $open->getBody());

        self::assertSame(403, self::dispatch([], '10.0.0.5')->getStatusCode(), 'no admin key: loopback-only');
        self::assertSame(200, self::dispatch([], '127.0.0.1')->getStatusCode());

        $custom = self::dispatch(['admin' => ['middleware' => [AllowAllMiddleware::class]]], '10.0.0.5');
        self::assertSame(200, $custom->getStatusCode(), 'a real PSR-15 gate is carried as declared');
        self::assertStringContainsString('data-gate="custom">gate: custom</span>', (string) $custom->getBody());
    }

    public function testARejectedLocaleRunsTheDefaultEverywhereAndSettingsSaysWhatWasDeclared(): void
    {
        [$container] = self::boot([AdminPlugin::class], ['admin' => ['locale' => 'fr']]);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $html = (string) $controller->section(self::sectionRequest('settings'))->getBody();

        self::assertStringContainsString('<html lang="en"', $html, 'the document speaks the locale in effect');
        self::assertStringContainsString('data-locale="en">en</span>', $html, 'so does the chip');
        self::assertStringContainsString('<code>locale</code></td><td><code>en</code> <span class="admin-settings__declared">(declared: fr)</span></td><td><span class="mui-badge mui-badge--danger">rejected</span>', $html, 'and the row does not pretend the app declared nothing');
        self::assertStringContainsString('<option value="server">server (en)</option>', $html);
        self::assertStringContainsString('data-gate="loopback">gate: loopback</span>', $html, 'the gate was not declared: untouched');
        self::assertStringNotContainsString('mui-alert--danger', $html, 'a rejected locale is not a gate problem');
    }

    public function testWithOnlyALiveSecretThePanelDoesNotClaimToRunEntirelyOnDefaults(): void
    {
        [$container] = self::boot([AdminPlugin::class], ['live' => ['secret' => 'live-hunter2-0123456789']]);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $html = (string) $controller->section(self::sectionRequest('settings'))->getBody();

        self::assertStringContainsString('config/app.php has no admin key: the panel runs on defaults, except the secret it takes from live.secret.', $html);
        self::assertStringNotContainsString('Running entirely on defaults', $html);
        self::assertStringContainsString('●●●</span>declared (live.secret)</td><td><span class="mui-badge mui-badge--accent">config</span>', $html);
        self::assertSame(4, substr_count($html, '<span class="mui-badge">default</span>'));
        self::assertStringNotContainsString('hunter2', $html);
    }

    public function testACustomGateThatExistsIsCarriedAsDeclaredAndAnOpenOneStaysOpen(): void
    {
        [$container] = self::boot([AdminPlugin::class], ['admin' => ['middleware' => [AllowAllMiddleware::class]]]);
        $admin = self::admin($container);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        self::assertSame([AllowAllMiddleware::class], $admin->routes()[0]->middleware);
        self::assertSame('custom', $admin->settings()->gateKind());
        $html = (string) $controller->section(self::sectionRequest('settings'))->getBody();
        self::assertStringContainsString('data-gate="custom">gate: custom</span>', $html);
        self::assertStringContainsString('<td><code>middleware</code></td><td><code>AllowAllMiddleware</code></td><td><span class="mui-badge mui-badge--accent">config</span></td>', $html);
        self::assertStringNotContainsString('mui-badge--danger', $html);
        self::assertStringNotContainsString('has no admin key', $html);

        [$container] = self::boot([AdminPlugin::class], ['admin' => ['middleware' => []]]);
        self::assertSame([], self::admin($container)->routes()[0]->middleware, 'the app opened the panel on purpose');
        self::assertSame('open', self::admin($container)->settings()->gateKind());
    }

    public function testAFreshAppSeesFiveDefaultsAndTheSnippetToPaste(): void
    {
        [$container] = self::boot([AdminPlugin::class]);
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $html = (string) $controller->section(self::sectionRequest('settings'))->getBody();

        self::assertStringContainsString('Running entirely on defaults: config/app.php has no admin key', $html, 'every one of the five is a default, so the wording is earned');
        self::assertStringContainsString('<pre class="admin-snippet"><code>', $html);
        self::assertStringContainsString('LoopbackOnlyMiddleware::class]],</code></pre>', $html);
        self::assertSame(5, substr_count($html, '<span class="mui-badge">default</span>'));
        self::assertStringContainsString('●●●</span>derived</td>', $html);
        self::assertStringContainsString('data-gate="loopback">gate: loopback</span>', $html);
        self::assertStringContainsString('<script data-admin-prefs="early">', $html);
        self::assertStringContainsString('<script data-admin-prefs="delegated">', $html);
    }

    public function testTheLanguageQueryOverridesTheLocaleForThatRequestOnlyWhenTheCatalogCarriesIt(): void
    {
        $container = new DIContainer();
        $plugin = new AdminPlugin($container);
        $plugin->boot();
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        $es = (string) $controller->index((new ServerRequest('GET', '/milpa/admin?lang=es'))->withQueryParams(['lang' => 'es']))->getBody();
        self::assertStringContainsString('<html lang="es"', $es);
        self::assertStringContainsString('Rutas', $es, 'the sidebar speaks Spanish');
        self::assertStringContainsString('Plugins que esta app arranca', $es, 'so does the section body');
        self::assertStringContainsString('data-locale="es">es</span>', $es, 'and the chip');
        self::assertStringContainsString('<title>Plugins · Milpa Admin</title>', $es);

        $fromUri = (string) $controller->index(new ServerRequest('GET', '/milpa/admin?lang=es'))->getBody();
        self::assertStringContainsString('Rutas', $fromUri, 'read from the URI when the host did not parse the query');

        $unknown = (string) $controller->index(new ServerRequest('GET', '/milpa/admin?lang=xx'))->getBody();
        self::assertStringContainsString('<html lang="en"', $unknown, 'a locale the catalog lacks is ignored');
        self::assertStringContainsString('Routes', $unknown);
        self::assertStringNotContainsString('Rutas', $unknown);

        $plain = (string) $controller->index(new ServerRequest('GET', '/milpa/admin'))->getBody();
        self::assertStringContainsString('<html lang="en"', $plain, 'nothing stuck: the override was for one request');
        self::assertStringNotContainsString('Rutas', $plain);

        $section = (string) $controller->section(self::sectionRequest('settings', 'lang=es'))->getBody();
        self::assertStringContainsString('Preferencias del panel', $section);
        self::assertStringContainsString('<title>Ajustes · Milpa Admin</title>', $section);
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
        $stack = $controller->section(self::sectionRequest('stack'))->getBody()->__toString();
        self::assertStringContainsString('kernel is not in the container', $stack);
        self::assertStringContainsString('No plugin declared a service', $stack);
        $devtools = $controller->section(self::sectionRequest('devtools'))->getBody()->__toString();
        self::assertStringContainsString('No event store is registered in the container and the kernel is not there either: register the kernel in public/index.php so the panel can find the app root the agent ledger (var/agent-sessions.jsonl) lives under.', $devtools);
        self::assertStringContainsString('No log file is declared.', $devtools, 'the log block still reads: nothing declared');
        self::assertStringNotContainsString('Agent sessions', $devtools, 'no ledger to list');
    }

    public function testDevToolsReadsTheLedgerTheAgentWroteUnderTheAppRootAndOpensOneSessionFromTheQuery(): void
    {
        $root = sys_get_temp_dir() . '/milpa-admin-plugin-devtools-' . bin2hex(random_bytes(4));
        mkdir($root . '/var', 0775, true);
        $stream = SessionStore::PREFIX . 's-run';
        $rows = [
            ['stream_id' => $stream, 'type' => 'session.started', 'payload' => ['goal' => 'greet the house with a goal long enough to be cut short in the table of sessions', 'mode' => 'auto', 'parentId' => null], 'seq' => 1, 'recorded_at' => '2026-09-04T10:00:00.000000Z'],
            ['stream_id' => $stream, 'type' => 'session.turn', 'payload' => ['role' => 'user', 'content' => 'hola'], 'seq' => 2, 'recorded_at' => '2026-09-04T10:00:01.000000Z'],
            ['stream_id' => $stream, 'type' => 'session.model_returned', 'payload' => ['model' => 'qwen', 'usage' => ['prompt_tokens' => 18204, 'completion_tokens' => 3911, 'total_tokens' => 22115]], 'seq' => 3, 'recorded_at' => '2026-09-04T10:00:02.000000Z'],
            ['stream_id' => $stream, 'type' => 'session.tool_called', 'payload' => ['tool' => 'hola:greet', 'arguments' => [], 'result' => 'Hola', 'ok' => true, 'mutating' => false], 'seq' => 4, 'recorded_at' => '2026-09-04T10:00:03.000000Z'],
            ['stream_id' => $stream, 'type' => 'session.debt_signaled', 'payload' => ['signal' => 'admitted_intent_skip', 'context' => ['operation' => 'hola:greet']], 'seq' => 5, 'recorded_at' => '2026-09-04T10:00:04.000000Z'],
            ['stream_id' => SessionStore::PREFIX . 's-wait', 'type' => 'session.started', 'payload' => ['goal' => 'second', 'mode' => 'ask', 'parentId' => null], 'seq' => 6, 'recorded_at' => '2026-09-04T10:01:00.000000Z'],
            ['stream_id' => SessionStore::PREFIX . 's-wait', 'type' => 'session.question_asked', 'payload' => ['id' => 'q1', 'question' => 'Which target?', 'options' => [], 'why' => null, 'expiresAt' => null, 'reason' => 'target_not_named'], 'seq' => 7, 'recorded_at' => '2026-09-04T10:01:01.000000Z'],
        ];
        file_put_contents($root . '/var/agent-sessions.jsonl', implode("\n", array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows)) . "\n{a line the agent never finished\n");
        file_put_contents($root . '/var/app.log', "10:00 info operation completed\n10:01 warn probe timeout\n");

        try {
            $container = new DIContainer();
            $kernel = Kernel::boot(['root' => $root, 'plugins' => [AdminPlugin::class], 'config' => ['admin' => ['log' => 'var/app.log']], 'container' => $container]);
            $container->registerService(Kernel::class, $kernel);
            $controller = $container->get(AdminController::class);
            \assert($controller instanceof AdminController);

            $index = (string) $controller->index(new ServerRequest('GET', '/milpa/admin'))->getBody();
            self::assertStringContainsString('href="/milpa/admin/s/devtools"', $index, 'the Dev tools section is in the sidebar');

            $overview = $controller->section(self::sectionRequest('devtools'));
            self::assertSame(200, $overview->getStatusCode());
            $html = self::devtoolsSection((string) $overview->getBody());
            self::assertStringContainsString('<title>Dev tools · Milpa Admin</title>', (string) $overview->getBody());
            self::assertStringContainsString('<a href="/milpa/admin/s/devtools?session=s-run"><code>s-run</code></a>', $html, 'a row links into its timeline');
            self::assertStringContainsString('<a href="/milpa/admin/s/devtools?session=s-wait"><code>s-wait</code></a>', $html);
            self::assertStringContainsString('data-state="running">running</span>', $html);
            self::assertStringContainsString('data-state="waiting">waiting</span>', $html);
            self::assertStringContainsString('<code>18,204 / 3,911</code>', $html, 'the provider\'s own numbers');
            self::assertStringContainsString('<code>not reported</code>', $html, 'a session whose calls never carried usage — absent, not zero');
            self::assertStringContainsString('<span class="mui-badge mui-badge--accent">target_not_named</span> <small>Which target?</small>', $html, 'what the waiting session waits on, the question inline');
            self::assertStringContainsString('<td>' . substr('greet the house with a goal long enough to be cut short in the table of sessions', 0, 71) . '…</td>', $html, 'the goal is cut short in the table');
            self::assertStringContainsString('<p class="admin-devtools__hint">Read from ' . $root . '/var/agent-sessions.jsonl · 1 line(s) of the ledger could not be read and were skipped</p>', $html, 'the page says which ledger it read, and that one line of it is not an event');
            self::assertStringContainsString('<td><code>admitted_intent_skip</code><br><small>Ceremony was skipped because', $html);
            self::assertStringContainsString('never as authority.</small></td><td>1</td><td><a href="/milpa/admin/s/devtools?session=s-run"><code>s-run</code></a></td>', $html);
            self::assertStringContainsString('<td><code>framework_gap</code><br><small>The model declared a stalled leg', $html, 'the four real kinds, even at zero, each glossed');
            self::assertStringContainsString('could not progress on its own.</small></td><td>0</td><td>—</td>', $html);
            self::assertStringContainsString('No evidence recorded yet', $html);
            self::assertStringContainsString('last 2 lines of ' . $root . '/var/app.log', $html);
            self::assertStringContainsString("<pre class=\"admin-log\"><code>10:00 info operation completed\n10:01 warn probe timeout</code></pre>", $html);
            self::assertStringNotContainsString('<form', $html, 'nothing here acts');
            self::assertStringNotContainsString('<button', $html);

            $drill = $controller->section(self::sectionRequest('devtools', 'session=s-run'));
            self::assertSame(200, $drill->getStatusCode());
            $html = self::devtoolsSection((string) $drill->getBody());
            self::assertStringContainsString('<h2 class="mui-h2">Session s-run <span class="mui-badge mui-badge--success" data-state="running">running</span></h2>', $html, 'the query opened the timeline inside the section');
            self::assertStringContainsString('<a class="mui-btn mui-btn--ghost" href="/milpa/admin/s/devtools">Back to ledgers</a>', $html);
            self::assertStringContainsString('<dt>Tokens in</dt><dd>18,204</dd><dt>Tokens out</dt><dd>3,911</dd><dt>Debt signals</dt><dd>1</dd><dt>Events</dt><dd>5</dd>', $html);
            self::assertStringContainsString('<h3 class="mui-h3">Timeline</h3>' . "\n" . '<p class="admin-devtools__hint">What SessionProjector paints of this stream, read from ' . $root . '/var/agent-sessions.jsonl, plus the audit facts', $html);
            self::assertStringContainsString('<td><time datetime="2026-09-04T10:00:00Z">2026-09-04T10:00:00Z</time></td><td>session opened <span class="mui-badge">auto</span></td>', $html);
            self::assertStringContainsString('<td>tool call</td><td>hola:greet</td>', $html);
            self::assertStringContainsString('<td>debt signal</td><td>admitted_intent_skip — operation=hola:greet</td>', $html);
            self::assertStringNotContainsString('<form', $html);
            self::assertStringNotContainsString('<button', $html);

            $parsed = $controller->section(self::sectionRequest('devtools')->withQueryParams(['session' => 's-wait']));
            self::assertStringContainsString('Session s-wait <span class="mui-badge mui-badge--accent" data-state="waiting">waiting</span>', (string) $parsed->getBody(), 'the parsed params carry the query too');
            self::assertStringContainsString('<td>decision pending</td><td>Which target?</td>', (string) $parsed->getBody());

            $ghost = $controller->section(self::sectionRequest('devtools', 'session=ghost'));
            self::assertSame(200, $ghost->getStatusCode(), 'an unknown session is a notice inside the section, not a 404 of the panel');
            self::assertStringContainsString('No session is named «ghost».', (string) $ghost->getBody());

            $spanish = (string) $controller->section(self::sectionRequest('devtools', 'lang=es&session=s-run'))->getBody();
            self::assertStringContainsString('Sesión s-run', $spanish);
            self::assertStringContainsString('<a class="mui-btn mui-btn--ghost" href="/milpa/admin/s/devtools?lang=es">Volver a los ledgers</a>', $spanish, 'the way back keeps the language the request asked for');
            self::assertStringContainsString('<td>sesión abierta <span class="mui-badge">auto</span></td>', $spanish);
            $spanishOverview = (string) $controller->section(self::sectionRequest('devtools', 'lang=es'))->getBody();
            self::assertStringContainsString('<a href="/milpa/admin/s/devtools?session=s-run&amp;lang=es"><code>s-run</code></a>', $spanishOverview, 'and so does every drill-down link');
            self::assertStringContainsString('Leído de ' . $root . '/var/agent-sessions.jsonl', $spanishOverview);
        } finally {
            foreach (['/var/agent-sessions.jsonl', '/var/app.log'] as $file) {
                @unlink($root . $file);
            }
            @rmdir($root . '/var');
            @rmdir($root);
        }
    }

    /** The Dev tools section's own HTML — what the doctrine control greps for `<form` and `<button`. */
    private static function devtoolsSection(string $page): string
    {
        $start = strpos($page, '<section class="admin-section admin-section--admin-devtools"');
        $end = strpos($page, '</section>', $start === false ? 0 : $start);
        self::assertNotFalse($start, 'the section is on the page');
        self::assertNotFalse($end);

        return substr($page, $start, $end - $start);
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
        self::assertSame([], self::admin($container)->routes()[0]->middleware, 'the app opened the panel on purpose');
    }

    private static function admin(DIContainer $container): AdminPlugin
    {
        $kernel = $container->get(Kernel::class);
        \assert($kernel instanceof Kernel);
        foreach ($kernel->plugins() as $plugin) {
            if ($plugin instanceof AdminPlugin) {
                return $plugin;
            }
        }
        self::fail('the kernel did not boot the admin plugin');
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

    private static function sectionRequest(string $id, string $query = ''): ServerRequest
    {
        $route = new Route(path: '/milpa/admin/s/{id}', handler: HandlerReference::method(AdminController::class, 'section'));

        return (new ServerRequest('GET', '/milpa/admin/s/' . $id . ($query === '' ? '' : '?' . $query)))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route, ['id' => $id]));
    }

    /**
     * One GET of the panel's index through the real kernel and request handler — routing, the
     * effective middleware, the controller — as a request from the given address would see it.
     *
     * @param array<string, mixed> $config
     */
    private static function dispatch(array $config, string $remoteAddress): ResponseInterface
    {
        [, $kernel] = self::boot([AdminPlugin::class], $config);
        $handler = new RequestHandler($kernel, new Psr17Factory());

        return $handler->handle(new ServerRequest('GET', '/milpa/admin', [], null, '1.1', ['REMOTE_ADDR' => $remoteAddress]));
    }
}
