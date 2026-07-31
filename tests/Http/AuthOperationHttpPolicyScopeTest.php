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

namespace Milpa\Admin\Tests\Http;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Milpa\Admin\Http\AuthOperationHttpPolicy;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Contracts\AuthContextFactory;
use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Exceptions\AuthMiddlewareNotInstalledException;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Command\Operation;
use Milpa\Console\Http\HttpProjector;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use PHPUnit\Framework\TestCase;

/**
 * La compuerta de scopes de la superficie HTTP: un átomo con scopes deja de correr desnudo.
 *
 * Vino de `milpa/skeleton` con la política que mide (P14.3). Lo que cambió es de quién es cada mitad:
 * el proyector es de `milpa/console` y no sabe nada de identidad; la política es de aquí, que ya
 * requiere `milpa/auth`. Los cuatro desenlaces son los mismos —200, 403, 401, y el 500 de host mal
 * configurado que es la distinción de Rod— y una operación SIN scopes sigue sin tocar nada de esto.
 */
final class AuthOperationHttpPolicyScopeTest extends TestCase
{
    /** Una operación de sólo lectura, sin confirmación, guardada por un scope. */
    private function scopedReadOp(): Operation
    {
        return new Operation(
            name: 'read_secret',
            description: 'Read a secret',
            handler: static fn (array $i): array => ['secret' => 'ok'],
            inputSchema: ['type' => 'object'],
            scopes: ['posts:read'],
            path: '/secret',
        );
    }

    private function projector(Operation $op, DIContainerInterface $container): HttpProjector
    {
        $psr17 = new HttpFactory();

        return new HttpProjector([$op], $container, $psr17, $psr17, policy: new AuthOperationHttpPolicy($container));
    }

    /** Un contenedor que SÍ resuelve la cadena de autenticación. */
    private function containerWithAuthChain(): DIContainerInterface
    {
        $container = $this->createMock(DIContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => $id === CredentialVerifier::class || $id === AuthContextFactory::class,
        );

        return $container;
    }

    /** Un contenedor SIN cadena de autenticación — el caso del 500 arquitectónico. */
    private function containerWithoutAuthChain(): DIContainerInterface
    {
        $container = $this->createMock(DIContainerInterface::class);
        $container->method('has')->willReturn(false);

        return $container;
    }

    private function matched(HttpProjector $projector, string $path): ServerRequest
    {
        $route = null;
        foreach ($projector->routes() as $r) {
            if ($r->path === $path) {
                $route = $r;
                break;
            }
        }
        self::assertNotNull($route, "no hay ruta sintetizada para {$path}");

        return (new ServerRequest('GET', $path))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route));
    }

    private function withActor(ServerRequest $request, Actor $actor): ServerRequest
    {
        return $request->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::authenticated($actor));
    }

    /** Quien tiene el scope pasa: 200 y el resultado del handler. */
    public function testAnActorHoldingTheScopeGetsThrough(): void
    {
        $projector = $this->projector($this->scopedReadOp(), $this->containerWithAuthChain());
        $request = $this->withActor(
            $this->matched($projector, '/secret'),
            new Actor('user:42', ActorType::User, ['posts:read']),
        );

        $response = $projector->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['secret' => 'ok'], json_decode((string) $response->getBody(), true));
    }

    /** Quien no lo tiene recibe 403 con el código que lo nombra. */
    public function testAnActorLackingTheScopeIsDenied(): void
    {
        $projector = $this->projector($this->scopedReadOp(), $this->containerWithAuthChain());
        $request = $this->withActor(
            $this->matched($projector, '/secret'),
            new Actor('user:9', ActorType::User, ['posts:write']),
        );

        $response = $projector->handle($request);

        self::assertSame(403, $response->getStatusCode());
        /** @var array{code: string} $payload */
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('MILPA_SCOPE_DENIED', $payload['code']);
    }

    /** Sin actor verificado, 401 — y no 403: no es que no pueda, es que no sabemos quién es. */
    public function testNoActorAtAllIs401(): void
    {
        $projector = $this->projector($this->scopedReadOp(), $this->containerWithAuthChain());

        $response = $projector->handle($this->matched($projector, '/secret'));

        self::assertSame(401, $response->getStatusCode());
        /** @var array{code: string} $payload */
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('MILPA_AUTH_CONTEXT_MISSING', $payload['code']);
    }

    /**
     * Sin cadena cableada es un 500, NUNCA un 401/403 — la distinción de Rod.
     *
     * Quien llama está perfecto: autenticado y con el scope. El defecto es del host, que declaró una
     * operación protegida y no cableó con qué protegerla, y un 4xx culparía a quien no hizo nada mal.
     */
    public function testNoAuthChainWiredIsAServerErrorAndNotTheCallersFault(): void
    {
        $projector = $this->projector($this->scopedReadOp(), $this->containerWithoutAuthChain());
        $request = $this->withActor(
            $this->matched($projector, '/secret'),
            new Actor('user:42', ActorType::User, ['posts:read']),
        );

        try {
            $projector->handle($request);
            self::fail('debería haber lanzado AuthMiddlewareNotInstalledException');
        } catch (AuthMiddlewareNotInstalledException $e) {
            self::assertSame(500, $e->statusCode());
            self::assertSame('MILPA_AUTH_MIDDLEWARE_NOT_INSTALLED', $e->errorCode());
            self::assertStringContainsString('read_secret', $e->getMessage());
        }
    }

    /** Una operación SIN scopes no toca nada de esto, ni con cadena ausente ni sin actor. */
    public function testAnUnscopedOperationIsUntouchedByAnyOfIt(): void
    {
        $op = new Operation(
            name: 'ping',
            description: 'Ping',
            handler: static fn (array $i): array => ['pong' => true],
            inputSchema: ['type' => 'object'],
            path: '/ping',
        );
        $projector = $this->projector($op, $this->containerWithoutAuthChain());

        $response = $projector->handle($this->matched($projector, '/ping'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['pong' => true], json_decode((string) $response->getBody(), true));
    }
}
