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

namespace Milpa\Admin\Components;

use Milpa\Admin\Rendering\ShellHtmlRenderer;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Dashboard\DashboardActionButtonComponent;
use Milpa\Live\Components\Dashboard\DashboardAlertListComponent;
use Milpa\Live\Components\Dashboard\DashboardGridComponent;
use Milpa\Live\Components\Dashboard\DashboardMainComponent;
use Milpa\Live\Components\Dashboard\DashboardPageHeaderComponent;
use Milpa\Live\Components\Dashboard\DashboardPanelComponent;
use Milpa\Live\Components\Dashboard\DashboardShellComponent;
use Milpa\Live\Components\Dashboard\DashboardSidebarComponent;
use Milpa\Live\Components\Dashboard\DashboardTopbarComponent;
use Milpa\Live\Components\Dashboard\DataTableComponent;
use Milpa\Live\Components\Dashboard\MetricCardComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Runtime\ComponentNameConflictException;
use Milpa\Live\Runtime\CompositeComponentRegistry;
use Milpa\Live\Runtime\InMemoryComponentRegistry;

/**
 * The components the panel can compose with: the dashboard primitives of `milpa/live`, the shell's own
 * two (`admin-sidebar`, `admin-section-header` — painted by {@see ShellHtmlRenderer}), plus every custom
 * component the sections bring — one or a whole {@see \Milpa\Admin\Section\DeclaredView} of them.
 *
 * Built fresh per request — the registry of `milpa/live` is a plain map with no discovery, and the
 * sections are discovered per request too, so the book follows them. A section that names a component
 * nobody registered fails here, with the list of what exists.
 *
 * **One registry, in layers** (greenhouse decisions/0211). The book is a
 * {@see CompositeComponentRegistry}: the panel's own layer first, then ONE layer per section that brought
 * components, labelled with the section's id. That is what lets a single live endpoint serve the host's
 * components and every guest's, and what makes shadowing loud instead of silent: two sections binding one
 * name to DIFFERENT definitions throw {@see ComponentNameConflictException} naming the component and both
 * sections, exactly as two plugins declaring one section id throw
 * {@see \Milpa\Admin\Section\SectionConflictException}. The rule is `milpa/live`'s own — identity or a
 * stateless class — not a second one invented here: two sections that reuse the SAME instance, or two
 * instances of a class with no state, are one definition and no conflict.
 *
 * The RENDERER is held to the same rule, by the book itself ({@see RendererConflictException}). Two
 * sections that legitimately share one definition may still each bring their own renderer, and the
 * renderer registry resolves the last one registered: without this the first section's surface would be
 * painted by the second's renderer, silently, in the one place whose whole point is that a collision is
 * loud. Sharing the renderer instance (or a stateless class) is agreement; anything else names both.
 *
 * The names the book registers itself — the primitives and the shell's own two — are the host's: a section
 * may NAME one (a `metric-card` section is the normal case) but never bring its own definition under it.
 * A layer of its own would not save it: the panel's layer resolves first, so the guest's definition would
 * be dead weight the endpoint could never reach; the book refuses instead
 * ({@see ReservedComponentException}), which is what lets the header say a section never names its own
 * declarer.
 */
final class ComponentBook
{
    /** How the panel's own layer is named when a conflict has to be reported. */
    public const HOST_LAYER = 'the panel';

    /** @var array<string, class-string> */
    private const PRIMITIVES = [
        'dashboard-shell' => DashboardShellComponent::class,
        'dashboard-sidebar' => DashboardSidebarComponent::class,
        'dashboard-main' => DashboardMainComponent::class,
        'dashboard-topbar' => DashboardTopbarComponent::class,
        'dashboard-grid' => DashboardGridComponent::class,
        'dashboard-panel' => DashboardPanelComponent::class,
        'dashboard-page-header' => DashboardPageHeaderComponent::class,
        'dashboard-action-button' => DashboardActionButtonComponent::class,
        'dashboard-alert-list' => DashboardAlertListComponent::class,
        'metric-card' => MetricCardComponent::class,
        'data-table' => DataTableComponent::class,
    ];

    private readonly InMemoryComponentRegistry $host;

    /** @var array<string, InMemoryComponentRegistry> section id → what that section brought */
    private array $guests = [];

