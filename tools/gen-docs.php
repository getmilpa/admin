<?php

/**
 * This file is part of milpa/plugin — the GitHub-native plugin distribution of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

/**
 * Generates the static API reference site for milpa/plugin.
 *
 * Thin entry over the family docs generator (`Milpa\Docs\SiteGenerator`,
 * shipped inside the milpa/core dist this package already requires): reflects
 * over `src/`, renders one `mui-api`-styled page per public type wrapped in
 * the `mui-docs` shell, a nav, a per-page table of contents, and `index.html`.
 *
 * Usage: php tools/gen-docs.php --out <dir> [--css-base <url>] [--version <v>]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Required-value long options (`name:`, not `name::`) so `--css-base /ds` with a
// space is captured; optional (`::`) only binds `--css-base=/ds`. getopt yields
// `false` for a flag it can't bind a value to, so guard with is_string, not `??`
// (which only rescues null) before falling back to the default.
$opts = getopt('', ['out:', 'css-base:', 'version:']);
$out = is_string($opts['out'] ?? null) ? $opts['out'] : 'build/docs';
$cssBase = is_string($opts['css-base'] ?? null) ? $opts['css-base'] : 'https://cdn.jsdelivr.net/npm/@milpa/design@0.8.0';

// Version shown in the docs chrome (topbar badge, title, footer). Prefer an
// explicit --version; otherwise read the release-please manifest (present in
// the published repo); fall back to "dev" for local builds.
$version = is_string($opts['version'] ?? null) ? $opts['version'] : null;
if ($version === null) {
    $manifest = dirname(__DIR__) . '/.github/.release-please-manifest.json';
    $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
    $version = is_array($data) && is_string($data['.'] ?? null) ? $data['.'] : 'dev';
}

// Branding for this package's docs site — see Milpa\Docs\SiteConfig (milpa/core).
$config = new Milpa\Docs\SiteConfig(
    brand: 'Milpa Admin',
    nsPrefix: 'Milpa\\Admin\\',
    installCommand: 'composer require milpa/admin',
    repoUrl: 'https://github.com/getmilpa/admin',
    pagesUrl: 'https://getmilpa.github.io/admin/',
    heroParagraph: 'The <strong>administration panel</strong> of Milpa — a section shell that discovers what your app can actually do. Add one plugin and <code>/milpa/admin</code> exists: navigation, a settings form, a plugin manager and a route inspector, behind your own auth and rendered on the server. The panel knows no section by name: any plugin can contribute one, and a capability you did not wire simply does not appear.',
    utmContent: 'admin',
);

$count = (new Milpa\Docs\SiteGenerator(dirname(__DIR__) . '/src', $out, $cssBase, $version, $config))->generate();

echo "generated {$count} page(s) to {$out} (v{$version}, css-base: {$cssBase})\n";
exit(0);
