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

namespace Milpa\Admin\Tests;

use Milpa\Admin\I18n\Catalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 A COMMAND THE PANEL PRINTS HAS TO RUN AS PRINTED — and this package had its own copies.
 *
 * `coa` is not on PATH after `composer create-project`: Composer does not link the ROOT package's
 * `bin`, so `vendor/bin/` holds php-cs-fixer, phpstan and phpunit and no `coa`. Measured in a clean
 * shell at an app root: `coa capabilities:refresh` answers «command not found», exit 127, while
 * `php bin/coa list` exits 0 (greenhouse decisions/0305).
 *
 * The audit that found it was auditing `house:start` in `milpa/app-runtime` — and then the count
 * came back at SIXTEEN sites across THREE packages, because this one keeps its own: two in the House
 * section's renderer, two in each locale of the catalog, and the two refusal sentences that name the
 * terminal a person can still use. Fixing the authority in app-runtime could not reach them.
 *
 * A guard rather than an example, and it reads BOTH locales: a command is not translatable copy —
 * `php bin/coa stack` is the same sentence in every language — so a command duplicated per locale is
 * two places for one fact to rot, and only a scan sees the second one.
 */
#[CoversClass(Catalog::class)]
final class ThePanelPrintsARunnableCommandTest extends TestCase
{
    /**
     * Both locales live in one file, so one scan of `src/` reads them both.
     *
     * There is no `Catalog::all()` and this guard does not ask for one: the catalog's public surface
     * is `tr()`, `has()`, `locale()` and `locales()`, which is the right shape for a consumer and the
     * wrong shape for an audit. Adding an enumerator so a test could walk it would widen a published
     * API for a guard's convenience — so the guard reads the source instead, which is where both
     * locales already are.
     */
    public function testThisPackageShipsBothLocalesSoTheScanBelowCoversBoth(): void
    {
        self::assertSame(['en', 'es'], Catalog::locales());
        self::assertStringContainsString('capabilities.command_form', (string) file_get_contents(\dirname(__DIR__) . '/src/I18n/Catalog.php'));
    }

    /** And the same in `src/`, for the commands that are not catalog values. */
    public function testNoStringInTheSourceOffersABareCoa(): void
    {
        $offenders = [];

        foreach (self::phpFiles(\dirname(__DIR__) . '/src') as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                $trimmed = ltrim($line);
                // Comments and docblocks are the code-language ratchet's subject, not this guard's.
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (preg_match('/[\'"`]coa\s+[a-z]/', $line) === 1) {
                    $offenders[] = basename($file) . ':' . ($n + 1) . '  ' . trim($line);
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $root): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
