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

use Milpa\Admin\Components\DevToolsComponent;
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Components\RoutesComponent;
use Milpa\Admin\Components\SettingsComponent;
use Milpa\Admin\Components\StackComponent;
use Milpa\Admin\Controllers\AdminController;
use Milpa\Admin\Controllers\AssetsController;
use Milpa\Admin\Controllers\StackController;
use Milpa\Admin\Data\DevToolsSource;
use Milpa\Admin\Data\PluginsSource;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\Data\SettingsSource;
use Milpa\Admin\Data\StackSource;
use Milpa\Admin\Http\LoopbackOnlyMiddleware;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Rendering\AdminHtmlRenderer;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Admin\Stack\ComposeProjection;
use Milpa\Admin\Stack\TcpProbe;
use Milpa\Admin\View\AdminPage;
use Milpa\Admin\View\AdminShell;
use Milpa\Attributes\PluginMetadata;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * The administration panel of a Milpa app — the surface where a human leaves the house ready for the agent.
 *
 * Add it to `config/plugins.php` and `/milpa/admin` exists: a shell of Milpa Components whose sidebar
 * lists every section the booted plugins declared through {@see AdminSectionProvider}. This plugin's own
 * sections — the plugins the app boots, the routes they declare, what the app declared about the panel
 * itself, the backing services the plugins need, and the ledgers the agent writes (Dev tools, read-only)
 * — enter through that same contract, so the panel has no privileged path and names no plugin.
 *
 * What the app declares (`admin.*` in its config): `route` (default `/milpa/admin`), `locale` (`en`|`es`),
 * `middleware` (PSR-15 classes attached to every panel route; default {@see LoopbackOnlyMiddleware}),
 * `secret` (state signing; falls back to `live.secret`, then a derived one), `title`. Only a literally
 * empty `middleware` list opens the panel; any misdeclaration — a non-string entry, a map, a value that
 * is not a list, a class that does not exist or is not PSR-15 — makes every panel route fall back to
 * loopback-only, and the Settings section and the topbar chip say so (greenhouse decisions/0204).
 */
#[PluginMetadata(
    version: '0.9.0', // x-release-please-version
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'Admin',
    type: 'Web',
)]
final class AdminPlugin implements PluginInterface, RouteProviderInterface, AdminSectionProvider
{
    private ?AdminSettings $settings = null;

    /** @var list<AdminSection> */
    private array $sections = [];

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** Wires the panel's collaborators from the declared settings and registers its controllers. */
    public function boot(): void
    {
        $config = $this->tryGet(Config::class);
        $settings = AdminSettings::fromConfig($config instanceof Config ? $config : null);
        $this->settings = $settings;

        $events = $this->tryGet(MilpaEventDispatcherInterface::class);
        $events = $events instanceof MilpaEventDispatcherInterface ? $events : null;

        $catalog = new Catalog($settings->locale);
        $codec = new SignedXhtmlStateTransferCodec(
            new XhtmlStateTransferCodec(),
            new HmacStateSigner($settings->signingSecret()),
            null,
        );
        $renderer = new AdminHtmlRenderer($codec, $catalog, $settings);
        $projection = new ComposeProjection();
        $stack = new StackSource($this->container, new TcpProbe(), $projection, fallbackProvider: null);

        $this->sections = [
            new AdminSection(
                id: 'plugins',
                title: 'nav.plugins',
                component: PluginsComponent::NAME,
                order: 10,
                group: 'admin',
                definition: new PluginsComponent(new PluginsSource($this->container)),
                renderer: $renderer,
            ),
            new AdminSection(
                id: 'routes',
                title: 'nav.routes',
                component: RoutesComponent::NAME,
                order: 20,
                group: 'admin',
                definition: new RoutesComponent(new RoutesSource($this->container, $this)),
                renderer: $renderer,
            ),
            new AdminSection(
                id: 'settings',
                title: 'nav.settings',
                component: SettingsComponent::NAME,
                order: 25,
                group: 'admin',
                definition: new SettingsComponent(new SettingsSource($settings)),
                renderer: $renderer,
            ),
            new AdminSection(
                id: 'stack',
                title: 'nav.stack',
                component: StackComponent::NAME,
                order: 30,
                group: 'admin',
                definition: new StackComponent($stack),
                renderer: $renderer,
            ),
            new AdminSection(
                id: DevToolsComponent::SECTION,
                title: 'nav.devtools',
                component: DevToolsComponent::NAME,
                order: 40,
                group: 'admin',
                definition: new DevToolsComponent(new DevToolsSource($this->container)),
                renderer: $renderer,
            ),
        ];

        $shell = new AdminShell($settings, $catalog, $codec, $events);
        $page = new AdminPage($settings, $catalog);

        $this->container->registerService(
            AdminController::class,
            new AdminController($this->container, $this, $catalog, $shell, $page),
        );
        $this->container->registerService(AssetsController::class, new AssetsController());
        $this->container->registerService(StackController::class, new StackController($stack, $projection, $catalog));
        if (!$this->container->has(LoopbackOnlyMiddleware::class)) {
            $this->container->registerService(LoopbackOnlyMiddleware::class, new LoopbackOnlyMiddleware($catalog));
        }
    }

    /**
     * The panel's routes, each carrying the EFFECTIVE middleware stack — the declared one when every
     * entry names a PSR-15 middleware class (an empty list included), loopback-only the moment the
     * declaration is anything else ({@see AdminSettings::effectiveMiddleware()}).
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        $settings = $this->settings ?? AdminSettings::fromConfig(null);
        $route = $settings->route;
        $middleware = $settings->effectiveMiddleware();

        return [
            new Route(
                path: $route,
                methods: HttpMethod::GET,
                name: 'milpa_admin',
                middleware: $middleware,
                handler: HandlerReference::method(AdminController::class, 'index'),
            ),
            new Route(
                path: $route . '/s/{id}',
                methods: HttpMethod::GET,
                name: 'milpa_admin_section',
                middleware: $middleware,
                handler: HandlerReference::method(AdminController::class, 'section'),
            ),
            new Route(
                path: $route . '/assets/{file}',
                methods: HttpMethod::GET,
                name: 'milpa_admin_asset',
                middleware: $middleware,
                handler: HandlerReference::method(AssetsController::class, 'serve'),
            ),
            new Route(
                path: $settings->composeUrl(),
                methods: HttpMethod::GET,
                name: 'milpa_admin_stack_compose',
                middleware: $middleware,
                handler: HandlerReference::method(StackController::class, 'compose'),
            ),
        ];
    }

    /**
     * The panel's own sections — Plugins, Routes, Settings, Stack and Dev tools — through the same contract every plugin uses.
     *
     * @return list<AdminSection>
     */
    public function adminSections(): array
    {
        return $this->sections;
    }

    /** The settings the panel booted with, or the defaults before boot. */
    public function settings(): AdminSettings
    {
        return $this->settings ?? AdminSettings::fromConfig(null);
    }

    /** Nothing to install: the panel keeps no data of its own. */
    public function install(): void
    {
    }

    /** Nothing to uninstall: removing the panel touches nothing it administers. */
    public function uninstall(): void
    {
    }

    /** Nothing to enable: sections exist because the panel discovered them. */
    public function enable(): void
    {
    }

    /** Nothing to disable. */
    public function disable(): void
    {
    }

    private function tryGet(string $id): ?object
    {
        if (!$this->container->has($id)) {
            return null;
        }
        $service = $this->container->get($id);

        return \is_object($service) ? $service : null;
    }
}
