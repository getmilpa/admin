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

namespace Milpa\Admin\Components;

/**
 * A section brought its own definition under a name the panel registers itself — a dashboard primitive,
 * or one of the shell's own components (`admin-sidebar`, `admin-section-header`).
 *
 * The registry overwrites silently, so letting it through would repaint every section that names that
 * component — and, for the header, replace the attribution line on every page with the guest's markup.
 * The message names the section, the name it tried to take, and the names that are the panel's.
 */
final class ReservedComponentException extends \RuntimeException
{
}
