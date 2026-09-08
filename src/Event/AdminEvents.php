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

namespace Milpa\Admin\Event;

use Milpa\Admin\View\AdminShell;
use Milpa\Interfaces\Event\EventDeclaration;

/**
 * Every event this package dispatches, declared — the panel's one answer to «what events exist?»
 * (greenhouse decisions/0228).
 *
 * The declarations are not written here: each surface that calls `dispatch()` builds its own from the
 * constants those calls use ({@see AdminShell::events()}), and this class only gathers them, so the list a
 * dispatcher learns at boot is the list the code fires. {@see \Milpa\Admin\AdminPlugin::boot()} hands them
 * to the dispatcher it is given when that dispatcher implements
 * {@see \Milpa\Interfaces\Event\DeclaredEvents}; a dispatcher that does not is told nothing, and
 * dispatching keeps working either way — declaring is a description, never a gate.
 */
final class AdminEvents
{
    /**
     * One declaration per event name this package dispatches, in the order a render fires them.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [...AdminShell::events()];
    }
}
