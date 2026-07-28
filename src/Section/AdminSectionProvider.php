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

namespace Milpa\Admin\Section;

/**
 * El extension point `ui.admin.section` (nombre conceptual — la verdad ejecutable es ESTA interfaz,
 * no una capability del resolver). Un plugin booteado que la implemente contribuye secciones al
 * Admin Hub, que las descubre vía instanceof — el mismo idioma que CommandProvider::operations()
 * y getToolProviderPromptSections().
 */
interface AdminSectionProvider
{
    /**
     * Las secciones que este plugin aporta al panel.
     *
     * @return list<AdminSection>
     */
    public function adminSections(): array;
}
