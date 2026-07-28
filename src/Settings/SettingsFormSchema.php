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

use Milpa\Live\Schema\FormDefinition;
use Milpa\Live\Schema\SchemaForm;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolScanner;
use Psr\Log\NullLogger;

/**
 * El único punto que arma el `FormDefinition` de settings — el GET (Tarea 6) y el POST (Tarea 7) llaman
 * a {@see self::definition()} para garantizar que ambos formen el MISMO form desde el MISMO schema
 * (ADR#7: el schema sale siempre del `#[Tool]` vía `ToolScanner`, nunca fabricado a mano).
 *
 * Desde P5.4 acepta un `ToolRegistry` opcional: sin argumento (BC del GET) sigue armando su propio
 * `ToolRegistry`/`NullLogger` desechables SOLO para escanear los atributos `#[Tool]`/`#[Param]` de
 * {@see SettingsTool} — ese registry nunca ejecuta `settings_update`, así que un logger nulo es
 * correcto: no hay nada que loggear en un escaneo puro de reflexión. Con un registry dado (el REAL
 * de {@see SettingsToolRegistry}, logger PSR-3 del host), reusa ESE registry — el mismo que sirve
 * `call()` — en vez de escanear dos veces.
 */
final class SettingsFormSchema
{
    /** El id de `FormDefinition` — el mismo en GET y POST. */
    public const OPERATION_ID = 'settings:update';

    private const TOOL_NAME = 'settings_update';

    /**
     * El `FormDefinition` de settings, escaneado del `#[Tool]` real — nunca fabricado a mano.
     *
     * Sin argumento: arma el throwaway scan-only de siempre (BC — el GET actual sigue llamándolo
     * así). Con `$registry`: usa el registry REAL de P5.4 (el mismo que sirve `call()`).
     */
    public static function definition(?ToolRegistry $registry = null): FormDefinition
    {
        $registry ??= self::throwawayRegistry();

        $tool = $registry->getDefinition(self::TOOL_NAME);
        if ($tool === null) {
            // No debería ocurrir nunca: SettingsTool::update() siempre declara
            // #[Tool(name: 'settings_update')]. Un throw explícito (no un fallback silencioso a un
            // schema vacío) para que un cambio de nombre del Tool rompa ruidosamente en el primer
            // request, no en un form vacío silencioso.
            throw new \RuntimeException('settings_update tool definition not found after scan.');
        }

        return (new SchemaForm())->fromSchema(self::OPERATION_ID, $tool->inputSchema);
    }

    /** El registry desechable de siempre (`NullLogger`, escaneo puro) — solo para el caso sin argumento. */
    private static function throwawayRegistry(): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        (new ToolScanner($registry))->scan(new SettingsTool(SettingsStore::repository()));

        return $registry;
    }
}