    private readonly ComponentRendererRegistry $renderers;

    /** @var array<string, ComponentRendererInterface> component name → renderer, in registration order */
    private array $byName = [];

    /** @var array<string, string> component name → the layer whose renderer paints it, so a clash can name both */
    private array $painterOf = [];

    /**
     * The names the book registered itself — the host's, which a section may name but never redefine.
     *
     * @var list<string>
     */
    private readonly array $reserved;

    private ?CompositeComponentRegistry $composite = null;

    public function __construct(StateTransferCodecInterface $codec, ?MilpaEventDispatcherInterface $events = null)
    {
        $this->host = new InMemoryComponentRegistry();
        $this->renderers = new ComponentRendererRegistry();
        $dashboard = new DashboardHtmlRenderer(new AlpineRuntimeAdapter(), $codec, null, $events);

        foreach (self::PRIMITIVES as $name => $class) {
            $this->register($name, new $class($events), $dashboard);
        }

        $shell = new ShellHtmlRenderer($codec);
        $this->register(SidebarComponent::NAME, new SidebarComponent(), $shell);
        $this->register(SectionHeaderComponent::NAME, new SectionHeaderComponent(), $shell);
        $this->reserved = array_keys($this->byName);
    }

    /**
     * A book that has already adopted every section of a catalogue — what a page compiles with and what
     * the live wire re-renders from, built the same way on both sides so an envelope signed by one is
     * resolvable by the other.
     *
     * @throws UnknownComponentException      when a section names a component nothing registered
     * @throws ReservedComponentException     when a section redefines one of the panel's own
     * @throws ComponentNameConflictException when two sections bind one name to different definitions
     * @throws RendererConflictException      when two sections bring different renderers for one name
     */
    public static function forSections(SectionCatalogue $catalogue, StateTransferCodecInterface $codec, ?MilpaEventDispatcherInterface $events = null): self
    {
        $book = new self($codec, $events);
        foreach ($catalogue->sections() as $section) {
            $book->adopt($section);
        }

        return $book;
    }

    /** Registers one component under a name, with the renderer that paints it — the book's one way in. */
    public function register(string $name, ComponentDefinitionInterface $definition, ComponentRendererInterface $renderer): void
    {
        $this->host->register($name, $definition);
        $this->composite = null;
        $this->remember($name, $renderer, self::HOST_LAYER);
    }

    /**
     * Makes a section renderable: registers everything it brings — one component or the whole tree of a
     * declared view — under its own layer, or checks that the one it names exists.
     *
     * @throws UnknownComponentException      when the section names a component nothing registered
     * @throws ReservedComponentException     when the section brings its own definition under a name the book
     *                                        registered itself — a primitive, or one of the shell's own
     * @throws ComponentNameConflictException when another section already bound one of those names to a
     *                                        different definition
     * @throws RendererConflictException      when another section already bound one of those names to a
     *                                        different renderer
     */
    public function adopt(AdminSection $section): void
    {
        if ($section->view !== null) {
            $this->adoptLayer($section, $section->view->definitions, $section->view->renderers);

            return;
        }

        if ($section->definition !== null && $section->renderer !== null) {
            $this->adoptLayer($section, [$section->component => $section->definition], [$section->component => $section->renderer]);

            return;
        }

        if (!$this->registry()->has($section->component)) {
            throw new UnknownComponentException(\sprintf(
                'Admin section «%s» names component «%s», which nothing registered. Bring a definition and a renderer with the section, or name one of: %s.',
                $section->id,
                $section->component,
                implode(', ', $this->names()),
            ));
        }
    }

    /** The registry the compiler and the live endpoint resolve component names against — every layer. */
    public function registry(): ComponentRegistryInterface
    {
        return $this->composite();
    }

    /** The renderers, answering per component name — the pair of the composite registry. */
    public function renderers(): ComponentRendererRegistry
    {
        return $this->renderers;
    }

    /**
     * The names the book can render — the primitives, then the shell's own, then the sections' — in registration order, each once.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->byName));
    }

    /**
     * A compiler over this book, with default props per component name.
     *
     * @param array<string, array<string, mixed>> $defaults component name → props merged under the markup's attributes
     */
    public function compiler(array $defaults = []): XhtmlComponentCompiler
    {
        return new XhtmlComponentCompiler($this->composite(), $this->renderers, $defaults);
    }

