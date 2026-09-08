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

use PHPUnit\Framework\TestCase;

/**
 * The falsifier of greenhouse evidence/0568: a capability is discovered BY WHAT IT IS, and the registry
 * listing every Milpa app reads is `packagist.org/packages/list.json?type=milpa-capability`. A package that
 * announces a full `extra.milpa.capability` contract while publishing `"type": "library"` is invisible to
 * that listing — the marketplace index measured on the live registry saw nine of thirteen capability
 * packages, and this panel was one of the four it could not see.
 *
 * Measured on the manifest that ships, never on prose: the test reads this package's OWN `composer.json`
 * from disk and asserts BOTH HALVES TOGETHER — the capability declaration and the type that publishes it —
 * so a future edit that drops either one goes red here.
 */
final class TheAnnouncedCapabilityDeclaresTheTypeThatMakesItDiscoverableTest extends TestCase
{
    /**
     * Both halves in one assertion: announcing a capability and publishing the type the registry indexes are
     * one fact, and a manifest that keeps one without the other is the invisibility 0568 measured.
     */
    public function testTheManifestAnnouncesACapabilityAndPublishesTheTypeTheRegistryIndexes(): void
    {
        $manifest = self::manifest();

        $capability = $manifest['extra']['milpa']['capability'] ?? null;
        self::assertIsArray($capability, 'the manifest announces extra.milpa.capability');
        self::assertNotSame([], $capability, 'the announced capability is not empty');

        self::assertSame(
            'milpa-capability',
            $manifest['type'] ?? null,
            'a package announcing extra.milpa.capability publishes "type": "milpa-capability", or the registry listing '
            . 'at packagist.org/packages/list.json?type=milpa-capability cannot see it (greenhouse evidence/0568)',
        );
    }

    /** The announced contract carries the identity the index is built from, so being visible is being useful. */
    public function testTheAnnouncedCapabilityCarriesTheIdentityTheIndexIsBuiltFrom(): void
    {
        $capability = self::manifest()['extra']['milpa']['capability'] ?? null;
        self::assertIsArray($capability);

        foreach (['id', 'title', 'briefing'] as $key) {
            self::assertArrayHasKey($key, $capability, sprintf('the announced capability carries «%s»', $key));
            self::assertIsString($capability[$key]);
            self::assertNotSame('', trim((string) $capability[$key]), sprintf('«%s» is not blank', $key));
        }

        self::assertSame('admin', $capability['id'], 'this package announces the admin capability');
    }

    /**
     * The package's own manifest, read from disk — not from a fixture and not from Composer's runtime API,
     * so the file that ships is the file measured.
     *
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $path = \dirname(__DIR__) . '/composer.json';
        self::assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }
}
