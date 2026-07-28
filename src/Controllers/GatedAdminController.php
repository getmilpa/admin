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

namespace Milpa\Admin\Controllers;

use GuzzleHttp\Psr7\HttpFactory;
use Milpa\Http\Symfony\BaseController;
use Milpa\Http\Symfony\HttpResponse;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\Exceptions\AuthException;
use Milpa\Auth\Http\RequireScopeMiddleware;
use Milpa\Auth\Http\StartSession;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionDiscovery;
use Milpa\Admin\Support\LocalRedirectTarget;
use Milpa\Runtime\Http\MiddlewarePipeline;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;

/**
 * El seam compartido del gate de Milpa Admin: passkey session → `milpa.admin` → handler. Cada
 * controller del área (shell GET, settings POST, …) construye su propio `$tip` PSR-15 y llama a
 * {@see self::runGated()}; el middleware produce 401/403 tipado y este seam lo proyecta a
 * `HttpResponse` (redirect a login / HTML "sin acceso"), preservando `Content-Type`, cualquier
 * `Set-Cookie` que el handler emita, y — desde la Tarea 7 — el header `Location` de un 302 (el PRG
 * del POST de settings tras un dispatch válido).
 */
abstract class GatedAdminController extends BaseController
{
    /**
     * Arma `[StartSession, RequireScopeMiddleware('milpa.admin'), ...$extraMiddleware]` delante de
     * `$tip`, la dispatchea, y proyecta el resultado PSR-7 a `HttpResponse`. `$extraMiddleware` se
     * compone SIEMPRE después del gate de auth (p. ej. un `CsrfGuard` en un POST) — el default `[]`
     * deja el pipeline de un GET simple byte-idéntico al de antes de este seam existir. `$returnTo`
     * declara el next explícito del 401 (ADR#12 — safe-GET-destination): lo usa un controller cuyo
     * request actual no es un GET seguro para reusar como destino (el POST de settings, que declara
     * el path de su propio GET); un GET simple no lo necesita — ver el criterio completo en el catch
     * de abajo.
     *
     * @param list<MiddlewareInterface> $extraMiddleware middlewares adicionales, después del gate de auth
     * @param ?string                   $returnTo        next explícito del 401; si es null, un GET usa su propio path
     */
    protected function runGated(Request $request, RequestHandlerInterface $tip, array $extraMiddleware = [], ?string $returnTo = null): HttpResponse
    {
        $guzzle = new HttpFactory();
        $psr = (new PsrHttpFactory($guzzle, $guzzle, $guzzle, $guzzle))->createRequest($request);

        $pipeline = new MiddlewarePipeline(
            [
                new StartSession($this->container->get(SessionStore::class)),
                new RequireScopeMiddleware('milpa.admin'),
                ...$extraMiddleware,
            ],
            $tip,
        );

        try {
            $response = $pipeline->handle($psr);

            $httpResponse = $this->cleanResponse((string) $response->getBody(), $response->getStatusCode(), [
                'Content-Type' => $response->getHeaderLine('Content-Type') ?: 'text/html; charset=utf-8',
            ]);

            // El handler puede emitir cookies (p. ej. la cookie CSRF de settings GET) — sobreviven
            // la proyección PSR-7 → HttpResponse copiándolas tal cual al header bag de Symfony, que
            // las parsea vía Cookie::fromString() y las vuelve a emitir como Set-Cookie propios.
            foreach ($response->getHeader('Set-Cookie') as $setCookie) {
                $httpResponse->headers->set('Set-Cookie', $setCookie, false);
            }

            // El handler también puede emitir un 302 con `Location` (el PRG del POST de settings,
            // Tarea 7, tras un dispatch válido) — sin esta línea el status 302 llegaría sin destino,
            // dejando al navegador sin adónde ir. Un GET nunca la emite, así que este `if` es un
            // no-op para el pipeline de shell existente.
            if ($response->hasHeader('Location')) {
                $httpResponse->headers->set('Location', $response->getHeaderLine('Location'));
            }

            return $httpResponse;
        } catch (AuthException $e) {
            if ($e->statusCode() === 401) {
                // El next preserva la sección (criterio safe-GET-destination): un GET vuelve a su
                // propio path; un POST usa el returnTo explícito que su controller declaró (el GET
                // equivalente); sin ninguno, el fallback es el Hub — que redirige a la default.
                // resolve() sigue validando el candidato fail-closed (local absoluto, sin control chars).
                $candidate = $returnTo ?? ($request->isMethod('GET') ? $request->getPathInfo() : null);
                $next = LocalRedirectTarget::resolve($candidate, '/milpa/admin');

                return $this->redirect('/agency/login?next=' . rawurlencode($next), 302);
            }

            // 403 CSRF — el state token faltó o no matcheó. La excepción trae un mensaje learnable
            // (qué falló + cómo arreglarlo + link, NUNCA un token) que se muestra tal cual: el 403
            // genérico de scope descartaba esa pista y dejaba al que lo golpeó sin saber el fix
            // (Learnable Errors). Se discrimina por el código `MILPA_*` estable, no por instanceof.
            if ($e->errorCode() === 'MILPA_CSRF_DENIED') {
                return $this->cleanResponse(
                    '<!doctype html><title>Milpa Admin</title><main data-error-code="'
                    . htmlspecialchars($e->errorCode(), \ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($e->getMessage(), \ENT_QUOTES, 'UTF-8') . '</main>',
                    $e->statusCode(),
                    ['Content-Type' => 'text/html; charset=utf-8'],
                );
            }

            // 403 — autenticado pero sin milpa.admin.
            return $this->cleanResponse(
                '<!doctype html><title>Milpa Admin</title><main>Sin acceso: no tienes permiso para entrar a Milpa Admin.</main>',
                $e->statusCode(),
                ['Content-Type' => 'text/html; charset=utf-8'],
            );
        }
    }

    /**
     * El discovery de secciones (ADR#12) — mismo idioma que
     * {@see \Milpa\Admin\Controllers\MilpaAdminController::index()}: resuelve
     * `PluginsManagerInterface` del container y arma un `AdminSectionDiscovery` fresco sobre los
     * plugins booteados. Compartido por cualquier controller de esta área que necesite pintar el
     * sidebar completo (el shell GET de settings, el redisplay del POST) — sin cachear entre
     * requests, un discovery por invocación.
     *
     * @return list<AdminSection>
     */
    protected function discoveredSections(): array
    {
        /** @var PluginsManagerInterface $plugins */
        $plugins = $this->container->get(PluginsManagerInterface::class);

        return (new AdminSectionDiscovery($plugins->getPlugins()))->sections();
    }
}