    /**
     * Registers everything a section brought under a layer of its own, after refusing any name the panel
     * registers itself. The composite is rebuilt at once, so a clash with another section is reported HERE
     * — naming both sections — instead of at the first render.
     *
     * A name two sections agree on as a DEFINITION (the same instance, a stateless class) but bring
     * different RENDERERS for is a {@see RendererConflictException} naming both — the collision
     * `milpa/live`'s definition rule deliberately lets through, refused here rather than resolved by
     * registration order. Either refusal leaves the book as it was: the layer is rolled back.
     *
     * @param array<string, ComponentDefinitionInterface> $definitions
     * @param array<string, ComponentRendererInterface>   $renderers
     */
    private function adoptLayer(AdminSection $section, array $definitions, array $renderers): void
    {
        foreach (array_keys($definitions) as $name) {
            if (\in_array($name, $this->reserved, true)) {
                throw new ReservedComponentException(\sprintf(
                    'Admin section «%s» brings its own definition under «%s», a component the panel registers itself. A section may name a registered component; it may not redefine one — pick a name of your own. The panel\'s are: %s.',
                    $section->id,
                    $name,
                    implode(', ', $this->reserved),
                ));
            }
        }

        $layer = new InMemoryComponentRegistry();
        foreach ($definitions as $name => $definition) {
            $layer->register((string) $name, $definition);
        }
        $this->guests[self::layerOf($section)] = $layer;
        $this->composite = null;

        try {
            $this->composite();
        } catch (ComponentNameConflictException $conflict) {
            unset($this->guests[self::layerOf($section)]);
            $this->composite = null;

            throw $conflict;
        }

        try {
            foreach ($renderers as $name => $renderer) {
                $this->remember((string) $name, $renderer, self::layerOf($section));
            }
        } catch (RendererConflictException $conflict) {
            unset($this->guests[self::layerOf($section)]);
            $this->composite = null;

            throw $conflict;
        }
    }

    /** The composite over the panel's layer and every section's, built once per adoption. */
    private function composite(): CompositeComponentRegistry
    {
        return $this->composite ??= new CompositeComponentRegistry(
            [self::HOST_LAYER => $this->host, ...$this->guests],
            writable: self::HOST_LAYER,
        );
    }

    /** How a section's layer is named in a conflict — the section, never the plugin: the panel names no plugin. */
    private static function layerOf(AdminSection $section): string
    {
        return 'section «' . $section->id . '»';
    }

    /**
     * Binds the renderer that paints `$name`, refusing a second, different one.
     *
     * `ComponentRendererRegistry::registerFor()` PREPENDS — the last registration wins — so without this
     * the second section to bring a renderer for a shared name would silently repaint the first's surface.
     *
     * @throws RendererConflictException when another layer already bound this name to a different renderer
     */
    private function remember(string $name, ComponentRendererInterface $renderer, string $layer): void
    {
        $painter = $this->byName[$name] ?? null;
        if ($painter !== null && !self::sameRenderer($painter, $renderer)) {
            throw new RendererConflictException($name, $this->painterOf[$name] ?? self::HOST_LAYER, $layer);
        }
        $this->byName[$name] = $renderer;
        $this->painterOf[$name] = $this->painterOf[$name] ?? $layer;
        $this->renderers->registerFor($name, $renderer);
    }

    /**
     * `milpa/live`'s rule for definitions, said for renderers: the same instance, or two instances of one
     * STATELESS class. Never a structural compare — a renderer holds a codec and a dispatcher, and `==`
     * over a collaborator that points back would be an uncatchable fatal instead of a named exception.
     */
    private static function sameRenderer(ComponentRendererInterface $a, ComponentRendererInterface $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return $a::class === $b::class && self::isStateless($a);
    }

    /** True when the instance carries no state of its own: no instance property anywhere in its class chain. */
    private static function isStateless(object $renderer): bool
    {
        for ($class = new \ReflectionObject($renderer); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if (!$property->isStatic()) {
                    return false;
                }
            }
        }

        return true;
    }
}
