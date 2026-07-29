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

namespace Milpa\Admin\Settings;

use Milpa\Admin\Contracts\StorageRootSource;
use RuntimeException;

/**
 * El ÚNICO punto de wiring de la store de PRODUCCIÓN de Milpa Admin: tanto el GET (Tarea 6) como el
 * POST (Tarea 7) deben llamar a estos mismos métodos estáticos para garantizar que ambos lean/escriban
 * el MISMO archivo — nunca construyen un {@see SettingsRepository} con su propia ruta calculada aparte,
 * porque cualquier divergencia (aunque sea un carácter) partiría el estado en dos archivos distintos.
 *
 * `SettingsController` (el dueño de la sección Settings, GET/POST en `/milpa/admin/settings` — el Hub
 * en `MilpaAdminController` solo descubre y redirige, nunca toca esta store) se construye en los tests
 * de este plugin vía `new SettingsController($container)` sin bootear `MilpaAdminPlugin` — por eso esta
 * store NO se resuelve vía el contenedor de DI (que además tampoco podría auto-wirear
 * `SettingsRepository`: su constructor toma un `string $file` sin default, un parámetro no-clase que
 * `Milpa\Container\DIContainer::canResolve()` nunca auto-resuelve). Un factory estático llamado
 * directamente por cada controller es el mecanismo que funciona IDÉNTICO en test y en producción, sin
 * depender de que el plugin haya bootstrapeado nada.
 *
 * La ruta cuelga de lo que el host declare por {@see StorageRootSource}: `<raíz>/milpa-admin/settings.json`.
 * El paquete NO la adivina. La versión anterior la calculaba con `dirname(__DIR__, 3)` —contando
 * directorios hacia arriba desde este archivo— y eso funcionaba sólo mientras el código vivía dentro del
 * host: desde un paquete daba `<raíz>/packages/storage/…`, y con `composer require` daba algo dentro de
 * `vendor/`, un directorio que el próximo `composer install` puede borrar entero.
 *
 * Un override por variable de entorno (`MILPA_ADMIN_SETTINGS_PATH`) sigue existiendo y gana sobre todo:
 * permite a los tests apuntar a un archivo temporal aislado sin montar contenedor — el mismo idioma que
 * `MILPA_DESIGN_PATH` ya usa (ver `AdminPage` / `MilpaAdminShellTest`).
 */
final class SettingsStore
{
    private const ENV_PATH_OVERRIDE = 'MILPA_ADMIN_SETTINGS_PATH';

    /**
     * Dónde dijo el host que guarda su estado. Estático porque esta store lo es: se llama desde
     * controladores construidos a mano, sin contenedor, y ése es justamente el punto.
     */
    private static ?StorageRootSource $storage = null;

    /**
     * El host declara su raíz de almacenamiento. `null` la desata — los tests que la aten deben
     * soltarla en su `tearDown`, o se la heredan al siguiente.
     *
     * {@see \Milpa\Admin\AdminPlugin::boot()} la ata sola si el host registró un
     * {@see StorageRootSource} en el contenedor.
     */
    public static function bindStorageRoot(?StorageRootSource $source): void
    {
        self::$storage = $source;
    }

    /** La ruta real del archivo de settings: el override de entorno, o lo que el host declaró. */
    public static function path(): string
    {
        $override = getenv(self::ENV_PATH_OVERRIDE);
        if (is_string($override) && $override !== '') {
            return $override;
        }

        if (self::$storage instanceof StorageRootSource) {
            return rtrim(self::$storage->storageRoot(), '/\\') . '/milpa-admin/settings.json';
        }

        // Sin raíz declarada NO hay valor por defecto, y es deliberado: adivinar es el defecto que
        // este puerto existe para eliminar. Un panel que escribe en silencio en el lugar equivocado
        // se descubre el día que alguien nota que su configuración desapareció — y para entonces ya
        // nadie sabe dónde quedó.
        throw new RuntimeException(
            'milpa/admin no sabe dónde guardar su configuración. Registra un '
            . StorageRootSource::class . ' en el contenedor del host, o exporta '
            . self::ENV_PATH_OVERRIDE . ' con la ruta completa del archivo.',
        );
    }

    /** Un {@see SettingsRepository} apuntando a la ruta compartida de {@see self::path()}. */
    public static function repository(): SettingsRepository
    {
        return new SettingsRepository(self::path());
    }

    /** El seam de lectura ({@see SettingsStateProvider}) sobre {@see self::repository()}. */
    public static function provider(): SettingsStateProvider
    {
        return new RepositorySettingsStateProvider(self::repository());
    }
}
