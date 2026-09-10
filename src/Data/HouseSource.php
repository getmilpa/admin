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

use Milpa\Admin\AdminSettings;
use Milpa\Runtime\Kernel;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * WHAT THIS HOUSE IS, standing — the facts the panel's first screen is made of.
 *
 * ── WHY THERE IS A FIRST SCREEN AT ALL ───────────────────────────────────────────────────────────
 *
 * The panel used to open on Plugins, and NOBODY CHOSE THAT: `SectionCatalogue::first()` returns the
 * lowest `(order, id)`, Plugins declared `order: 10`, and it won. So a human who had just installed
 * the framework was met by a table of PHP class names — `Milpa\Admin\AdminPlugin`,
 * `App\Plugins\HelloPlugin\HelloPlugin`. That is `decisions/0260`'s lesson one room further in: the
 * door stopped lecturing about what kind of framework this is, and the room behind it opened with a
 * list of namespaces (greenhouse decisions/0264).
 *
 * ── IT COMPOSES, IT DOES NOT GATHER ──────────────────────────────────────────────────────────────
 *
 * Every figure here already existed somewhere in this package; it was presented as an appendix under
 * a class table. So this reads the SAME sources the other sections read — a second way to count
 * plugins would be a second answer to one question. What it adds is the two facts nobody was asking
 * for: what this house was FOUNDED to do, and which of the framework's own packages it runs.
 *
 * ── WHAT IT DELIBERATELY DOES NOT KNOW ───────────────────────────────────────────────────────────
 *
 * Who this house recognises — the credential ledger — belongs to `milpa/app-runtime`, which owns the
 * passkey. Reaching into its storage path from here would be this package guessing another's
 * convention. The identity section is its to declare; this screen says only what the SHELL already
 * told it: the gate in effect and the principal, if any.
 */
