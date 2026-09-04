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

use Milpa\Admin\Section\AdminSection;
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
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Runtime\InMemoryComponentRegistry;

/**
 * The components the panel can compose with: the dashboard primitives of `milpa/live`, plus every
 * custom component the sections bring.
 *
 * Built fresh per request — the registry of `milpa/live` is a plain map with no discovery, and the
 * sections are discovered per request too, so the book follows them. A section that names a component
 * nobody registered fails here, with the list of what exists.
 */
final class ComponentBook
{
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

    private readonly InMemoryComponentRegistry $registry;

    /** @var array<string, ComponentRendererInterface> */
    private array $renderers = [];

    public function __construct(StateTransferCodecInterface $codec, ?MilpaEventDispatcherInterface $events = null)
    {
        $this->registry = new InMemoryComponentRegistry();
        $dashboard = new DashboardHtmlRenderer(new AlpineRuntimeAdapter(), $codec, null, $events);

        foreach (self::PRIMITIVES as $name => $class) {
            $this->registry->register($name, new $class($events));
            $this->renderers[$name] = $dashboard;
        }
    }

    /**
     * Makes a section renderable: registers the component it brings, or checks that the one it names exists.
     *
     * @throws UnknownComponentException when the section names a component nothing registered
     */
    public function adopt(AdminSection $section): void
    {
        if ($section->definition !== null && $section->renderer !== null) {
            $this->registry->register($section->component, $section->definition);
            $this->renderers[$section->component] = $section->renderer;

            return;
        }

        if (!$this->registry->has($section->component)) {
            throw new UnknownComponentException(\sprintf(
                'Admin section «%s» names component «%s», which nothing registered. Bring a definition and a renderer with the section, or name one of: %s.',
                $section->id,
                $section->component,
                implode(', ', $this->names()),
            ));
        }
    }

    /** The registry the compiler resolves component names against. */
    public function registry(): ComponentRegistryInterface
    {
        return $this->registry;
    }

    /**
     * The names the book can render, primitives first, in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->renderers);
    }

    /**
     * A compiler over this book, with default props per component name.
     *
     * @param array<string, array<string, mixed>> $defaults component name → props merged under the markup's attributes
     */
    public function compiler(array $defaults = []): XhtmlComponentCompiler
    {
        return new XhtmlComponentCompiler($this->registry, $this->renderers, $defaults);
    }
}
