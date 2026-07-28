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

namespace Milpa\Admin\Tests\Controllers;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Milpa\Auth\ActorType;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\Exceptions\AuthContextMissingException;
use Milpa\Auth\Exceptions\ScopeDeniedException;
use Milpa\Auth\Http\RequireScopeMiddleware;
use Milpa\Auth\Http\StartSession;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\SessionRecord;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Admin\Controllers\MilpaAdminController;
use Milpa\Admin\Controllers\SettingsController;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Runtime\Http\MiddlewarePipeline;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fake provider for the controller-level fixtures below: the Hub
 * ({@see MilpaAdminController::index()}) resolves {@see PluginsManagerInterface} from the
 * container and builds its own {@see \Milpa\Admin\Section\AdminSectionDiscovery}
 * BEFORE the auth gate even runs, so every controller-level test in this file — including the
 * anonymous/403 ones — needs this registered, not just the ones that reach the redirect.
 */
final class GateTestFakeAdminSectionProvider implements AdminSectionProvider
{
    public function adminSections(): array
    {
        return [new AdminSection('settings', 'Settings', '/milpa/admin/settings', 10)];
    }
}

final class GateTestFakePluginsManager implements PluginsManagerInterface
{
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
        return ['fake' => new GateTestFakeAdminSectionProvider()];
    }

    public function getPlugin(string $name): ?PluginInterface
    {
        return null;
    }

    public function isEnabled(string $name): bool
    {
        return true;
    }
}

/**
 * Task 4 gate — the `/milpa/admin` pipeline `[StartSession, RequireScope('milpa.admin')]` plus the
 * host projection {@see MilpaAdminController} does over it (401 → redirect, 403 → HTML). Since Task
 * 4, `/milpa/admin` itself is the Admin Hub: it never renders a 200 — with scope it 302s to the
 * default section's href. Proven at two layers, mirroring
 * {@see \Tests\Unit\Plugins\NeighbourPlugin\Controllers\PermissionDemoControllerTest}:
 *
 *   - pipeline-level: the exact chain the controller composes, driven directly with a spy tip
 *     handler that returns a bare 200, so "the tip did NOT run" is a real assertion — path-agnostic,
 *     untouched by the Hub's own tip shape;
 *   - controller-level: the real {@see MilpaAdminController} (and, for the safe-GET-destination
 *     rule, {@see SettingsController}), invoked through a real {@see DIContainer} with an
 *     {@see InMemorySessionStore}, proving the actual 401/403/302 HTTP projection (redirect
 *     Location, "sin acceso" HTML, the Hub's redirect to its default section).
 */
final class MilpaAdminGateTest extends TestCase
{
    private const COOKIE = 'milpa_session';

