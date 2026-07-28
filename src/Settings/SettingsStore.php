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
 * La ruta real vive bajo `storage/milpa-admin/settings.json` (ignorado por git salvo `.gitempty`, igual
 * que el resto de `storage/*`), calculada de forma relativa a este archivo — sin depender de la constante
 * global `rootPath` (que sólo existe cuando corre el bootstrap completo del framework, no en una corrida
 * aislada de PHPUnit; `phpunit.xml` bootstrapea solo `vendor/autoload.php`). Un override por variable de
 * entorno (`MILPA_ADMIN_SETTINGS_PATH`) permite a los tests apuntar a un archivo temporal aislado — el
 * mismo idioma que `MILPA_DESIGN_PATH` ya usa en este mismo plugin (ver `AdminPage` / `MilpaAdminShellTest`).
 */
final class SettingsStore
{
    private const ENV_PATH_OVERRIDE = 'MILPA_ADMIN_SETTINGS_PATH';

    /** La ruta real del archivo de settings: el override de test si está seteado, o la de producción. */
    public static function path(): string
    {
        $override = getenv(self::ENV_PATH_OVERRIDE);
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return \dirname(__DIR__, 3) . '/storage/milpa-admin/settings.json';
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
