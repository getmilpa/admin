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

/**
 * En los tests, el HOST es la suite. Y como cualquier host, tiene que decir dónde guarda su estado.
 *
 * ── POR QUÉ EXISTE ESTE ARCHIVO ─────────────────────────────────────────────────────────────────
 *
 * Doce tests pasaban sin declarar nada, porque el paquete adivinaba la ruta contando directorios
 * hacia arriba y desde el propio paquete la cuenta daba. Ninguno de esos doce trata sobre settings
 * —son banners, el gate, la sección de plugins, el shell— y la tocaban de paso, sin saberlo.
 *
 * Ésa es la forma exacta en que el defecto sobrevivió a 28 tests en verde: **la suite corría desde
 * el único lugar donde la cuenta era correcta.** El primer consumidor real lo encontró en la
 * primera hora.
 *
 * Ahora la suite declara su raíz como la declararía cualquier host, en un directorio temporal
 * propio de esta corrida. Un test que quiera aislar su archivo sigue pudiendo exportar
 * `MILPA_ADMIN_SETTINGS_PATH`, que gana sobre esto.
 */

require __DIR__ . '/../vendor/autoload.php';

$raiz = sys_get_temp_dir() . '/milpa-admin-tests-' . bin2hex(random_bytes(6));

Milpa\Admin\Settings\SettingsStore::bindStorageRoot(
    new class ($raiz) implements Milpa\Admin\Contracts\StorageRootSource {
        public function __construct(private readonly string $raiz)
        {
        }

        public function storageRoot(): string
        {
            return $this->raiz;
        }
    },
);

// Se limpia al salir. Un temporal por corrida que nadie borra convierte /tmp en un registro de
// cuántas veces se corrió la suite.
register_shutdown_function(static function () use ($raiz): void {
    $archivo = $raiz . '/milpa-admin/settings.json';
    if (is_file($archivo)) {
        unlink($archivo);
    }
    foreach ([$raiz . '/milpa-admin', $raiz] as $dir) {
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
});
