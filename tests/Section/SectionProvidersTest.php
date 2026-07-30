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

namespace Milpa\Admin\Tests\Section;

use Milpa\Container\DIContainer;
use Milpa\Admin\AdminPlugin;
use Milpa\Console\Section\Section;
use Milpa\Console\Section\SectionDiscovery;
use Milpa\Console\Section\SectionProvider;
use Milpa\Admin\Tests\Fixtures\NeighbourPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Los providers REALES de `ui.admin.section` (ADR#12): MilpaAdminPlugin aporta `settings` (order 10)
 * y `system` (order 30, P5.7 — la tabla read-only de rutas de {@see \Milpa\Admin\Controllers\SystemController}),
 * y NeighbourPlugin — un plugin DISTINTO — aporta `architecture` (order 20). La prueba de que el
 * extension point funciona cross-plugin, no solo dentro de un mismo plugin, y de que un mismo plugin
 * puede aportar más de una sección.
 *
 * Harness: ambos constructores (`MilpaAdminPlugin::__construct`, `NeighbourPlugin::__construct`) solo
 * llaman a `parent::__construct($container)` — PluginBase se limita a guardar la referencia, sin
 * tocar el container. `sections()` tampoco toca el container. Por eso un {@see DIContainer}
 * real y vacío (cero servicios registrados) alcanza para instanciar ambos plugins de verdad, sin
 * necesitar `newInstanceWithoutConstructor()` ni boot() completo.
 */
final class SectionProvidersTest extends TestCase
{
    private function container(): DIContainer
    {
        return new DIContainer();
    }

    public function test_milpa_admin_plugin_provides_the_settings_plugins_and_system_sections(): void
    {
        $plugin = new AdminPlugin($this->container());

        self::assertInstanceOf(SectionProvider::class, $plugin);
        $sections = $plugin->sections();
        self::assertCount(3, $sections);

        self::assertSame('settings', $sections[0]->id);
        self::assertSame('/milpa/admin/settings', $sections[0]->href);
        self::assertSame(10, $sections[0]->order);

        // 15 y no 20: el 20 ya es de `architecture` (NeighbourPlugin), y un empate deja el orden de
        // la navegación a merced de en qué orden se descubrieron los plugins.
        self::assertSame('plugins', $sections[1]->id);
        self::assertSame('Plugins', $sections[1]->title);
        self::assertSame('/milpa/admin/plugins', $sections[1]->href);
        self::assertSame(15, $sections[1]->order);

        self::assertSame('system', $sections[2]->id);
        self::assertSame('Sistema', $sections[2]->title);
        self::assertSame('/milpa/admin/system', $sections[2]->href);
        self::assertSame(30, $sections[2]->order);
    }

    public function test_un_plugin_vecino_aporta_su_propia_seccion(): void
    {
        $plugin = new NeighbourPlugin($this->container());

        self::assertInstanceOf(SectionProvider::class, $plugin);
        $sections = $plugin->sections();
        self::assertCount(1, $sections);
        self::assertSame('architecture', $sections[0]->id);
        self::assertSame('/vecino/arquitectura', $sections[0]->href);
        self::assertSame(20, $sections[0]->order);
    }

    public function test_both_providers_survive_the_real_discovery(): void
    {
        $discovery = new SectionDiscovery([
            new AdminPlugin($this->container()),
            new NeighbourPlugin($this->container()),
        ]);

        $ids = array_map(static fn (Section $s): string => $s->id, $discovery->sections());
        self::assertSame(['settings', 'plugins', 'architecture', 'system'], $ids); // orden 10, 15, 20, 30

        self::assertSame('settings', $discovery->defaultSection()->id);
    }
}
