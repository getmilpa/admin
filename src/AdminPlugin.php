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

namespace Milpa\Admin;

use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Plugin\PluginBase;
use Milpa\Console\Contracts\RouteTableSource;
use Milpa\Admin\Contracts\StorageRootSource;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Console\Section\{Section, SectionProvider};
use Milpa\Admin\Settings\SettingsStore;
use Milpa\Console\State\SectionStateProvider;
use Milpa\Console\State\SectionStateSource;
use Milpa\Console\State\PluginsStateProvider;
use Milpa\Console\State\RoutesStateProvider;

/**
 * El panel de administración de Milpa: la superficie `/milpa/admin`.
 *
 * {@see Controllers\MilpaAdminController} es el Hub (ADR#12): descubre las secciones registradas
 * vía {@see SectionProvider} y redirige a la default, sin renderizar nada él mismo. Cada
 * sección es dueña de su ruta, su gate y su render; el panel no conoce ninguna por nombre.
 *
 * Este plugin aporta tres: **Settings** (la configuración del sitio, con form y CSRF), **Plugins**
 * (qué plugins tiene el host y cuáles arrancan, sobre las operaciones de `milpa/plugin`) y
 * **Sistema** (la tabla de rutas, read-only). Cualquier otro plugin puede aportar las suyas
 * implementando {@see SectionProvider} — el panel las descubre y las pinta en la navegación
 * sin cambiar una línea de aquí.
 *
 * **Lo que un host tiene que darle:** un `SessionStore` y el middleware de scopes de `milpa/auth`
 * (el gate vive detrás de `milpa.admin`), un `PluginRegistryInterface` si quiere la sección de
 * plugins, y un {@see RouteTableSource} si quiere la de Sistema. Lo que no registre, simplemente
 * no aparece — el panel no truena por una sección que este host no puede servir.
 */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'MilpaAdmin',
    type: 'Web',
    provides: [],
    requires: []
)]
class AdminPlugin extends PluginBase implements PluginInterface, SectionProvider, SectionStateSource, RouteProviderInterface
{
    public function __construct(DIContainerInterface $container)
    {
        parent::__construct($container);
    }

    /**
     * Las rutas del panel, declaradas y ya atadas a su handler.
     *
     * Explícitas y no escaneadas de atributos: escanear obliga a un host a traer un cargador de
     * atributos, y la familia no publica ninguno — era justo lo que amarraba este panel a un host.
     * Con seis rutas, decirlas cuesta menos que la maquinaria para adivinarlas, y se leen todas de
     * un jalón en un archivo.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return [
            $this->route('/milpa/admin', HttpMethod::GET, 'milpa_admin', Controllers\MilpaAdminController::class, 'index'),
            $this->route('/milpa/admin/settings', HttpMethod::GET, 'milpa_admin_settings_show', Controllers\SettingsController::class, 'show'),
            $this->route('/milpa/admin/settings', HttpMethod::POST, 'milpa_admin_settings', Controllers\SettingsController::class, 'save'),
            $this->route('/milpa/admin/plugins', HttpMethod::GET, 'milpa_admin_plugins_show', Controllers\PluginsController::class, 'show'),
            $this->route('/milpa/admin/plugins', HttpMethod::POST, 'milpa_admin_plugins', Controllers\PluginsController::class, 'toggle'),
            $this->route('/milpa/admin/system', HttpMethod::GET, 'milpa_admin_system', Controllers\SystemController::class, 'show'),
        ];
    }

    /**
     * @param class-string $controller
     */
    private function route(string $path, HttpMethod $method, string $name, string $controller, string $handler): Route
    {
        return (new Route(path: $path, methods: $method, name: $name))
            ->withHandler(HandlerReference::method($controller, $handler));
    }

    /**
     * Las secciones propias del plugin (ui.admin.section — ADR#12): Settings (order 10), la primera
     * del admin; Plugins (order 15, justo después — el 20 ya es de `architecture`) — qué plugins
     * tiene el host y cuáles arrancan, sobre las
     * operaciones de `milpa/plugin`, que sirve {@see Controllers\PluginsController}; y Sistema
     * (order 30, P5.7) — la tabla read-only de rutas registradas que sirve
     * {@see Controllers\SystemController}.
     *
     * @return list<Section>
     */
    public function sections(): array
    {
        return [
            new Section('settings', 'Settings', '/milpa/admin/settings', 10),
            new Section('plugins', 'Plugins', '/milpa/admin/plugins', 15),
            new Section('system', 'Sistema', '/milpa/admin/system', 30),
        ];
    }

    /**
     * El estado de las secciones propias del plugin (settings + plugins + system) — el MISMO que el shell web
     * consume, ahora disponible para el shell CLI (`coa:admin`). `architecture` es web-only (sin estado
     * inspectable), así que no aparece aquí. El estado de `system` son las rutas registradas, leídas de
     * la fuente de verdad ({@see RouteTableAssembler}, la autoridad única registrada como instancia en
     * el container tras `loadPlugins()` — Ola 4d.3a).
     *
     * @return array<string, SectionStateProvider>
     */
    public function sectionStates(): array
    {
        $states = [
            'settings' => SettingsStore::provider(),
            'plugins' => PluginsStateProvider::fromContainer($this->container),
        ];

        // Sistema sólo existe si el host dijo de dónde sacar su tabla de rutas. Inventarle una
        // vacía sería peor que no ofrecer la sección: diría que esta app no tiene rutas.
        $source = $this->tryGetService(RouteTableSource::class);
        if ($source instanceof RouteTableSource) {
            $states['system'] = new RoutesStateProvider($source->routes());
        }

        return $states;
    }

    /**
     * Lo único que arranca: atar la raíz de almacenamiento que el host haya registrado.
     *
     * Si no registró ninguna, no se ata nada y {@see SettingsStore::path()} lanza cuando alguien
     * intente leer o escribir settings — con el nombre del puerto que falta. Callar aquí y adivinar
     * allá es cómo el panel terminó escribiendo dentro de `vendor/`.
     */
    public function boot(): void
    {
        $storage = $this->tryGetService(StorageRootSource::class);
        if ($storage instanceof StorageRootSource) {
            SettingsStore::bindStorageRoot($storage);
        }
    }

    /** Nada que instalar: el panel no tiene datos propios. */
    public function install(): void
    {
    }

    /** Nada que desinstalar: quitar el panel no toca lo que el panel administra. */
    public function uninstall(): void
    {
    }

    /** Nada que prender: las secciones aparecen porque el panel las descubrió. */
    public function enable(): void
    {
    }

    /** Nada que apagar. */
    public function disable(): void
    {
    }
}
