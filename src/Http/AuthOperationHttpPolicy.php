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

use Milpa\Auth\Http\AuthOperationHttpPolicy as PoliticaDeAuth;

/**
 * La política de identidad para operaciones servidas por HTTP, que ahora vive en `milpa/auth`.
 *
 * Estuvo aquí un rato por una razón práctica —este paquete ya requería `milpa/auth` y ya servía
 * HTTP— y esa razón resultó ser la equivocada: la clase usa `milpa/auth` de arriba a abajo y no usa
 * nada del panel. Tenerla aquí obligaba a instalar diecinueve paquetes para servir una operación
 * protegida por HTTP; en `milpa/auth` son los que ya tiene quien pide identidad.
 *
 * Se queda como subclase vacía en vez de desaparecer: quien la haya nombrado sigue funcionando.
 *
 * @deprecated 0.5.0 usa {@see \Milpa\Auth\Http\AuthOperationHttpPolicy}
 */
final class AuthOperationHttpPolicy extends PoliticaDeAuth
{
}
