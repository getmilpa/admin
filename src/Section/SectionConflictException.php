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
 * Two plugins declared the same section id, or a provider returned something that is not a section.
 *
 * Loud on purpose: a silent "last one wins" would hide one plugin's section behind another's, and the
 * human would never learn which.
 */
final class SectionConflictException extends \RuntimeException
{
}
