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

use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolScanner;
use Psr\Log\LoggerInterface;

/**
 * El registry REAL de settings (P5.4): logger PSR-3 del host (el audit de `call()` cuelga de ese
 * logger — con NullLogger iría a /dev/null) + `SettingsTool` escaneado. Un registry por request
 * sirve AMBOS: `getDefinition()` (el schema del form) y `call()` (el dispatch gobernado) —
 * colapsa el split throwaway-para-schema / new-directo-para-dispatch de P5.3a.
 */
final class SettingsToolRegistry
{
    /** El registro con la única herramienta de configuración ya dada de alta. */
    public static function create(LoggerInterface $logger): ToolRegistry
    {
        $registry = new ToolRegistry($logger);
        (new ToolScanner($registry))->scan(new SettingsTool(SettingsStore::repository()));

        return $registry;
    }
}
