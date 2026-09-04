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
 * A section named a component nothing registered, and brought no definition of its own.
 *
 * The message lists what IS registered, so the fix is one edit away.
 */
final class UnknownComponentException extends \RuntimeException
{
}
