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

use Milpa\Admin\Http\CapabilityInstaller;
use Milpa\Console\Http\HttpProjector;
use Milpa\Console\FileConfirmTokenStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Milpa\Admin\Components\DevToolsComponent;
use Milpa\Admin\Components\HouseComponent;
use Milpa\Admin\Data\HouseSource;
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Components\RoutesComponent;
use Milpa\Admin\Components\SettingsComponent;
use Milpa\Admin\Components\StackComponent;
use Milpa\Admin\Controllers\AdminController;
use Milpa\Admin\Controllers\AssetsController;
use Milpa\Admin\Controllers\LiveController;
use Milpa\Admin\Controllers\StackController;
use Milpa\Admin\Data\DevToolsSource;
use Milpa\Admin\Data\PluginsSource;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\Data\SettingsSource;
use Milpa\Admin\Data\StackSource;
use Milpa\Admin\Event\AdminEvents;
use Milpa\Admin\Http\LoopbackOnlyMiddleware;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Rendering\AdminHtmlRenderer;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Admin\Stack\ComposeProjection;
use Milpa\Admin\Tui\AdminSectionStates;
use Milpa\Admin\Stack\TcpProbe;
use Milpa\Admin\View\AdminPage;
use Milpa\Admin\View\AdminShell;
use Milpa\Console\State\SectionStateProvider;
use Milpa\Console\State\SectionStateSource;
use Milpa\Attributes\PluginMetadata;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Live\Security\HmacCsrfGuard;
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
    version: '0.22.0', // x-release-please-version
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'Admin',
    type: 'Web',
)]
final class AdminPlugin implements PluginInterface, RouteProviderInterface, AdminSectionProvider, SectionStateSource
{
    private ?AdminSettings $settings = null;

    /** The panel's sections offered to the terminal — built at boot, so it reads the same codec the page does. */
    private ?AdminSectionStates $states = null;

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
        // The emitter declares what it dispatches, where the dispatcher enters the package (greenhouse
        // decisions/0228): a dispatcher that counts its events learns the panel's four here, before any
        // render; one that does not implement DeclaredEvents is told nothing and dispatch() works the same.
        if ($events instanceof DeclaredEvents) {
            $events->declare(...AdminEvents::declarations());
        }

        $catalog = new Catalog($settings->locale);
        $codec = new SignedXhtmlStateTransferCodec(
            new XhtmlStateTransferCodec(),
            new HmacStateSigner($settings->signingSecret()),
            null,
        );
        $renderer = new AdminHtmlRenderer($codec, $catalog, $settings);
        $projection = new ComposeProjection();
        $stack = new StackSource($this->container, new TcpProbe(), $projection, fallbackProvider: null);
        // ONE INSTANCE OF EACH SOURCE, shared by the section that owns it and by the home that
        // summarises it. Two instances would be two ways to count one thing, which is how a panel
        // ends up disagreeing with itself about how many plugins it boots.
        $pluginsSource = new PluginsSource($this->container);
        $routesSource = new RoutesSource($this->container, $this);

        $this->sections = [
            // THE SCREEN THE PANEL OPENS ON, and it is first BY DECISION rather than by arithmetic.
            // Plugins used to be the home because `order: 10` won a flat `(order, id)` sort — nobody
            // chose it — so a human who had just installed the framework met a table of PHP class
            // names (greenhouse decisions/0264). The table is not wrong; Plugins is where it belongs.
            new AdminSection(
                id: HouseComponent::SECTION,
                title: 'nav.house',
                component: HouseComponent::NAME,
                order: 5,
                group: AdminSection::GROUP_ADMIN,
                definition: new HouseComponent(new HouseSource($this->container, $settings, $pluginsSource, $routesSource)),
                renderer: $renderer,
                icon: '⌂',
            ),
            new AdminSection(
                id: 'plugins',
                title: 'nav.plugins',
                component: PluginsComponent::NAME,
                order: 10,
                group: AdminSection::GROUP_ADMIN,
                definition: new PluginsComponent($pluginsSource),
                renderer: $renderer,
                icon: '▣',
            ),
            new AdminSection(
                id: 'routes',
                title: 'nav.routes',
                component: RoutesComponent::NAME,
                order: 20,
                group: AdminSection::GROUP_ADMIN,
                definition: new RoutesComponent($routesSource),
                renderer: $renderer,
                icon: '⇢',
            ),
            new AdminSection(
                id: 'settings',
                title: 'nav.settings',
                component: SettingsComponent::NAME,
                order: 25,
                group: AdminSection::GROUP_ADMIN,
                definition: new SettingsComponent(new SettingsSource($settings)),
                renderer: $renderer,
                icon: '◎',
            ),
            new AdminSection(
                id: 'stack',
                title: 'nav.stack',
                component: StackComponent::NAME,
                order: 30,
                group: AdminSection::GROUP_ADMIN,
                definition: new StackComponent($stack),
                renderer: $renderer,
                icon: '▤',
            ),
            new AdminSection(
                id: DevToolsComponent::SECTION,
                title: 'nav.devtools',
                component: DevToolsComponent::NAME,
                order: 40,
                group: AdminSection::GROUP_ADMIN,
                definition: new DevToolsComponent(new DevToolsSource($this->container)),
                renderer: $renderer,
                icon: '◇',
            ),
        ];

        $this->states = new AdminSectionStates($this->container, $this, $codec, $settings->route, $events);

