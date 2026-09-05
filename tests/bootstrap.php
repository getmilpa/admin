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

require __DIR__ . '/../vendor/autoload.php';

// The suite names app-runtime's passkey gate without installing app-runtime: when the real class is not
// there, its name is bound to the fixture that has its shape, so `admin.middleware` resolves it the way
// an app that has the package does. With the package installed, the real class is the one that runs.
if (!class_exists(\Milpa\Admin\AdminSettings::PASSKEY_GATE)) {
    class_alias(\Milpa\Admin\Tests\Fixtures\PasskeyGateStub::class, \Milpa\Admin\AdminSettings::PASSKEY_GATE);
}
