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
 * La tabla de plugins del panel: una fila por plugin y un botón que lo prende o lo apaga.
 *
 * Server-rendered y sin JS, igual que el resto del admin (ADR#8): cada botón es su propio form
 * POST con su token CSRF. Un checkbox con `onchange` se vería mejor y dejaría de funcionar sin
 * JavaScript, que es justo cuando alguien necesita apagar un plugin que rompió la página.
 */
final readonly class PluginsTableView
{
    /**
     * La tabla de plugins, con su botón de prender/apagar por fila.
     *
     * @param list<array<string, mixed>> $plugins
     */
    public function render(array $plugins, bool $canInstall, bool $available, string $csrf, ?string $notice = null): string
    {
        $html = '';

        if ($notice !== null) {
            $html .= '<p class="mui-banner mui-banner--info">' . $this->text($notice) . '</p>';
        }

        if (!$available) {
            return $html . '<p class="mui-banner mui-banner--warn">Este host no tiene almacén de activación, '
                . 'así que no hay plugins que administrar desde aquí.</p>';
        }

        $html .= $plugins === []
            ? '<p>Este host no tiene plugins registrados.</p>'
            : $this->table($plugins, $csrf);

        // Instalar no aparece por defecto: existe sólo si el host cableó un instalador. Pintarlo
        // igual sería ofrecer algo que no tiene a quién llamar.
        $html .= $canInstall
            ? '<p class="mui-hint">Este host puede instalar plugins desde una fuente remota.</p>'
            : '<p class="mui-hint">Este host no tiene instalador cableado: los plugins se agregan '
                . 'por código y aquí se prenden o se apagan.</p>';

        return $html;
    }

    /**
     * @param list<array<string, mixed>> $plugins
     */
    private function table(array $plugins, string $csrf): string
    {
        $rows = '';
        foreach ($plugins as $plugin) {
            $name = $this->str($plugin, 'name');
            $enabled = ($plugin['enabled'] ?? false) === true;
            $declared = $this->str($plugin, 'source') === 'declared';

            $rows .= '<tr>'
                . '<td>' . $this->text($name) . '</td>'
                . '<td>' . $this->text($this->str($plugin, 'version')) . '</td>'
                . '<td>' . $this->text($this->str($plugin, 'type')) . '</td>'
                . '<td>' . $this->text($declared ? 'declarado en código' : $this->str($plugin, 'source')) . '</td>'
                . '<td>' . ($enabled ? 'activo' : 'inactivo') . '</td>'
                . '<td>' . $this->toggle($name, $enabled, $csrf) . '</td>'
                . '</tr>';
        }

        return '<table class="mui-table mui-table--compact">'
            . '<thead><tr><th>Plugin</th><th>Versión</th><th>Tipo</th><th>Origen</th><th>Estado</th><th></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }

    private function toggle(string $name, bool $enabled, string $csrf): string
    {
        $action = $enabled ? 'disable' : 'enable';
        $label = $enabled ? 'Apagar' : 'Prender';

        return '<form method="post" action="/milpa/admin/plugins" class="mui-inline-form">'
            . '<input type="hidden" name="csrf" value="' . $this->text($csrf) . '">'
            . '<input type="hidden" name="name" value="' . $this->text($name) . '">'
            . '<input type="hidden" name="action" value="' . $action . '">'
            . '<button type="submit" class="mui-button mui-button--small">' . $label . '</button>'
            . '</form>';
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    private function text(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
    }
}
