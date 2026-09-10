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
 * THE THIRD POINT: what a `milpa/framework` release actually ships — asked, cached, never on paint.
 *
 * The update's other two points need no network ({@see FrameworkDivergence}). This one is egress: it
 * asks a registry what exists and fetches a release to hash it. So it is a VERB somebody presses, and
 * the panel renders only what a previous press left behind. This house measured what probing on paint
 * costs — a single provider reach put five seconds into a render (greenhouse decisions/0281).
 *
 * ── HOW A RELEASE IS FETCHED, AND WHY NOT BY HAND ───────────────────────────────────────────────────
 *
 * `composer create-project --no-install --no-scripts --no-plugins` — measured at 2.1 seconds and 500 KB
 * for one release. Composer already knows how to resolve a constraint, verify a dist and unpack it;
 * hand-rolling an archive download would be a second implementation of that, with its own bugs, for a
 * tool every one of these houses already has. `--no-install` skips the vendor tree we do not read,
 * `--no-scripts` keeps the fetched skeleton's own `post-create-project-cmd` from stamping a birth
 * record into a temporary directory, and `--no-plugins` keeps a third party out of this process.
 *
 * ── THE GLOBS ARE A SECOND COPY, AND THE TEST SAYS SO ───────────────────────────────────────────────
 *
 * 🚨 {@see self::TRACKED} repeats what `tools/stamp-framework.php` declares in the skeleton. It has to:
 * that file is COPIED into each app and frozen there, so an app's copy is the OLD list, and this
 * package is the one `composer update` can move. Two lists is a lie waiting to happen, so a test
 * compares them against the app's own copy whenever one is present — the same shape as the footer's
 * version, which is asserted against the release manifest rather than trusted.
 */
final class FrameworkRelease
{
    /** Where the registry answers what versions exist. */
    public const string REGISTRY = 'https://repo.packagist.org/p2/milpa/framework.json';

    /**
     * The skeleton's tracked set, mirrored from `tools/stamp-framework.php`.
     *
     * @var list<string>
     */
    public const array TRACKED = [
        'composer.json',
        'bin/*',
        'config/*.php',
        'public/*.php',
        'src/*.php',
        'src/*/*.php',
        'src/*/*/*.php',
        'src/*/*/*/*.php',
        'recipes/*.json',
        'tools/*',
    ];

    /**
     * The newest published version, without the `v`, or null when the registry cannot be reached.
     *
     * Pre-releases are skipped: a house is not offered an alpha because it happens to be newest. Null
     * and not an exception, because «I could not ask» is an answer a screen can print, and one a person
     * reading a panel on a laptop with no network needs to see rather than a stack trace.
     */
    public static function latest(): ?string
    {
        $body = @file_get_contents(self::REGISTRY, false, stream_context_create([
            'http' => ['timeout' => 8, 'header' => "User-Agent: milpa-admin\r\n"],
        ]));
        if (!\is_string($body) || $body === '') {
            return null;
        }
        $read = json_decode($body, true);
        $versions = \is_array($read) ? ($read['packages']['milpa/framework'] ?? null) : null;
        if (!\is_array($versions)) {
            return null;
        }

        foreach ($versions as $entry) {
            $version = \is_array($entry) && \is_string($entry['version'] ?? null) ? $entry['version'] : '';
            $version = ltrim($version, 'v');
            if (preg_match('/^\d+\.\d+\.\d+$/', $version) === 1) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Every tracked file of a release, keyed to the sha256 of its bytes — from cache when it is there.
     *
     * Cached per version under the app's `storage/`, because a release's bytes never change: once
     * `0.48.0` has been hashed, asking again is pure waste, and the cache is what lets the panel render
     * a reconciliation without touching the network at all.
     *
     * @return array<string, string>|null null when the release could not be fetched
     */
    public static function ships(string $version, string $root): ?array
    {
        $cache = $root . '/storage/framework-releases/' . $version . '.json';
        if (is_file($cache)) {
            $read = json_decode((string) file_get_contents($cache), true);
            if (\is_array($read)) {
                /** @var array<string, string> $hashes */
                $hashes = array_filter($read, '\\is_string');

                return $hashes;
            }
        }

        $tree = sys_get_temp_dir() . '/milpa-release-' . $version . '-' . bin2hex(random_bytes(4));
        $command = \sprintf(
            'composer create-project --no-install --no-scripts --no-plugins --quiet %s:%s %s 2>&1',
            escapeshellarg('milpa/framework'),
            escapeshellarg($version),
            escapeshellarg($tree),
        );
        exec($command, $output, $status);
        if ($status !== 0 || !is_dir($tree)) {
            self::rm($tree);

            return null;
        }

        $hashes = self::hashes($tree);
        self::rm($tree);
        if ($hashes === []) {
            return null;
        }

        @mkdir(\dirname($cache), 0o775, true);
        file_put_contents($cache, json_encode($hashes, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");

        return $hashes;
    }

    /**
     * The tracked files of a tree, path relative to it, keyed to the sha256 of its bytes.
     *
     * @return array<string, string>
     */
    public static function hashes(string $root): array
    {
        $out = [];
        foreach (self::TRACKED as $glob) {
            foreach (glob($root . '/' . $glob) ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $out[substr($path, \strlen($root) + 1)] = hash_file('sha256', $path) ?: '';
            }
        }
        ksort($out);

        return $out;
    }

    /** Removes a fetched tree; a release we already hashed is not worth keeping on disk. */
    private static function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
