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

use Milpa\Live\ValueObjects\ClientAssets;

/**
 * One composed page of the panel: the shell's HTML, plus everything the document around it must emit for
 * that HTML to come alive — the client files every rendered component DECLARED, and the signal seeds the
 * host and the active section's view agreed on (greenhouse decisions/0211).
 *
 * The shell composes; {@see AdminPage} wraps. This is what travels between them, so the page never has to
 * guess which stylesheets and modules a guest needed and the shell never has to write a `<script>` tag.
 */
final readonly class ShellOutput
{
    public function __construct(
        public string $html,
        public ClientAssets $assets,
        public LiveSeeds $seeds,
    ) {
    }
}
