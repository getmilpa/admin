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
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Auth\Http\CsrfGuard;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Http\Symfony\HttpResponse;
use Milpa\Admin\Http\ShellRenderHandler;
use Milpa\Admin\Settings\SettingsStore;
use Milpa\Console\State\PluginsStateProvider;
use Milpa\Admin\View\AdminShellRenderer;
use Milpa\Admin\View\PluginsTableView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * La sección "Plugins" del Milpa Admin: qué plugins tiene este host, cuáles arrancan, y un botón
 * por fila para prenderlos o apagarlos.
 *
 * Detrás del MISMO gate compartido que Settings y Sistema
 * (`[StartSession, RequireScopeMiddleware('milpa.admin')]` vía {@see GatedAdminController::runGated()}),
 * más {@see CsrfGuard} en el POST — conmutar un plugin cambia lo que este host ejecuta en la
 * siguiente petición, así que no puede dispararse desde otro sitio.
 *
 * No implementa nada de plugins: maneja las operaciones de `milpa/plugin` a través de
 * {@see PluginsStateProvider}. La terminal (`coa plugins.list`), un cliente MCP y esta página
 * llegan exactamente a la misma definición, así que no pueden decir cosas distintas.
 *
 * Éxito → PRG (303 a la misma ruta, para que un refresh no re-envíe el POST) y la propia tabla
 * es la confirmación: la fila cambia de "activo" a "inactivo". Fallo → redisplay con el motivo,
 * mismo idioma que Settings.
 */
final class PluginsController extends GatedAdminController
{
    public function __construct(DIContainerInterface $container)
    {
        parent::__construct($container);
    }

    /**
     * @param array<string, string> $params
     */
    #[Route(path: '/milpa/admin/plugins', methods: HttpMethod::GET, name: 'milpa_admin_plugins_show')]
    /** Pinta los plugins del host y un botón por fila para prenderlos o apagarlos. */
    public function show(Request $request, array $params = []): HttpResponse
    {
        return $this->runGated($request, $this->page($request->isSecure(), bin2hex(random_bytes(32)), null));
    }

    /**
     * @param array<string, string> $params
     */
    #[Route(path: '/milpa/admin/plugins', methods: HttpMethod::POST, name: 'milpa_admin_plugins')]
    /** Conmuta un plugin. Éxito → PRG; fallo → se repinta con el motivo. */
    public function toggle(Request $request, array $params = []): HttpResponse
    {
        $secure = $request->isSecure();
        $provider = PluginsStateProvider::fromContainer($this->container);

        $handler = new class ($provider, fn (?string $notice): RequestHandlerInterface => $this->page($secure, bin2hex(random_bytes(32)), $notice)) implements RequestHandlerInterface {
            /** @param \Closure(?string): RequestHandlerInterface $redisplay */
            public function __construct(
                private readonly PluginsStateProvider $provider,
                private readonly \Closure $redisplay,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $body = $request->getParsedBody();
                $body = \is_array($body) ? $body : [];

                $name = \is_string($body['name'] ?? null) ? $body['name'] : '';
                $action = \is_string($body['action'] ?? null) ? $body['action'] : '';

                if ($name === '' || !\in_array($action, ['enable', 'disable'], true)) {
                    return ($this->redisplay)('Petición incompleta: falta el plugin o la acción.')->handle($request);
                }

                $failure = $this->provider->toggle($name, $action === 'enable');
                if ($failure !== null) {
                    return ($this->redisplay)($failure)->handle($request);
                }

                // Éxito → PRG. La confirmación es la tabla misma: la fila vuelve pintada con su
                // estado nuevo, que es más honesto que un mensaje diciendo que algo pasó.
                return new Response(303, ['Location' => '/milpa/admin/plugins']);
            }
        };

        return $this->runGated(
            $request,
            $handler,
            [new CsrfGuard(ShellRenderHandler::CSRF_COOKIE, ShellRenderHandler::CSRF_FIELD)],
            '/milpa/admin/plugins',
        );
    }

    /**
     * El handler que pinta la sección: tabla dentro del shell, más la cookie CSRF que el POST
     * verificará. Emite un token nuevo por request, igual que Settings.
     */
    private function page(bool $secure, string $csrf, ?string $notice): RequestHandlerInterface
    {
        return new class ($this->container, $this->discoveredSections(), $csrf, $secure, $notice) implements RequestHandlerInterface {
            /** @param list<\Milpa\Console\Section\Section> $sections */
            public function __construct(
                private readonly DIContainerInterface $container,
                private readonly array $sections,
                private readonly string $csrf,
                private readonly bool $secure,
                private readonly ?string $notice,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $context = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
                $actorId = $context instanceof AuthContext && $context->actor !== null ? $context->actor->id : 'unknown';

                $state = PluginsStateProvider::fromContainer($this->container)->state();
                $body = (new PluginsTableView())->render(
                    $state['plugins'],
                    $state['canInstall'],
                    $state['available'],
                    $this->csrf,
                    $this->notice,
                );

                $brand = (string) (SettingsStore::provider()->state()['siteName'] ?? 'Milpa Admin');
                $page = (new AdminShellRenderer())->render($body, $this->sections, 'plugins', $brand, $actorId);

                $cookie = (string) new Cookie(
                    ShellRenderHandler::CSRF_COOKIE,
                    $this->csrf,
                    time() + 3600,
                    '/milpa/admin',
                    null,
                    $this->secure,
                    true,
                    false,
                    Cookie::SAMESITE_LAX,
                );

                return new Response(200, [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Set-Cookie' => $cookie,
                ], $page);
            }
        };
    }
}
