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

namespace Milpa\Admin\Http;

use GuzzleHttp\Psr7\Response;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Contracts\AuthContextFactory;
use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Contracts\PermissionContextFactory;
use Milpa\Auth\Contracts\PermissionResolver;
use Milpa\Auth\Exceptions\AuthContextMissingException;
use Milpa\Auth\Exceptions\AuthMiddlewareNotInstalledException;
use Milpa\Auth\Exceptions\PermissionDeniedException;
use Milpa\Auth\Exceptions\ScopeDeniedException;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Auth\Http\RequirePermissionMiddleware;
use Milpa\Auth\Http\RequireScopeMiddleware;
use Milpa\Auth\Permission;
use Milpa\Command\Operation;
use Milpa\Console\Http\HttpProjector;
use Milpa\Console\Http\OperationHttpPolicy;
use Milpa\Console\Http\UnguardedOperationException;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * La política de la superficie HTTP con `milpa/auth`: qué exige una operación antes de correr.
 *
 * Es la implementación de {@see OperationHttpPolicy} que usa identidad de verdad — contexto
 * verificado, scopes, permisos— y vive aquí porque este paquete ya requiere `milpa/auth` y ya sirve
 * HTTP. Ponerla en `milpa/console`, junto al proyector, habría arrastrado la identidad al piso
 * mínimo del framework: exponer una operación por HTTP no debería obligar a instalar un sistema de
 * autenticación.
 *
 * Vino de `milpa/skeleton` cuando éste se retiró como puerta de entrada (P14.3); las 37 referencias
 * a `Milpa\Auth` que tenía el proyector viven todas de este lado.
 *
 * Scope Y permission: una operación se tipa por uno o por el otro, nunca por los dos — `Operation` lo
 * rechaza en su constructor. El `PolicyGate` de tool-runtime es defensa en profundidad específica de
 * scope y no aplica a las tipadas por permiso.
 */
final class AuthOperationHttpPolicy implements OperationHttpPolicy
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * `null` cuando la petición pasa; un 401/403 ya formado cuando `milpa/auth` la niega.
     *
     * Lanza {@see AuthMiddlewareNotInstalledException} (500) cuando la operación exige identidad y el
     * host no cableó una cadena de autenticación: es la distinción de Rod, y es un error de servidor.
     * Un 4xx culparía a quien llamó, que no hizo nada mal — el host declaró algo protegido y lo dejó
     * sin guardia. Se conserva la excepción de `milpa/auth` y no la de console porque ésta lleva su
     * `statusCode()` y su `errorCode()`, que es lo que un host ya mapea hoy;
     * {@see UnguardedOperationException} cubre el otro caso, el de un host sin NINGUNA política.
     */
    public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
    {
        if ($op->permission !== null) {
            return $this->enforcePermission($op, $request);
        }

        if ($op->scopes !== []) {
            return $this->enforceScopes($op, $request);
        }

        return null;
    }

    /**
     * La compuerta de scopes, cerrada por defecto.
     *
     * `RequireScopeMiddleware` lee el {@see AuthContext} que un {@see AuthenticateMiddleware} río
     * arriba dejó en `'milpa.auth'` y lanza la negativa tipada; el handler centinela sólo corre si
     * admitió la petición.
     */
    public function enforceScopes(Operation $op, ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->authChainInstalled()) {
            throw AuthMiddlewareNotInstalledException::forScopedOperation($op->name, $op->scopes);
        }

        $guard = new RequireScopeMiddleware(...$op->scopes);

        try {
            $guard->process($request, $this->sentinel());
        } catch (AuthContextMissingException|ScopeDeniedException $e) {
            return $this->json($e->statusCode(), ['error' => $e->getMessage(), 'code' => $e->errorCode()]);
        }

        // Autorizada. El contexto deja de mentir: el átomo pasa por la MISMA capa de política que
        // guarda MCP, con un ToolContext::web honesto (principal real, scopes reales).
        return $this->enforceWebPolicy($op, $request);
    }

    /**
     * La contraparte tipada por permiso, espejo de {@see self::enforceScopes()}.
     *
     * Corre sólo la compuerta honesta de `RequirePermission`: el `PolicyGate` de tool-runtime razona
     * sobre scopes y aplicarlo aquí sería juzgar con la vara equivocada.
     */
    public function enforcePermission(Operation $op, ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->authChainInstalled()) {
            throw AuthMiddlewareNotInstalledException::forPermissionedOperation($op->name, (string) $op->permission);
        }

        $resolver = $this->container->has(PermissionResolver::class) ? $this->container->get(PermissionResolver::class) : null;
        $contextFactory = $this->container->has(PermissionContextFactory::class) ? $this->container->get(PermissionContextFactory::class) : null;

        $guard = new RequirePermissionMiddleware(
            Permission::parse((string) $op->permission),
            $resolver instanceof PermissionResolver ? $resolver : null,
            $contextFactory instanceof PermissionContextFactory ? $contextFactory : null,
        );

        try {
            $guard->process($request, $this->sentinel());
        } catch (AuthContextMissingException|PermissionDeniedException $e) {
            return $this->json($e->statusCode(), ['error' => $e->getMessage(), 'code' => $e->errorCode()]);
        }

        return null;
    }

    /**
     * Si el host cableó una cadena capaz de producir un {@see AuthContext} verificado.
     *
     * Cuando no hay ninguna, una operación con scopes no se puede hacer cumplir honestamente — y eso
     * es configuración del host, no una petición fallida.
     */
    public function authChainInstalled(): bool
    {
        return $this->container->has(CredentialVerifier::class)
            || $this->container->has(AuthContextFactory::class);
    }

    /**
     * Defensa en profundidad para una petición YA autorizada: reconstruye el {@see ToolContext::web()}
     * honesto y lo pasa por el mismo {@see PolicyGate} que guarda MCP. Opt-in: no hace nada si
     * `milpa/tool-runtime` no está instalado.
     */
    public function enforceWebPolicy(Operation $op, ServerRequestInterface $request): ?ResponseInterface
    {
        if (!class_exists(ToolContext::class) || !class_exists(PolicyGate::class)) {
            return null;
        }

        $context = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
        if (!$context instanceof AuthContext || $context->actor === null) {
            return null; // inalcanzable una vez que la compuerta de arriba admitió; red de seguridad
        }

        $decision = (new PolicyGate())->authorize(
            ToolContext::web($context->actor->id, $context->actor->scopes),
            new ToolDefinition(
                name: $op->name,
                description: $op->description,
                inputSchema: $op->inputSchema ?? [],
                callback: static fn (): null => null,
                scopes: $op->scopes,
                mutating: $op->mutating,
                requiresConfirmation: $op->requiresConfirmation,
            ),
        );

        if (!$decision->allowed) {
            return $this->json(403, ['error' => $decision->reason, 'code' => 'MILPA_SCOPE_DENIED']);
        }

        return null;
    }

    /**
     * El handler que corre sólo si el middleware admitió la petición.
     *
     * Su 204 no viaja a ningún lado: {@see HttpProjector} sigue con la operación cuando esta política
     * devuelve `null`. Existe porque un middleware PSR-15 necesita a quién delegar para poder admitir.
     */
    private function sentinel(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        };
    }

    private function json(int $status, mixed $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }
}
