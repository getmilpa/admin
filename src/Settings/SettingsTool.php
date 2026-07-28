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

namespace Milpa\Admin\Settings;

use Milpa\ToolRuntime\Attributes\Param;
use Milpa\ToolRuntime\Attributes\Tool;

/**
 * La operación de settings del framework, declarada como Tool: su inputSchema lo deriva
 * ToolScanner a partir de los atributos #[Tool]/#[Param] de abajo — nunca a mano (ADR#7: nunca
 * fabricar un contrato temporal cuando el definitivo existe). En P5.3a el host invoca update() de
 * forma ceremonial tras el bind del form; en P5.4 el ToolProjector genérico dispatcha esta misma
 * operación sin que el form cambie.
 */
final class SettingsTool
{
    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    /**
     * Persiste la configuración de Milpa Admin (nombre del sitio, mantenimiento, tema).
     */
    #[Tool(name: 'settings_update', description: 'Actualiza la configuración de Milpa Admin.')]
    public function update(
        #[Param(description: 'Nombre del sitio', required: true)]
        string $siteName,
        #[Param(description: 'Modo mantenimiento')]
        bool $maintenance = false,
        #[Param(description: 'Tema', enum: ['light', 'dark'])]
        string $theme = 'light',
    ): void {
        $this->repository->save(new SettingsEntity($siteName, $maintenance, $theme));
    }
}
