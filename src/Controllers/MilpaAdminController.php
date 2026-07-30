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
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Console\Section\SectionDiscovery;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * El Admin Hub (ADR#12): descubre, ordena y redirige. No renderiza, no conoce ninguna sección por
 * nombre, cero ifs por sección. La política de a-dónde ir vive en
 * {@see SectionDiscovery::defaultSection()} — hoy la primera por orden; cambiarla jamás toca
 * este controller.
 */
final class MilpaAdminController extends GatedAdminController
{
    public function __construct(DIContainerInterface $container)
    {
        parent::__construct($container);
    }

    /**
     * @param array<string, string> $params
     */
    #[Route(path: '/milpa/admin', methods: HttpMethod::GET, name: 'milpa_admin')]
    /** Redirige a la sección default. El Hub no renderiza: descubre, ordena y manda. */
    public function index(Request $request, array $params = []): HttpResponse
    {
        /** @var PluginsManagerInterface $plugins */
        $plugins = $this->container->get(PluginsManagerInterface::class);
        $discovery = new SectionDiscovery($plugins->getPlugins());

        $href = $discovery->defaultSection()->href;

        return $this->runGated($request, new class ($href) implements RequestHandlerInterface {
            public function __construct(private readonly string $href)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(302, ['Location' => $this->href]);
            }
        });
    }
}
