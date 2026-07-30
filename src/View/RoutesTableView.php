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

/**
 * Pinta las filas de rutas ({@see \Milpa\Console\State\RoutesStateProvider}) como una
 * tabla `mui-table` del design-system, server-rendered y read-only — cero JS, degrada perfecto sin él
 * (ADR#8). No es el `DataTableComponent` interactivo (selección/Alpine): esta sección solo muestra.
 */
final class RoutesTableView
{
    /**
     * La tabla de rutas registradas.
     *
     * @param list<array{method:string,path:string,name:string,handler:string}> $rows
     */
    public function render(array $rows): string
    {
        if ($rows === []) {
            return '<table class="mui-table mui-table--compact">'
                . '<thead><tr><th>Método</th><th>Ruta</th><th>Nombre</th><th>Handler</th></tr></thead>'
                . '<tbody><tr><td colspan="4">sin rutas registradas</td></tr></tbody>'
                . '</table>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>'
                . '<td>' . $this->cell($row['method']) . '</td>'
                . '<td>' . $this->cell($row['path']) . '</td>'
                . '<td>' . $this->cell($row['name']) . '</td>'
                . '<td>' . $this->cell($row['handler']) . '</td>'
                . '</tr>';
        }

        return '<table class="mui-table mui-table--compact">'
            . '<thead><tr><th>Método</th><th>Ruta</th><th>Nombre</th><th>Handler</th></tr></thead>'
            . '<tbody>' . $body . '</tbody>'
            . '</table>';
    }

    private function cell(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
    }
}
