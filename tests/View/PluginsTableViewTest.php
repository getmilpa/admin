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

use Milpa\Admin\View\PluginsTableView;
use PHPUnit\Framework\TestCase;

/**
 * La tabla de plugins del panel.
 *
 * Server-rendered y sin JS (ADR#8): cada botón es su propio form POST con su token. Eso importa
 * justo cuando más se necesita — apagar un plugin que rompió la página es exactamente el momento
 * en que el JavaScript de esa página puede no estar corriendo.
 */
final class PluginsTableViewTest extends TestCase
{
    /** @return array<string, mixed> */
    private function plugin(string $name, bool $enabled = true, string $source = 'local'): array
    {
        return [
            'name' => $name,
            'version' => '1.0.0',
            'type' => 'Service',
            'enabled' => $enabled,
            'source' => $source,
            'installed' => true,
        ];
    }

    public function test_cada_fila_trae_su_form_con_el_token_y_la_accion_contraria_a_su_estado(): void
    {
        $html = (new PluginsTableView())->render(
            [$this->plugin('OAuthPlugin', enabled: true), $this->plugin('MailPlugin', enabled: false)],
            canInstall: false,
            available: true,
            csrf: 'token-abc',
        );

        self::assertStringContainsString('<form method="post" action="/milpa/admin/plugins"', $html);
        self::assertSame(2, substr_count($html, 'name="csrf" value="token-abc"'));
        // El activo ofrece apagarse; el inactivo, prenderse.
        self::assertStringContainsString('value="disable"', $html);
        self::assertStringContainsString('value="enable"', $html);
        self::assertStringContainsString('Apagar', $html);
        self::assertStringContainsString('Prender', $html);
    }

    public function test_el_estado_de_cada_plugin_se_lee_en_la_fila(): void
    {
        $html = (new PluginsTableView())->render(
            [$this->plugin('OAuthPlugin', enabled: false)],
            canInstall: false,
            available: true,
            csrf: 't',
        );

        self::assertStringContainsString('<td>OAuthPlugin</td>', $html);
        self::assertStringContainsString('<td>inactivo</td>', $html);
    }

    public function test_un_plugin_declarado_en_codigo_se_nombra_como_tal_y_no_como_su_origen_crudo(): void
    {
        // "declared" no le dice nada a quien lo lee; que está en el código, sí — y explica por qué
        // no se puede quitar desde aquí.
        $html = (new PluginsTableView())->render(
            [$this->plugin('HelloPlugin', source: 'declared')],
            canInstall: false,
            available: true,
            csrf: 't',
        );

        self::assertStringContainsString('declarado en código', $html);
        self::assertStringNotContainsString('<td>declared</td>', $html);
    }

    public function test_sin_instalador_la_pagina_explica_como_se_agregan_plugins_en_este_host(): void
    {
        $html = (new PluginsTableView())->render([$this->plugin('X')], canInstall: false, available: true, csrf: 't');

        self::assertStringContainsString('se agregan', $html);
        self::assertStringNotContainsString('puede instalar plugins desde una fuente remota', $html);
    }

    public function test_con_instalador_la_pagina_lo_dice(): void
    {
        $html = (new PluginsTableView())->render([$this->plugin('X')], canInstall: true, available: true, csrf: 't');

        self::assertStringContainsString('puede instalar plugins desde una fuente remota', $html);
    }

    public function test_un_host_sin_almacen_de_activacion_lo_dice_y_no_pinta_ningun_boton(): void
    {
        $html = (new PluginsTableView())->render([], canInstall: false, available: false, csrf: 't');

        self::assertStringContainsString('no tiene almacén de activación', $html);
        self::assertStringNotContainsString('<form method="post"', $html);
    }

    public function test_un_host_sin_plugins_lo_dice_en_vez_de_pintar_una_tabla_vacia(): void
    {
        $html = (new PluginsTableView())->render([], canInstall: false, available: true, csrf: 't');

        self::assertStringContainsString('no tiene plugins registrados', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function test_el_aviso_se_pinta_arriba_cuando_hay_uno(): void
    {
        $html = (new PluginsTableView())->render(
            [$this->plugin('X')],
            canInstall: false,
            available: true,
            csrf: 't',
            notice: 'Plugin Fantasma is not installed.',
        );

        self::assertStringContainsString('Plugin Fantasma is not installed.', $html);
        self::assertLessThan(strpos($html, '<table'), strpos($html, 'Fantasma'), 'El motivo va antes de la tabla, donde se lee.');
    }

    public function test_todo_lo_que_viene_de_un_plugin_va_escapado(): void
    {
        // El nombre y la versión salen de un manifiesto que pudo escribir cualquiera: un plugin
        // instalado desde una fuente remota no debería poder inyectar HTML en el panel del host.
        $html = (new PluginsTableView())->render(
            [[
                'name' => '<script>alert(1)</script>',
                'version' => '"><img src=x>',
                'type' => 'Service',
                'enabled' => true,
                'source' => 'local',
            ]],
            canInstall: false,
            available: true,
            csrf: '"><b>',
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('value="&quot;&gt;&lt;b&gt;"', $html, 'El token también se escapa: viaja dentro de un atributo.');
    }
}