final readonly class HouseSource
{
    public function __construct(
        private DIContainerInterface $container,
        private AdminSettings $settings,
        private PluginsSource $plugins,
        private RoutesSource $routes,
    ) {
    }

    /**
     * Everything the first screen paints, in one read.
     *
     * Composed from the sources the other sections already use, plus the two facts nobody else
     * reports: what this house was FOUNDED to do, and which of the framework's own packages it runs.
     *
     * @return array{
     *     title: string,
     *     route: string,
     *     gate: string,
     *     root: string,
     *     foundation: array{declared: bool, domain: null|string, objective: null|string, founded_at: null|string, boundaries: int, authorities: array<string, string>},
     *     packages: array{count: int, rows: list<array{name: string, version: string}>},
     *     capabilities: array{installed: list<array<string, mixed>>, available: list<array<string, mixed>>, source: string},
     *     plugins: int,
     *     routes: int,
     * }
     */
    public function snapshot(): array
    {
        $root = $this->root();
        $plugins = $this->plugins->snapshot();
        $capabilities = \is_array($plugins['capabilities'] ?? null) ? $plugins['capabilities'] : [];

        return [
            'title' => $this->settings->title,
            'route' => $this->settings->route,
            'gate' => $this->settings->gateLabel(),
            'root' => $root,
            'foundation' => $this->foundation($root),
            // WHAT THIS HOUSE HAS CHANGED SINCE IT WAS BORN. Null when the tree carries no birth
            // record: a house created before `milpa/framework` stamped one cannot say, and counting
            // zero customized files would read as «nothing changed» rather than «unknown» — two
            // different answers, and telling them apart is the point (greenhouse decisions/0293).
            'divergence' => FrameworkDivergence::summary($root),
            'divergenceRows' => FrameworkDivergence::rows($root),
            // THE THIRD POINT, only as far as a previous press of the verb got it. Null means nobody has
            // checked — which is NOT «you are up to date», and a screen that showed an empty
            // reconciliation for the first would be claiming the second (greenhouse decisions/0294).
            'check' => FrameworkRelease::remembered($root),
            'reconciliation' => $this->reconciliation($root),
            'packages' => $this->packages($root),
            'capabilities' => [
                'installed' => \is_array($capabilities['installed'] ?? null) ? array_values($capabilities['installed']) : [],
                'available' => \is_array($capabilities['available'] ?? null) ? array_values($capabilities['available']) : [],
                'source' => \is_string($capabilities['source'] ?? null) ? $capabilities['source'] : '',
            ],
            'plugins' => \count(\is_array($plugins['plugins'] ?? null) ? $plugins['plugins'] : []),
            'routes' => \count($this->routes->snapshot()['routes'] ?? []),
        ];
    }

    /**
     * What this house was founded to do, or the fact that nothing was declared.
     *
     * A `foundation.json` full of nulls is the common case and NOT the same as a missing one: the
     * file says the app was created, and the nulls say nobody ever told it what it is for. Both are
     * reported as they are, because a screen that renders «—» for either teaches nothing about
     * which one it is looking at.
     *
     * @return array{declared: bool, domain: null|string, objective: null|string, founded_at: null|string, boundaries: int, authorities: array<string, string>}
     */
    private function foundation(string $root): array
    {
        $blank = ['declared' => false, 'domain' => null, 'objective' => null, 'founded_at' => null, 'boundaries' => 0, 'authorities' => []];
        $file = $root . '/.milpa/foundation.json';
        if ($root === '' || !is_file($file)) {
            return $blank;
        }
        $read = json_decode((string) file_get_contents($file), true);
        if (!\is_array($read)) {
            return $blank;
        }
        $text = static fn (string $key): ?string => \is_string($read[$key] ?? null) && trim((string) $read[$key]) !== ''
            ? trim((string) $read[$key])
            : null;

        $authorities = [];
        foreach (\is_array($read['authorities'] ?? null) ? $read['authorities'] : [] as $what => $who) {
            if (\is_string($what) && \is_string($who)) {
                $authorities[$what] = $who;
            }
        }

        return [
            'declared' => true,
            'domain' => $text('domain'),
            'objective' => $text('objective'),
            'founded_at' => $text('founded_at'),
            'boundaries' => \count(\is_array($read['boundaries'] ?? null) ? $read['boundaries'] : []),
            'authorities' => $authorities,
        ];
    }

    /**
     * Which of the framework's own packages this house runs, at which versions.
     *
     * Delegated to {@see InstalledPackages}, which the sidebar's footer reads too: the same fact, one
     * owner. It lived here, private, until a second surface needed it — and a shell that reaches into
     * one section's data source is a shell that breaks when that section is not installed
     * (greenhouse decisions/0269).
     *
     * @return array{count: int, rows: list<array{name: string, version: string}>}
     */
    private function packages(string $root): array
    {
        $rows = InstalledPackages::rows($root);

        return ['count' => \count($rows), 'rows' => $rows];
    }

    /**
     * What the last check would do to this house, read entirely from cache.
     *
     * No network here, ever: the version comes from the pointer the verb wrote and the hashes from the
     * per-release cache it filled. A release whose cache went missing answers null rather than being
     * re-fetched during a render (greenhouse decisions/0281, 0294).
     *
     * @return array{latest: string, at: string, summary: array<string, int>, rows: list<array{path: string, status: string}>}|null
     */
    private function reconciliation(string $root): ?array
    {
        $check = FrameworkRelease::remembered($root);
        if ($check === null || $root === '') {
            return null;
        }
        $cache = $root . '/storage/framework-releases/' . $check['latest'] . '.json';
        if (!is_file($cache)) {
            return null;
        }
        $read = json_decode((string) file_get_contents($cache), true);
        if (!\is_array($read)) {
            return null;
        }
        /** @var array<string, string> $ships */
        $ships = array_filter($read, '\is_string');

        $summary = FrameworkReconciliation::summary($root, $ships);
        $rows = FrameworkReconciliation::rows($root, $ships);
        if ($summary === null || $rows === null) {
            return null;
        }

        return ['latest' => $check['latest'], 'at' => $check['at'], 'summary' => $summary, 'rows' => $rows];
    }

    /** The app's root, from the kernel the app registered — '' when there is none to ask. */
    private function root(): string
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;

        return $kernel instanceof Kernel ? $kernel->root() : '';
    }
}
