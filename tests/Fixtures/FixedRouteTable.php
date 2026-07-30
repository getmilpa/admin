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

namespace Milpa\Admin\Tests\Fixtures;

use Milpa\Console\Contracts\RouteTableSource;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;

/**
 * Una tabla de rutas fija.
 *
 * Antes estos tests armaban el ensamblador real del host, que escanea directorios de controllers
 * de una app concreta. Eso hacía que la sección "Sistema" se probara contra las rutas que ESA app
 * tuviera ese día — si alguien borraba un controller allá, fallaba un test de aquí.
 */
final readonly class FixedRouteTable implements RouteTableSource
{
    /** @param list<Route> $routes */
    public function __construct(private array $routes)
    {
    }

    /** Una tabla con una ruta reconocible, suficiente para ver que la sección la pinta. */
    public static function withOneRoute(): self
    {
        return new self([
            (new Route(path: '/', methods: HttpMethod::GET, name: 'home'))
                ->withHandler(HandlerReference::method('App\\Controllers\\HomeController', 'index')),
        ]);
    }

    /** Un host que no tiene rutas registradas: la sección debe decirlo, no reventar. */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }
}