    protected function setUp(): void
    {
        if (!defined('rootPath')) {
            define('rootPath', dirname(__DIR__, 5));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
    }

    // ---- fixtures ---------------------------------------------------------------------------

    private function session(string $id, array $scopes): SessionRecord
    {
        $now = new \DateTimeImmutable();

        return new SessionRecord(
            id: $id,
            actorId: 'actor-' . $id,
            actorType: ActorType::User,
            createdAt: $now,
            expiresAt: $now->modify('+1 hour'),
            scopes: $scopes,
        );
    }

    private function spyHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public bool $ran = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->ran = true;

                return (new HttpFactory())->createResponse(200);
            }
        };
    }

    // ---- pipeline-level: proves StartSession + RequireScopeMiddleware wiring, spy tip ---------

    public function testPipelineAnonymousIs401AndTheSpyDidNotRun(): void
    {
        $store = new InMemorySessionStore();
        $spy = $this->spyHandler();
        $pipeline = new MiddlewarePipeline(
            [new StartSession($store), new RequireScopeMiddleware('milpa.admin')],
            $spy,
        );

        try {
            $pipeline->handle(new ServerRequest('GET', '/milpa/admin'));
            $this->fail('expected AuthContextMissingException');
        } catch (AuthContextMissingException $e) {
            self::assertSame(401, $e->statusCode());
            self::assertSame('MILPA_UNAUTHENTICATED', $e->errorCode());
        }

        self::assertFalse($spy->ran, 'the shell must not run when the gate denies the request');
    }

    public function testPipelineAuthenticatedWithoutScopeIs403AndTheSpyDidNotRun(): void
    {
        $store = new InMemorySessionStore();
        $store->write($this->session('sess-403', ['agency.sales']));
        $spy = $this->spyHandler();
        $pipeline = new MiddlewarePipeline(
            [new StartSession($store), new RequireScopeMiddleware('milpa.admin')],
            $spy,
        );

        $request = (new ServerRequest('GET', '/milpa/admin'))->withCookieParams([self::COOKIE => 'sess-403']);

        try {
            $pipeline->handle($request);
            $this->fail('expected ScopeDeniedException');
        } catch (ScopeDeniedException $e) {
            self::assertSame(403, $e->statusCode());
            self::assertSame('MILPA_SCOPE_DENIED', $e->errorCode());
        }

        self::assertFalse($spy->ran, 'the shell must not run when the gate denies the request');
    }

    public function testPipelineAuthenticatedWithScopeReaches200AndTheSpyRan(): void
    {
        $store = new InMemorySessionStore();
        $store->write($this->session('sess-200', ['milpa.admin']));
        $spy = $this->spyHandler();
        $pipeline = new MiddlewarePipeline(
            [new StartSession($store), new RequireScopeMiddleware('milpa.admin')],
            $spy,
        );

        $request = (new ServerRequest('GET', '/milpa/admin'))->withCookieParams([self::COOKIE => 'sess-200']);
        $response = $pipeline->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($spy->ran, 'the shell must run once the gate admits the request');
    }

    // ---- controller-level: the real MilpaAdminController, real DIContainer, no full-app boot ---

    private function container(SessionStore $store): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(LoggerInterface::class, new NullLogger());
        $container->registerService(SessionStore::class, $store);
        $container->registerService(PluginsManagerInterface::class, new GateTestFakePluginsManager());

        // Ola 5b: HttpResponderInterface, misma construcción que los extintos WebManager/CliManager
        // registraban tras loadPlugins() (Ola 7c) — BaseController (el paquete) lo resuelve en el constructor.
        $container->registerService(
            \Milpa\Http\Symfony\HttpResponderInterface::class,
            new \Milpa\Admin\Tests\Fixtures\EchoResponder(),
        );

        return $container;
    }

    public function testControllerAnonymousRedirectsToLogin(): void
    {
        $controller = new MilpaAdminController($this->container(new InMemorySessionStore()));

        $response = $controller->index(Request::create('/milpa/admin', 'GET'));

        self::assertSame(302, $response->getStatusCode());

        $location = $response->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringStartsWith('/agency/login?next=', $location);

        // `next` travels percent-encoded on the query string (LocalRedirectTarget::resolve()'s
        // output is rawurlencode()'d before being appended) — decode it back to compare.
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('/milpa/admin', $query['next'] ?? null);
    }

    /**
     * Task 4 — the safe-GET-destination rule: with no explicit `returnTo`, a GET's `next` is its
     * OWN path (derived from `getPathInfo()`), never the Hub's. The 401 fires inside
     * `RequireScopeMiddleware`, before {@see SettingsController::show()}'s own tip ever runs, so
     * this is really the same gate as the Hub's — just anchored at a different route.
     */
    public function testControllerAnonymousToSettingsRedirectsToLoginWithSettingsNext(): void
    {
        $controller = new SettingsController($this->container(new InMemorySessionStore()));

        $response = $controller->show(Request::create('/milpa/admin/settings', 'GET'));

        self::assertSame(302, $response->getStatusCode());

        $location = $response->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringStartsWith('/agency/login?next=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('/milpa/admin/settings', $query['next'] ?? null);
    }

    public function testControllerAuthenticatedWithoutScopeReturns403SinAccesoPage(): void
    {
        $store = new InMemorySessionStore();
        $store->write($this->session('sess-403', ['agency.sales']));
        $controller = new MilpaAdminController($this->container($store));

        $request = Request::create('/milpa/admin', 'GET', [], [self::COOKIE => 'sess-403']);
        $response = $controller->index($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('sin acceso', strtolower((string) $response->getContent()));
    }

    /**
     * Task 4 — the Hub never renders: with scope, it discovers the sections, picks the default
     * (the first by order — {@see \Milpa\Admin\Section\AdminSectionDiscovery::defaultSection()})
     * and 302s there. The "with-scope reaches the surface" invariant this test used to prove (a real
     * 200 + shell markup) now lives on the section route itself — see
     * {@see \Tests\Integration\Plugins\MilpaAdminPlugin\MilpaAdminSettingsGetTest} for that fuller
     * assertion set, which this test would otherwise duplicate.
     */
    public function testControllerAuthenticatedWithScopeRedirectsToDefaultSection(): void
    {
        $store = new InMemorySessionStore();
        $store->write($this->session('sess-200', ['milpa.admin']));
        $controller = new MilpaAdminController($this->container($store));

        $request = Request::create('/milpa/admin', 'GET', [], [self::COOKIE => 'sess-200']);
        $response = $controller->index($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/milpa/admin/settings', $response->headers->get('Location'));
    }
}
