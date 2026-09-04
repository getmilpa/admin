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

namespace Milpa\Admin\I18n;

/**
 * Every human-facing string of the panel, by key, in English (default) and Spanish.
 *
 * The panel never hardcodes copy: a view asks for a key and the catalog answers in the declared
 * locale, falling back to English, and to the key itself when nobody wrote it. A section's title may
 * be a key the catalog knows or a literal the plugin chose — {@see self::has()} tells them apart.
 */
final class Catalog
{
    public const DEFAULT_LOCALE = 'en';

    /** @var array<string, array<string, string>> */
    private const MESSAGES = [
        'en' => [
            'title' => 'Milpa Admin',
            'nav.plugins' => 'Plugins',
            'nav.routes' => 'Routes',
            'plugins.heading' => 'Plugins this app boots',
            'plugins.no_registry' => 'This app keeps no plugin registry: the kernel boots what config/plugins.php declares and tracks nothing else.',
            'plugins.empty' => 'No plugin is declared.',
            'plugins.capabilities' => 'Capabilities',
            'plugins.installed' => 'Installed',
            'plugins.available' => 'Available',
            'plugins.no_capabilities' => 'Install milpa/app-runtime to see what this app can do and the command that grows it.',
            'routes.heading' => 'Routes the booted plugins declared',
            'routes.no_kernel' => 'The kernel is not in the container: register it in public/index.php so the panel can read every booted plugin. Showing only the panel\'s own routes.',
            'routes.empty' => 'No plugin declared a route.',
            'col.name' => 'Name',
            'col.version' => 'Version',
            'col.type' => 'Type',
            'col.enabled' => 'Enabled',
            'col.source' => 'Source',
            'col.class' => 'Class',
            'col.method' => 'Method',
            'col.path' => 'Path',
            'col.route' => 'Route name',
            'col.handler' => 'Handler',
            'col.middleware' => 'Middleware',
            'col.plugin' => 'Plugin',
            'col.package' => 'Package',
            'col.command' => 'Command',
            'on' => 'on',
            'off' => 'off',
            'none' => '—',
            'section.unknown' => 'No section is named «%s».',
            'section.none' => 'No plugin declared an admin section yet. Implement Milpa\Admin\Section\AdminSectionProvider in a plugin and the panel lists it here.',
            'section.conflict' => 'The panel cannot compose its sections: %s',
            'gate.loopback' => 'Milpa Admin answers only to loopback by default. Declare admin.middleware in config/app.php to put it behind your own gate.',
            'skip' => 'Skip to content',
        ],
        'es' => [
            'title' => 'Milpa Admin',
            'nav.plugins' => 'Plugins',
            'nav.routes' => 'Rutas',
            'plugins.heading' => 'Plugins que esta app arranca',
            'plugins.no_registry' => 'Esta app no lleva registro de plugins: el kernel arranca lo que declara config/plugins.php y no rastrea nada más.',
            'plugins.empty' => 'No hay ningún plugin declarado.',
            'plugins.capabilities' => 'Capacidades',
            'plugins.installed' => 'Instaladas',
            'plugins.available' => 'Disponibles',
            'plugins.no_capabilities' => 'Instala milpa/app-runtime para ver qué puede hacer esta app y el comando que la hace crecer.',
            'routes.heading' => 'Rutas que declararon los plugins arrancados',
            'routes.no_kernel' => 'El kernel no está en el container: regístralo en public/index.php para que el panel lea todos los plugins arrancados. Se muestran sólo las rutas del propio panel.',
            'routes.empty' => 'Ningún plugin declaró una ruta.',
            'col.name' => 'Nombre',
            'col.version' => 'Versión',
            'col.type' => 'Tipo',
            'col.enabled' => 'Activo',
            'col.source' => 'Origen',
            'col.class' => 'Clase',
            'col.method' => 'Método',
            'col.path' => 'Path',
            'col.route' => 'Nombre de ruta',
            'col.handler' => 'Handler',
            'col.middleware' => 'Middleware',
            'col.plugin' => 'Plugin',
            'col.package' => 'Paquete',
            'col.command' => 'Comando',
            'on' => 'sí',
            'off' => 'no',
            'none' => '—',
            'section.unknown' => 'No hay ninguna sección llamada «%s».',
            'section.none' => 'Ningún plugin declaró todavía una sección del admin. Implementa Milpa\Admin\Section\AdminSectionProvider en un plugin y el panel la lista aquí.',
            'section.conflict' => 'El panel no puede componer sus secciones: %s',
            'gate.loopback' => 'Milpa Admin sólo responde a loopback por default. Declara admin.middleware en config/app.php para ponerlo detrás de tu propia puerta.',
            'skip' => 'Saltar al contenido',
        ],
    ];

    private string $locale;

    public function __construct(string $locale = self::DEFAULT_LOCALE)
    {
        $this->locale = isset(self::MESSAGES[$locale]) ? $locale : self::DEFAULT_LOCALE;
    }

    /** The message for a key in the catalog's locale, with `sprintf` arguments applied; the key itself when unknown. */
    public function tr(string $key, string ...$args): string
    {
        $message = self::MESSAGES[$this->locale][$key] ?? self::MESSAGES[self::DEFAULT_LOCALE][$key] ?? $key;

        return $args === [] ? $message : vsprintf($message, $args);
    }

    /** True when the catalog knows the key — how a section title is told apart from a literal. */
    public function has(string $key): bool
    {
        return isset(self::MESSAGES[$this->locale][$key]) || isset(self::MESSAGES[self::DEFAULT_LOCALE][$key]);
    }

    /** The locale this catalog answers in. */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * The locales the catalog carries.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_keys(self::MESSAGES);
    }
}
