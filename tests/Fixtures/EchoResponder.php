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

use Milpa\Http\Symfony\HttpResponderInterface;
use Milpa\Http\Symfony\HttpResponse;

/**
 * Un renderizador que devuelve el nombre de la vista en vez de renderizarla.
 *
 * El panel construye su propio HTML y sólo necesita que ESTO exista en el container para que un
 * controller se pueda construir. Traer un motor de plantillas real sólo para eso ataría el paquete
 * a una dependencia que no usa, y haría que un fallo de Latte se leyera como un fallo del panel.
 */
final class EchoResponder implements HttpResponderInterface
{
    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    public function renderCustomView(string $view, array $params = [], array $headers = ['Content-Type' => 'text/html']): HttpResponse
    {
        return new HttpResponse('vista:' . $view, 200, $headers);
    }

    /** @param array<string, mixed> $params */
    public function renderPage(string $view, array $params = []): HttpResponse
    {
        return new HttpResponse('página:' . $view);
    }
}
