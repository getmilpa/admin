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

namespace Milpa\Admin\View;

use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Dashboard\DashboardMainComponent;
use Milpa\Live\Components\Dashboard\DashboardShellComponent;
use Milpa\Live\Components\Dashboard\DashboardSidebarComponent;
use Milpa\Live\Components\Dashboard\DashboardTopbarComponent;
use Milpa\Live\Rendering\DashboardHtmlRenderer;
use Milpa\Live\Rendering\XhtmlComponentCompiler;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Admin\Section\AdminSection;

/**
 * El chrome reusable del Milpa Admin (P5.7, extraído de {@see \Milpa\Admin\Http\ShellRenderHandler}):
 * compone el shell REAL de milpa/live-web (shell + sidebar + topbar) con un `$childrenHtml` ARBITRARIO
 * inyectado en `dashboard-main`, y lo envuelve con {@see AdminPage::wrap}. El body (form de settings,
 * tabla de Sistema, …) es lo único section-específico y lo decide el caller — este renderer no conoce
 * ninguna sección por nombre.
 */
final class AdminShellRenderer
{
    /**
     * El chrome del admin —navegación descubierta y topbar— alrededor del cuerpo de una sección.
     *
     * @param string             $childrenHtml    el body pre-renderizado (opaco) de la sección
     * @param list<AdminSection> $sections        las secciones descubiertas, YA ordenadas
     * @param string             $activeSectionId el id de la sección dueña de esta página
     * @param string             $brand           el nombre a pintar en el chrome (topbar + sidebar)
     * @param string             $actorId         el principal autenticado, para el ComponentContext
     */
    public function render(string $childrenHtml, array $sections, string $activeSectionId, string $brand, string $actorId): string
    {
        $codec = new XhtmlStateTransferCodec();
        $adapter = new AlpineRuntimeAdapter();
        $renderer = new DashboardHtmlRenderer($adapter, $codec);

        $components = new InMemoryComponentRegistry();
        $components->register('dashboard-shell', new DashboardShellComponent());
        $components->register('dashboard-sidebar', new DashboardSidebarComponent());
        $components->register('dashboard-main', new DashboardMainComponent());
        $components->register('dashboard-topbar', new DashboardTopbarComponent());

        $renderers = [
            'dashboard-shell' => $renderer,
            'dashboard-sidebar' => $renderer,
            'dashboard-main' => $renderer,
            'dashboard-topbar' => $renderer,
        ];

        $items = $sections === []
            ? [['key' => 'settings', 'label' => 'Settings', 'href' => '/milpa/admin/settings']]
            : array_map(static fn (AdminSection $s): array => ['key' => $s->id, 'label' => $s->title, 'href' => $s->href], $sections);

        $defaults = [
            'dashboard-topbar' => [
                'title' => $brand,
                'controls' => 'milpa-admin-sidebar',
            ],
            'dashboard-sidebar' => [
                'brand' => $brand,
                'active' => $activeSectionId,
                'items' => $items,
            ],
            'dashboard-main' => [
                'childrenHtml' => $childrenHtml,
            ],
        ];

        $markup = <<<XHTML
            <milpa:dashboard-shell id="milpa-admin" title="Milpa Admin">
                <milpa:dashboard-topbar/>
                <milpa:dashboard-sidebar id="milpa-admin-sidebar"/>
                <milpa:dashboard-main/>
            </milpa:dashboard-shell>
            XHTML;

        $compiler = new XhtmlComponentCompiler($components, $renderers, $defaults);
        $result = $compiler->compile($markup, new ComponentContext('milpa-admin', principal: $actorId, route: $this->activeRoute($sections, $activeSectionId)));

        return AdminPage::wrap($result->output, [$adapter->assets()['script'], '/vendor/alpine.min.js']);
    }

    /**
     * El `route:` del ComponentContext — el href de la sección ACTIVA si está en `$sections`, o el Hub
     * como fallback.
     *
     * @param list<AdminSection> $sections
     */
    private function activeRoute(array $sections, string $activeSectionId): string
    {
        foreach ($sections as $section) {
            if ($section->id === $activeSectionId) {
                return $section->href;
            }
        }

        return '/milpa/admin';
    }
}
