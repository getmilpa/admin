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

namespace Milpa\Admin\Data;

/**
 * WHICH OF THE FRAMEWORK'S OWN PACKAGES THIS APP RUNS, AT WHICH VERSIONS — one reader, two callers.
 *
 * From `composer.lock`, which is the only place that says what is ACTUALLY installed: `composer.json`
 * says what was asked for, and the two disagree the moment a range resolves. Absent lock, absent list —
 * a house whose dependencies were never resolved has nothing to report, and inventing a row would be
 * worse than an empty one.
 *
 * It is its own class because two surfaces need the same fact and neither owns it: the House section
 * reports the stack it runs, and the sidebar's footer says which versions you are looking at right now.
 * The reader used to be private to the House section's data source, so the shell would have had to ask
 * a section for it — a shell that reaches into one section's data is a shell that breaks when that
 * section is not installed (greenhouse decisions/0269).
 */
final class InstalledPackages
{
    /**
     * Every `milpa/*` package in the lock, name and resolved version, sorted by name.
     *
     * @return list<array{name: string, version: string}>
     */
    public static function rows(string $root): array
    {
        $file = $root . '/composer.lock';
        if ($root === '' || !is_file($file)) {
            return [];
        }
        $read = json_decode((string) file_get_contents($file), true);
        $rows = [];
        foreach (\is_array($read['packages'] ?? null) ? $read['packages'] : [] as $package) {
            $name = \is_array($package) && \is_string($package['name'] ?? null) ? $package['name'] : '';
            if (!str_starts_with($name, 'milpa/')) {
                continue;
            }
            $rows[] = ['name' => $name, 'version' => \is_string($package['version'] ?? null) ? $package['version'] : '?'];
        }
        usort($rows, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $rows;
    }

    /**
     * One package's resolved version, or null when the lock does not carry it.
     *
     * Null and not `'?'`: «not installed» and «installed at a version nobody could read» are different
     * facts, and a footer that printed a question mark for an absent package would say the app runs
     * something it does not.
     */
    public static function version(string $root, string $name): ?string
    {
        foreach (self::rows($root) as $row) {
            if ($row['name'] === $name) {
                return $row['version'];
            }
        }

        return null;
    }
}
