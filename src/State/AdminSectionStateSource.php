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

namespace Milpa\Admin\State;

/**
 * El extension point opt-in que un plugin implementa para declarar el ESTADO de sus secciones — el
 * paralelo de {@see \Milpa\Admin\Section\AdminSectionProvider} (que declara las
 * secciones) para el estado. {@see AdminSectionStateDiscovery} lo descubre por `instanceof`.
 */
interface AdminSectionStateSource
{
    /**
     * El estado read-only de las secciones que este plugin aporta, keyed por id de sección.
     *
     * @return array<string, AdminSectionStateProvider>
     */
    public function adminSectionStates(): array;
}
