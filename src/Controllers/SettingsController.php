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

use GuzzleHttp\Psr7\Response;
use Milpa\Http\Symfony\HttpResponse;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Auth\Http\CsrfGuard;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Live\Schema\FormDefinition;
use Milpa\Live\Schema\SchemaForm;
use Milpa\Admin\Http\ShellRenderHandler;
use Milpa\Admin\Projection\ToolBannerMapper;
use Milpa\Admin\Projection\ToolProjector;
use Milpa\Admin\Settings\SettingsFormSchema;
use Milpa\Admin\Settings\SettingsStore;
use Milpa\Admin\Settings\SettingsToolRegistry;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * El POST de settings detrás del MISMO gate compartido que el GET (Tarea 6):
 * `[StartSession, RequireScopeMiddleware('milpa.admin')]` vía
 * {@see GatedAdminController::runGated()}, más {@see CsrfGuard} compuesta DESPUÉS como
 * `$extraMiddleware` — un CSRF ausente o que no matchea nunca llega a bindear ni a persistir,
 * porque el guard corre y lanza ANTES de que el `$tip` de abajo se ejecute.
 *
 * El `$tip` bindea el body contra el MISMO `FormDefinition` que el GET
 * ({@see SettingsFormSchema::definition()}) vía `SchemaForm::bind()`. Válido → dispatch GOBERNADO
 * (P5.4, ADR#11): {@see ToolProjector::dispatch()} corre SIEMPRE por `ToolRegistry::call()` —
 * policy/rate-limit/audit/contención — nunca invoke directo; el ceremonial de P5.3a murió con
 * este cambio. Success → PRG (303 See Other a `/milpa/admin/settings`, para que un refresh re-GET-ee
 * en vez de reenviar el POST). Redisplay (bind inválido, o el registry rechazó/falló) → el MISMO shell
 * ({@see ShellRenderHandler}, Tarea 6) con los valores Y errores de LA SUMISIÓN — nunca la store,
 * nunca una mezcla —, el `?FormBanner` seguro que el proyector arma cuando aplica, y una
 * cookie/hidden CSRF frescos (mismo idioma que el GET: un token nuevo por render).
 */
final class SettingsController extends GatedAdminController
{
    public function __construct(DIContainerInterface $container)
    {
        parent::__construct($container);
    }

    /**
     * @param array<string, string> $params
     */
    #[Route(path: '/milpa/admin/settings', methods: HttpMethod::GET, name: 'milpa_admin_settings_show')]
    /** Pinta el formulario de configuración con un token CSRF nuevo por petición. */
    public function show(Request $request, array $params = []): HttpResponse
    {
        // Un token nuevo por request — la MISMA cadena viaja como hidden `csrf` del form Y como
        // valor de la cookie `milpa_admin_csrf` (el handler emite ambos); nunca derivado de
        // `milpa_session`. `SettingsFormSchema`/`SettingsStore` son los mismos seams compartidos
        // que usará el POST de la Tarea 7, así que ambos leen/escriben el mismo archivo y arman el
        // mismo `FormDefinition` desde el mismo `#[Tool]`.
        $csrf = bin2hex(random_bytes(32));
        $definition = SettingsFormSchema::definition();
        $values = SettingsStore::provider()->state();

        // El flag `Secure` de la cookie CSRF sale del `isSecure()` de la Request de Symfony —
        // consciente de proxies de confianza (`Request::setTrustedProxies(...)` con el allowlist de
        // Cloudflare/Docker, igual que hacía el extinto `WebManager::run()` — retirado en la Ola 7c,
        // hoy `WebRunner::run()`), así que sólo honra
        // `X-Forwarded-Proto` de un proxy conocido. Se pasa como booleano al handler, que sólo ve la
        // Request PSR-7 proyectada (sin ese allowlist) — mismo idioma que
        // `AuthController::setOAuthStateCookie`, que usa `$request->isSecure()` directo.
        $secure = $request->isSecure();

        return $this->runGated(
            $request,
            new ShellRenderHandler(
                $definition,
                $values,
                $csrf,
                $secure,
                sections: $this->discoveredSections(),
                activeSectionId: 'settings',
                // El brand del chrome sale del `siteName` persistido — que en el GET ES `$values`
                // (viene de `provider()->state()`); pasarlo explícito deja el chrome correcto-por-
                // construcción, simétrico con el redisplay del POST (B3).
                brand: (string) ($values['siteName'] ?? 'Milpa Admin'),
            ),
        );
    }

    /**
     * @param array<string, string> $params
     */
    #[Route(path: '/milpa/admin/settings', methods: HttpMethod::POST, name: 'milpa_admin_settings')]
    /** Guarda la configuración. Éxito → PRG; sumisión inválida → se repinta con sus errores. */
    public function save(Request $request, array $params = []): HttpResponse
    {
        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);
        $registry = SettingsToolRegistry::create($logger);

        return $this->runGated(
            $request,
            $this->tip(
                $request->isSecure(),
                new ToolProjector($registry, new SchemaForm(), new ToolBannerMapper()),
                SettingsFormSchema::definition($registry),
            ),
            [new CsrfGuard('milpa_admin_csrf', 'csrf')],
            '/milpa/admin/settings',
        );
    }

    /**
     * El handler PSR-15 que corre una vez que el gate de auth Y el CsrfGuard ya admitieron la
     * request: lee el body, dispatchea vía el proyector gobernado, y proyecta el resultado a PRG o
     * redisplay. `$secure` viaja desde el controller (que ya lo resolvió vía `Request::isSecure()`
     * de Symfony) porque el redisplay inválido necesita emitir una cookie CSRF fresca con el mismo
     * flag `Secure` que el GET.
     */
    private function tip(bool $secure, ToolProjector $projector, FormDefinition $definition): RequestHandlerInterface
    {
        // Descubierto UNA vez por request, antes de que el tip corra — el mismo idioma que el GET
        // (Tarea 5, ADR#12): el redisplay inválido pinta el sidebar completo, no solo Settings.
        $sections = $this->discoveredSections();

        return new class ($secure, $projector, $definition, $sections) implements RequestHandlerInterface {
            /** @param list<\Milpa\Admin\Section\AdminSection> $sections */
            public function __construct(
                private readonly bool $secure,
                private readonly ToolProjector $projector,
                private readonly FormDefinition $definition,
                private readonly array $sections,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // El gate ya garantizó un AuthContext autenticado con milpa.admin; el ctx del
                // dispatch lleva los scopes EXACTOS verificados (jamás wildcard — ADR#11).
                $auth = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
                if (!$auth instanceof AuthContext || $auth->actor === null) {
                    throw new \LogicException('El tip de settings corrió sin AuthContext: el gate debió impedirlo.');
                }
                $context = ToolContext::web($auth->actor->id, $auth->actor->scopes);

                $result = $this->projector->dispatch(
                    'settings_update',
                    $this->definition,
                    $this->parsedBody($request),
                    $context,
                );

                if ($result->isSuccess()) {
                    // La superficie navega (PRG) — el proyector proyecta, no navega. 303 See Other:
                    // la semántica exacta post-POST (un refresh re-GET-ea /milpa/admin/settings en
                    // vez de reenviar el POST).
                    return new Response(303, ['Location' => '/milpa/admin/settings']);
                }

                // Un token nuevo por render — el mismo idioma que el GET: nunca se reusa el token
                // que la request acaba de consumir para pasar el CsrfGuard.
                $csrf = bin2hex(random_bytes(32));
                // El brand del chrome sale del `siteName` PERSISTIDO, no de la sumisión inválida
                // (B3): un rechazo manda `siteName=''` en los valores del redisplay, y derivar el
                // brand de ahí blanquearía topbar+sidebar. Un bind inválido nunca dispatcha a
                // persistir, así que `state()` aquí es el valor guardado pre-POST.
                $brand = (string) (SettingsStore::provider()->state()['siteName'] ?? 'Milpa Admin');
                $handler = new ShellRenderHandler(
                    $this->definition,
                    $result->submission()->values,
                    $csrf,
                    $this->secure,
                    $result->submission()->validation,
                    $result->banner(),
                    $this->sections,
                    'settings',
                    $brand,
                );

                return $handler->handle($request);
            }

            /**
             * Lee el body posteado — `getParsedBody()` (ya poblado por `PsrHttpFactory` desde
             * `$request->request->all()` de Symfony para un POST `x-www-form-urlencoded`/multipart),
             * con fallback a parsear `php://input` crudo cuando viene vacío — el mismo idioma que
             * `McpController::oauthToken()`, adaptado a la request PSR-7 que este `$tip` recibe.
             *
             * @return array<string, mixed>
             */
            private function parsedBody(ServerRequestInterface $request): array
            {
                $body = $request->getParsedBody();
                if (is_array($body) && $body !== []) {
                    return $body;
                }

                parse_str((string) $request->getBody(), $parsed);

                return $parsed;
            }
        };
    }
}
