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

use Milpa\Admin\Http\AuthOperationHttpPolicy;
use Milpa\Command\OperationHttpPolicy;
use PHPUnit\Framework\TestCase;

/**
 * El nombre viejo sigue sirviendo.
 *
 * La política de identidad se mudó a `milpa/auth`, que es donde vive todo lo que usa. Quien la haya
 * nombrado desde aquí —era pública en 0.4.0— no tiene por qué enterarse: esta prueba es la promesa
 * de que su código sigue compilando y sigue satisfaciendo el contrato que el proyector pide.
 *
 * Las pruebas de COMPORTAMIENTO se fueron con la clase: probarlo dos veces sólo garantiza que las
 * dos copias digan lo mismo hoy.
 */
final class DeprecatedPolicyAliasTest extends TestCase
{
    public function testTheOldNameStillSatisfiesTheContract(): void
    {
        self::assertTrue(is_a(AuthOperationHttpPolicy::class, OperationHttpPolicy::class, true));
        self::assertTrue(
            is_a(AuthOperationHttpPolicy::class, \Milpa\Auth\Http\AuthOperationHttpPolicy::class, true),
            'el nombre viejo tiene que SER el nuevo, no una segunda implementación',
        );
    }
}