        $shell = new AdminShell($settings, $catalog, $codec, $events);
        $page = new AdminPage($settings, $catalog);
        // One key per page, one wire (greenhouse decisions/0211): the CSRF guard signs with the SAME secret
        // that signs the state envelopes, so the boot a page issues is the boot the panel's own endpoint
        // verifies — and a guest's component reaches it without a key of its own.
        $csrf = new HmacCsrfGuard($settings->signingSecret());

        $this->container->registerService(
            AdminController::class,
            new AdminController($this->container, $this, $catalog, $shell, $page, $csrf, $settings),
        );
        $this->container->registerService(
            LiveController::class,
            new LiveController($this->container, $this, $codec, $csrf, $settings, $events),
        );
        $this->container->registerService(AssetsController::class, new AssetsController());
        $this->container->registerService(StackController::class, new StackController($stack, $projection, $catalog));
        // "If absent" must ask the PSR-11 registry, not DIContainer::has(): the latter answers true for
        // any auto-wirable class, so this guard never registered the gate and every LAN request was
        // refused by an auto-wired instance carrying the DEFAULT catalog — the declared locale never
        // reached the refusal (greenhouse evidence/0522, found while the Desktop copied the pattern).
        if (!$this->container->getContainer()->has(LoopbackOnlyMiddleware::class)) {
            $this->container->registerService(LoopbackOnlyMiddleware::class, new LoopbackOnlyMiddleware($catalog));
        }
    }

    /**
     * The panel's routes, each carrying the EFFECTIVE middleware stack — the declared one when every
     * entry names a PSR-15 middleware class (an empty list included), loopback-only the moment the
     * declaration is anything else ({@see AdminSettings::effectiveMiddleware()}).
     *
     * The live wire (`POST {route}/live`) carries that same stack, deliberately: it is the door every
     * component of the page — the panel's own and every guest's — takes its actions through, and a wire
     * outside the gate would be a hole (greenhouse decisions/0211).
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        $settings = $this->settings ?? AdminSettings::fromConfig(null);
        $route = $settings->route;
        $middleware = $settings->effectiveMiddleware();

        return [
            ...$this->installerRoute($route, $middleware),
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
                path: $settings->liveUrl(),
                methods: HttpMethod::POST,
                name: 'milpa_admin_live',
                middleware: $middleware,
                handler: HandlerReference::method(LiveController::class, 'live'),
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

    /**
     * The same sections, offered to the terminal — every section in the catalogue, this plugin's and every
     * guest's (greenhouse decisions/0220).
     *
     * The panel implements this ONCE, for everyone. `milpa/console` discovers terminal state from booted
     * plugins implementing {@see SectionStateSource}; if each plugin implemented it, every plugin would
     * declare its section twice — once for the panel, once for the terminal — which is the thing 0220
     * refuses. A plugin declares an {@see AdminSection} and gains a surface it never mentioned.
     *
     * Empty before boot: there is no codec to mount with and no catalogue to read.
     *
     * @return array<string, SectionStateProvider>
     */
    public function sectionStates(): array
    {
        return $this->states?->sectionStates() ?? [];
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

    /**
     * The route the panel's own Install button posts to, when this app has an operation to install with.
     *
     * The panel listed what a house could grow and offered a button that answered 404: reaching
     * `capabilities:enable` over HTTP requires the APP to name it in `config/http.php`, and a fresh
     * app names nothing. Mounting it here keeps that opt-in untouched — nothing else becomes
     * reachable — and carries the panel's own middleware, so who may install is exactly who may look
     * (greenhouse decisions/0248).
     *
     * Absent `milpa/app-runtime` there is no such operation and no route: the panel still lists what
     * it can see and still prints the command, which is what it did before.
     *
     * @param list<class-string> $middleware
     *
     * @return list<Route>
     */
    private function installerRoute(string $route, array $middleware): array
    {
        // NAMED AS STRINGS, like the capability catalogue this section already reads: `milpa/app-runtime`
        // is a suggestion and not a dependency, so referencing the classes directly would make static
        // analysis right to complain that this package does not have them.
        $operations = 'Milpa\\AppRuntime\\Operations\\CapabilityOperations';
        $runtime = 'Milpa\\AppRuntime\\Support\\Capabilities';

        if (!class_exists($operations) || !class_exists($runtime) || !class_exists(HttpProjector::class)) {
            return [];
        }

        $operation = null;

        /** @var iterable<\Milpa\Command\Operation> $candidates */
        $candidates = (new $operations())->operations();

        foreach ($candidates as $candidate) {
            if ($candidate->name === CapabilityInstaller::OPERATION) {
                $operation = $candidate;

                break;
            }
        }

        if ($operation === null) {
            return [];
        }

        $psr17 = new Psr17Factory();
        $this->container->registerService(CapabilityInstaller::class, new CapabilityInstaller(new HttpProjector(
            [$operation],
            $this->container,
            $psr17,
            $psr17,
            // The same store the app's own projector uses, so a token minted by one ceremony is not
            // a stranger to the other. The root comes from milpa/app-runtime, which asks composer
            // rather than counting directories — this class lives in a package, so `__DIR__` would
            // answer for the package and not for the app.
            tokens: new FileConfirmTokenStore($runtime::raizDeLaApp() . '/storage/confirm-tokens.json'),
        )));

        return [
            new Route(
                path: $route . '/capabilities/enable',
                methods: HttpMethod::POST,
                name: CapabilityInstaller::ROUTE_NAME,
                middleware: $middleware,
                handler: HandlerReference::method(CapabilityInstaller::class, 'handle'),
            ),
        ];
    }
}
