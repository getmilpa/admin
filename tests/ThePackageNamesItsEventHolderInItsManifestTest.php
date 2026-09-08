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

use Milpa\Admin\Event\AdminEvents;
use Milpa\Interfaces\Event\DeclaresEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * The falsifier of greenhouse decisions/0228, second slice: this package NAMES its event holder in its own
 * manifest, so a host can read the four names out of `vendor/composer/installed.json` without constructing
 * the shell that dispatches them.
 *
 * Measured on the manifest, never on prose: the test reads `composer.json` from disk, resolves the class the
 * manifest names, and calls `declarations()` on THAT class name. A renamed holder, a typo'd namespace or a
 * class that is not a holder therefore goes red here — the manifest is the thing under test, not the code
 * that happens to sit beside it.
 */
final class ThePackageNamesItsEventHolderInItsManifestTest extends TestCase
{
    /** The manifest lists exactly this package's holder — the same class the plugin declares from at boot. */
    public function testTheManifestNamesTheHolderThisPackageDeclaresFrom(): void
    {
        $named = self::manifestEventHolders();

        self::assertSame([AdminEvents::class], $named, 'extra.milpa.events names exactly this package\'s holder');
    }

    /** Every class the manifest names exists and is a holder, so a host resolving it finds a list, not a fatal. */
    public function testEveryNamedClassExistsAndIsAHolder(): void
    {
        $named = self::manifestEventHolders();
        self::assertNotSame([], $named, 'the manifest names at least one holder');

        foreach ($named as $class) {
            self::assertTrue(class_exists($class), sprintf('«%s» is named in extra.milpa.events but no such class exists', $class));
            self::assertTrue(is_a($class, DeclaresEvents::class, true), sprintf('«%s» is named in extra.milpa.events but does not implement %s', $class, DeclaresEvents::class));
        }
    }

    /**
     * Reading the manifest answers the same question as constructing the emitter: the class NAMED IN THE
     * MANIFEST returns the same event names the holder does — measured by calling through the string.
     */
    public function testCallingTheClassTheManifestNamesReturnsTheHolderSDeclarations(): void
    {
        $fromManifest = [];
        foreach (self::manifestEventHolders() as $class) {
            self::assertTrue(is_a($class, DeclaresEvents::class, true), $class . ' is a holder');
            /** @var list<EventDeclaration> $declarations */
            $declarations = $class::declarations();
            foreach ($declarations as $declaration) {
                $fromManifest[] = $declaration->name;
            }
        }

        $fromHolder = array_map(static fn (EventDeclaration $d): string => $d->name, AdminEvents::declarations());

        self::assertNotSame([], $fromManifest, 'the manifest route yields declarations');
        self::assertSame($fromHolder, $fromManifest, 'what the manifest resolves to is what the holder declares');
    }

    /**
     * The package's own manifest, read from disk — not from a fixture and not from Composer's runtime API,
     * so the file that ships is the file measured.
     *
     * @return list<string>
     */
    private static function manifestEventHolders(): array
    {
        $path = \dirname(__DIR__) . '/composer.json';
        self::assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['extra'] ?? null, 'the manifest has an extra section');
        self::assertIsArray($manifest['extra']['milpa'] ?? null, 'the manifest has an extra.milpa section');
        self::assertArrayHasKey('capability', $manifest['extra']['milpa'], 'declaring events left the capability declaration untouched');
        self::assertIsArray($manifest['extra']['milpa']['events'] ?? null, 'extra.milpa.events is a list of holder class names');

        $named = [];
        foreach ($manifest['extra']['milpa']['events'] as $class) {
            self::assertIsString($class);
            $named[] = $class;
        }

        return $named;
    }
}
