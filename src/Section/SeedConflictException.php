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
 * Two declarers seed one signal with different values, and the page emits each seed tag once
 * (greenhouse decisions/0211).
 *
 * Thrown while the shell merges what the panel seeds with what the active section's
 * {@see DeclaredView} seeds ({@see \Milpa\Admin\View\LiveSeeds}), so the panel says which two disagree and
 * about what, instead of letting section order decide which value the runtime starts with. The sibling of
 * {@see SectionConflictException} (two plugins, one section id) and of `milpa/live`'s
 * `ComponentNameConflictException` (two layers, one component name): every collision the panel can see is
 * named, never resolved in silence.
 */
final class SeedConflictException extends \RuntimeException
{
}
