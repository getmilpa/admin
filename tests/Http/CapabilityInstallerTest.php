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

namespace Milpa\Admin\Tests\Http;

use Milpa\Admin\Http\CapabilityInstaller;
use PHPUnit\Framework\TestCase;

/**
 * The panel mounts what its own Install button needs, and says so in one place.
 *
 * The button posted to the app's GLOBAL operations surface, which a fresh app does not expose — so it
 * answered 404 in precisely the house it exists for, and station 4 of the ideal path did not exist
 * (greenhouse decisions/0248).
 */
final class CapabilityInstallerTest extends TestCase
{
    /**
     * The route name is the panel's own, so it can never collide with a host that exposes the same
     * operation on its global surface.
     *
     * Two routes named `capabilities:enable` would be a router conflict; two packages each mounting
     * their own HttpProjector would be worse — one set of routes resolving against an instance that
     * never heard of their operations, answering 404 with no error anywhere.
     */
    public function testThePanelsRouteCarriesItsOwnNameAndNotTheOperations(): void
    {
        self::assertSame('capabilities:enable', CapabilityInstaller::OPERATION);
        self::assertNotSame(CapabilityInstaller::OPERATION, CapabilityInstaller::ROUTE_NAME);
        self::assertStringStartsWith('milpa_admin', CapabilityInstaller::ROUTE_NAME);
    }

    /**
     * The client sends the confirmation token as a HEADER, which is where the ceremony reads it.
     *
     * It was sent in the body, so a second POST looked like a first one and answered 428 with a fresh
     * token — forever. Nobody could see it, because the endpoint answered 404 before this step was
     * ever reached. Asserted on the shipped source: this package has no JS bundle to load and run.
     */
    public function testTheConfirmationTokenIsSentAsAHeaderAndNotInTheBody(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Rendering/AdminHtmlRenderer.php');

        self::assertStringContainsString("'Confirm-Token': token", $source);
        self::assertStringNotContainsString('confirm_token: answer.confirm_token', $source, 'the token in the body is a loop, not a confirmation');
    }

    /**
     * The button posts to the panel, not to the app's global operations surface.
     */
    public function testTheButtonPostsToThePanelsOwnEndpoint(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Rendering/AdminHtmlRenderer.php');

        self::assertStringContainsString("fetch('{ENDPOINT}'", $source);
        self::assertStringNotContainsString("fetch('/capabilities/enable'", $source);
        self::assertStringContainsString("'/capabilities/enable'", $source, 'the endpoint is still built from the panel route');
    }
}
