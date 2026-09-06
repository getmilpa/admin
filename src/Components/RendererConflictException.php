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
 * Two layers bind one component name to DIFFERENT renderers, and only one of them can paint it
 * (greenhouse decisions/0211).
 *
 * The other half of `milpa/live`'s {@see \Milpa\Live\Runtime\ComponentNameConflictException}. That one
 * catches two sections binding a name to different DEFINITIONS; this one catches the case it deliberately
 * lets through — two sections that agree on the definition (the same instance, or two instances of a
 * stateless class: one definition, no conflict) while each brings its own RENDERER. The registry would
 * resolve the last one registered and the first section's surface would be painted by somebody else's
 * renderer, silently, on a page whose whole point is that collisions are loud.
 *
 * The rule for «different» is `milpa/live`'s, said for renderers: the same instance is one renderer, and
 * so are two instances of a class with no state of its own; anything else is two. Never a structural
 * compare — a renderer holds a codec and a dispatcher, and `==` over those is a fatal, not a verdict.
 *
 * Thrown while the panel adopts a section ({@see ComponentBook}), naming the component and both sections,
 * so the reader learns it before the first render. The sibling of {@see ReservedComponentException} (a
 * guest redefining one of the panel's own) and of
 * {@see \Milpa\Admin\Section\SeedConflictException} (two declarers, one signal).
 */
final class RendererConflictException extends \LogicException
{
    public function __construct(
        public readonly string $component,
        public readonly string $firstLayer,
        public readonly string $secondLayer,
    ) {
        parent::__construct(\sprintf(
            'Component «%s» is painted by different renderers in %s and %s; the panel resolves one renderer per name and never lets one shadow the other. Share the renderer instance, or give the component a name of your own.',
            $component,
            $firstLayer,
            $secondLayer,
        ));
    }
}
