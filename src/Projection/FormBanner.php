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

namespace Milpa\Admin\Projection;

/**
 * El aviso form-level tipado (Ajuste 2 del spec): la semántica de `meta['code']` viaja
 * estructurada hasta el render — el código original nunca se pierde, el mensaje ya es seguro
 * (jamás `$result->error` crudo; ver ToolBannerMapper).
 */
final readonly class FormBanner
{
    public function __construct(
        public string $code,
        public BannerTone $tone,
        public string $message,
    ) {
    }
}
