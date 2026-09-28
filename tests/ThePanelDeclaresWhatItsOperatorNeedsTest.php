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

namespace Milpa\Admin\Tests;

use Milpa\Admin\Http\CapabilityInstaller;
use PHPUnit\Framework\TestCase;

/**
 * greenhouse decisions/0498: the panel is where a human arrives, so it brings its door and says what the
 * human who operates it must hold.
 *
 * - `milpa/auth` is REQUIRED, not suggested: a panel whose first human can only get in after a terminal step
 *   was the break B3 measured (evidence/1024). The runtime wires a capability that arrives with another.
 * - `operator_scopes` names what the runtime invites the first human with, and grows an installer by: the
 *   gate's scope, and the scope the Install button's operation declares.
 *
 * Read from the manifest that ships, never from prose.
 */
final class ThePanelDeclaresWhatItsOperatorNeedsTest extends TestCase
{
    /** The door is a dependency of the panel, not an afterthought. */
    public function testThePanelRequiresItsDoor(): void
    {
        self::assertArrayHasKey('milpa/auth', self::manifest()['require'] ?? []);
    }

    /** The operator of the panel holds the gate's scope and the Install button's. */
    public function testTheOperatorScopesAreTheGateAndTheInstallButton(): void
    {
        $capability = self::manifest()['extra']['milpa']['capability'] ?? [];

        self::assertSame(['milpa.admin', CapabilityInstaller::OPERATION], $capability['operator_scopes'] ?? null);
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__) . '/composer.json'), true);
        self::assertIsArray($manifest);

        return $manifest;
    }
}
