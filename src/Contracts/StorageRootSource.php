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

namespace Milpa\Admin\Contracts;

/**
 * Dónde guarda su estado mutable el host que consume este panel.
 *
 * ── POR QUÉ EXISTE ──────────────────────────────────────────────────────────────────────────────
 *
 * Antes, la ruta se calculaba contando directorios hacia arriba desde el propio archivo:
 * `dirname(__DIR__, 3) . '/storage/milpa-admin/settings.json'`. Eso daba la raíz del host mientras
 * el código vivía dentro del host. Desde un paquete da `<raíz>/packages/storage/…`, y con
 * `composer require` da algo **dentro de `vendor/`** — un directorio que el próximo `composer
 * install` puede borrar entero.
 *
 * Los 28 tests del paquete pasaban, y por eso nadie lo vio: todos corren desde el propio paquete,
 * donde la cuenta sí da. El defecto sólo aparece cuando alguien consume el paquete COMO paquete, y
 * apareció en la primera hora del primer consumidor real.
 *
 * **Un paquete no puede saber dónde está la raíz de quien lo consume contando directorios desde sí
 * mismo.** Sólo el host lo sabe, así que el host lo dice. Es el mismo movimiento que
 * {@see RouteTableSource} hizo con la tabla de rutas: lo que sólo el host puede contestar, el host
 * lo contesta.
 *
 * ── QUÉ PASA SI NADIE LO REGISTRA ───────────────────────────────────────────────────────────────
 *
 * {@see \Milpa\Admin\Settings\SettingsStore::path()} lanza, y lo dice con nombre y apellido. No cae
 * a un valor por defecto: adivinar es exactamente el defecto que este puerto existe para eliminar,
 * y un panel que escribe en silencio en el lugar equivocado se descubre el día que alguien nota que
 * su configuración desapareció.
 */
interface StorageRootSource
{
    /**
     * El directorio donde este host guarda estado mutable, absoluto y sin barra final.
     *
     * El panel cuelga lo suyo debajo (`<raíz>/milpa-admin/…`) y no escribe fuera de ahí.
     */
    public function storageRoot(): string;
}
