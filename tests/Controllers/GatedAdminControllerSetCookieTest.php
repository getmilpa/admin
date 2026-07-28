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
use Milpa\Http\Symfony\HttpResponse;
use Milpa\Auth\ActorType;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\SessionRecord;
use Milpa\Container\DIContainer;
use Milpa\Admin\Controllers\GatedAdminController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 5 — proves the NEW plumbing {@see GatedAdminController::runGated()} adds over the P5.2 gate:
 * a `Set-Cookie` header emitted by the tip handler survives the PSR-7 → `HttpResponse` projection.
 * Today's `ShellRenderHandler` sets no cookie, so this is purely additive — nothing in the P5.2
 * {@see \Tests\Unit\Plugins\MilpaAdminPlugin\Controllers\MilpaAdminGateTest} suite observes it — but
 * Task 6/7 (the settings CSRF cookie) depends on this seam existing and working.
 */
final class GatedAdminControllerSetCookieTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('rootPath')) {
            define('rootPath', dirname(__DIR__, 5));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
    }

    private function container(SessionStore $store): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(LoggerInterface::class, new NullLogger());
        $container->registerService(SessionStore::class, $store);

        // Ola 5b: HttpResponderInterface, misma construcción que los extintos WebManager/CliManager
        // registraban tras loadPlugins() (Ola 7c) — BaseController (el paquete) lo resuelve en el constructor.
        $container->registerService(
            \Milpa\Http\Symfony\HttpResponderInterface::class,
            new \Milpa\Admin\Tests\Fixtures\EchoResponder(),
        );

        return $container;
    }

    public function testSetCookieFromTheHandlerSurvivesProjection(): void
    {
        $now = new \DateTimeImmutable();
        $store = new InMemorySessionStore();
        $store->write(new SessionRecord(
            id: 'sess-cookie',
            actorId: 'actor-sess-cookie',
            actorType: ActorType::User,
            createdAt: $now,
            expiresAt: $now->modify('+1 hour'),
            scopes: ['milpa.admin'],
        ));

        $controller = new class ($this->container($store)) extends GatedAdminController {
            public function callGated(Request $request, RequestHandlerInterface $tip): HttpResponse
            {
                return $this->runGated($request, $tip);
            }
        };

        $tip = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new HttpFactory())->createResponse(200)
                    ->withAddedHeader('Set-Cookie', 'milpa_csrf=abc123; Path=/; HttpOnly');
            }
        };

        $request = Request::create('/milpa/admin', 'GET', [], ['milpa_session' => 'sess-cookie']);
        $response = $controller->callGated($request, $tip);

        self::assertSame(200, $response->getStatusCode());

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('milpa_csrf', $cookies[0]->getName());
        self::assertSame('abc123', $cookies[0]->getValue());
        self::assertTrue($cookies[0]->isHttpOnly());
    }

    public function testNoSetCookieWhenTheHandlerEmitsNone(): void
    {
        $now = new \DateTimeImmutable();
        $store = new InMemorySessionStore();
        $store->write(new SessionRecord(
            id: 'sess-no-cookie',
            actorId: 'actor-sess-no-cookie',
            actorType: ActorType::User,
            createdAt: $now,
            expiresAt: $now->modify('+1 hour'),
            scopes: ['milpa.admin'],
        ));

        $controller = new class ($this->container($store)) extends GatedAdminController {
            public function callGated(Request $request, RequestHandlerInterface $tip): HttpResponse
            {
                return $this->runGated($request, $tip);
            }
        };

        $tip = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new HttpFactory())->createResponse(200);
            }
        };

        $request = Request::create('/milpa/admin', 'GET', [], ['milpa_session' => 'sess-no-cookie']);
        $response = $controller->callGated($request, $tip);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $response->headers->getCookies());
    }

    /**
     * Task 5 (T4 reviewer follow-up) — the safe-GET-destination rule's OTHER branch: a POST with NO
     * explicit `$returnTo` never reuses `getPathInfo()` as `next` (that's ONLY for a GET, per
     * `runGated()`'s own comment: `$request->isMethod('GET') ? $request->getPathInfo() : null`) —
     * it falls all the way through to the Hub default (`LocalRedirectTarget::resolve(null, '/milpa/admin')`).
     * Every REAL caller of a POST (`SettingsController::save()`) always declares its own `$returnTo`,
     * so this branch has no controller-level caller to exercise it — this is the direct unit test
     * `runGated()` itself needed, proven the same way as the sibling tests in this file: a bare
     * anonymous subclass exposing `callGated()`, no controller involved.
     */
    public function testPostWithoutReturnToFallsBackToTheHubNext(): void
    {
        $controller = new class ($this->container(new InMemorySessionStore())) extends GatedAdminController {
            public function callGated(Request $request, RequestHandlerInterface $tip): HttpResponse
            {
                return $this->runGated($request, $tip);
            }
        };

        $tip = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new HttpFactory())->createResponse(200);
            }
        };

        // Sin cookie de sesión (anónimo) y SIN returnTo — el caso que ningún caller real ejercita.
        $request = Request::create('/milpa/admin/settings', 'POST');
        $response = $controller->callGated($request, $tip);

        self::assertSame(302, $response->getStatusCode());

        $location = $response->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringStartsWith('/agency/login?next=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('/milpa/admin', $query['next'] ?? null);
    }
}
