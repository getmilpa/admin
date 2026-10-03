<?php

/**
 * This file is part of milpa/admin.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Support;

/**
 * A STAND-IN FOR `milpa/app-runtime`'s `Capabilities`, UNDER ITS REAL NAME — required by hand, never autoloaded.
 *
 * The panel's suite runs without app-runtime, and the panel names that class by string. A test that
 * wants to see what the panel paints when the house DOES say how it is reached loads this file in a
 * process of its own, so no other test ever meets a `Capabilities` that is not the real one.
 *
 * It answers only what the panel asks: `cli()`. What makes a declared way in acceptable — no line
 * break, no `;`, no `$`, 200 characters — is app-runtime's judgement and is tested there; this one
 * repeats none of it.
 */
final class Capabilities
{
    /** The CLI as a person types it: `php bin/coa `, after `MILPA_CLI_PREFIX` when the serving process declared one. */
    public static function cli(): string
    {
        $prefix = getenv('MILPA_CLI_PREFIX');

        return \is_string($prefix) && $prefix !== '' ? $prefix . ' php bin/coa ' : 'php bin/coa ';
    }
}
