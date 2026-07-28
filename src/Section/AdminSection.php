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
 * Una sección del Milpa Admin — la unidad que un plugin contribuye al Hub (ADR#12). El contrato es
 * deliberadamente mínimo: solo lo que los consumidores ACTUALES leen (el sidebar renderiza
 * id/title/href; el discovery ordena por order). Campos futuros (mode/icon/scopes) entran cuando
 * algo los muerda, no antes.
 */
final readonly class AdminSection
{
    public function __construct(
        public string $id,      // gramática: ^[a-z][a-z0-9.-]*$
        public string $title,
        public string $href,    // path LOCAL ABSOLUTO (/milpa/admin/settings, /agency/architecture)
        public int $order = 0,
    ) {
    }
}
