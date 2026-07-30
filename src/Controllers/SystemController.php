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
use Milpa\Console\Contracts\RouteTableSource;
use Milpa\Http\Symfony\HttpResponse;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Admin\Settings\SettingsStore;
use Milpa\Console\State\RoutesStateProvider;
use Milpa\Admin\View\AdminShellRenderer;
use Milpa\Admin\View\RoutesTableView;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * La sección "Sistema" del Milpa Admin (P5.7): una superficie READ-ONLY, detrás del MISMO gate
 * compartido que Settings (`[StartSession, RequireScopeMiddleware('milpa.admin')]` vía
 * {@see GatedAdminController::runGated()}), que muestra las rutas registradas del framework en una
 * tabla server-rendered. Sin form, sin CSRF, sin escritura — degrada perfecto sin JS (ADR#8). Lee la
 * fuente de verdad ({@see RouteTableSource}, el puerto por el que cada host publica su tabla),
 * la mapea con {@see RoutesStateProvider} y la pinta
 * con {@see RoutesTableView} dentro del {@see AdminShellRenderer}.
 */
final class SystemController extends GatedAdminController
{
    public function __construct(DIContainerInterface $container)
    {
        parent::__construct($container);
    }

    /**
     * @param array<string, string> $params
     */
    #[Route(path: '/milpa/admin/system', methods: HttpMethod::GET, name: 'milpa_admin_system')]
    /** Pinta la tabla de rutas que el host publicó por su puerto, read-only. */
    public function show(Request $request, array $params = []): HttpResponse
    {
        $handler = new class ($this->container, $this->discoveredSections()) implements RequestHandlerInterface {
            /** @param list<\Milpa\Console\Section\Section> $sections */
            public function __construct(
                private readonly DIContainerInterface $container,
                private readonly array $sections,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $context = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
                $actorId = $context instanceof AuthContext && $context->actor !== null ? $context->actor->id : 'unknown';

                // La tabla entra por el puerto: cada host arma la suya a su manera y el panel no
                // tiene por qué saber cuál.
                $source = $this->container->tryGet(RouteTableSource::class);
                if (!$source instanceof RouteTableSource) {
                    // El host no dijo de dónde salen sus rutas. Se dice, no se inventa una tabla
                    // vacía que leería como "esta app no tiene rutas".
                    return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (new AdminShellRenderer())->render(
                        '<p class="mui-banner mui-banner--warn">Este host no publicó su tabla de rutas: '
                        . 'registra un ' . RouteTableSource::class . ' en el container para ver esta sección.</p>',
                        $this->sections,
                        'system',
                        (string) (SettingsStore::provider()->state()['siteName'] ?? 'Milpa Admin'),
                        $actorId,
                    ));
                }

                $rows = (new RoutesStateProvider($source->routes()))->state()['routes'];
                $body = (new RoutesTableView())->render($rows);
                $brand = (string) (SettingsStore::provider()->state()['siteName'] ?? 'Milpa Admin');

                $page = (new AdminShellRenderer())->render($body, $this->sections, 'system', $brand, $actorId);

                return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $page);
            }
        };

        return $this->runGated($request, $handler);
    }
}
