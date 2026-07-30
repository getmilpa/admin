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

use Milpa\Console\State\SectionStateProvider;

/**
 * El seam de lectura que consume el GET de configuración — nunca el store directamente, para que la
 * fuente de los valores actuales quede desacoplada de cómo se persisten. Generalizado a
 * {@see SectionStateProvider} en P5.7/P5.6: la config actual ES el `state()` de la sección Settings.
 */
interface SettingsStateProvider extends SectionStateProvider
{
    /**
     * Los valores de configuración actuales, sin el id interno.
     *
     * @return array<string,mixed>
     */
    public function state(): array;
}
