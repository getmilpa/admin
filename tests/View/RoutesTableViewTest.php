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

namespace Milpa\Admin\Tests\View;

use Milpa\Admin\View\RoutesTableView;
use PHPUnit\Framework\TestCase;

/**
 * `RoutesTableView` pinta las filas de rutas como una tabla `mui-table` server-rendered (read-only,
 * cero JS): cabecera + una fila por ruta con las celdas escapadas, o una fila "sin rutas" si viene vacío.
 */
final class RoutesTableViewTest extends TestCase
{
    public function test_renders_a_mui_table_with_a_row_per_route(): void
    {
        $html = (new RoutesTableView())->render([
            ['method' => 'GET', 'path' => '/milpa/admin/settings', 'name' => 'milpa_admin_settings_show', 'handler' => 'SettingsController::show'],
        ]);

        self::assertStringContainsString('<table class="mui-table mui-table--compact">', $html);
        self::assertStringContainsString('<th>Método</th>', $html);
        self::assertStringContainsString('<td>GET</td>', $html);
        self::assertStringContainsString('<td>/milpa/admin/settings</td>', $html);
        self::assertStringContainsString('<td>SettingsController::show</td>', $html);
    }

    public function test_escapes_cell_content(): void
    {
        $html = (new RoutesTableView())->render([
            ['method' => 'GET', 'path' => '/x?a=<b>&c', 'name' => '-', 'handler' => 'C::a'],
        ]);

        self::assertStringContainsString('/x?a=&lt;b&gt;&amp;c', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function test_empty_rows_render_a_no_routes_message_not_a_mute_table(): void
    {
        $html = (new RoutesTableView())->render([]);

        self::assertStringContainsString('sin rutas registradas', $html);
    }
}
