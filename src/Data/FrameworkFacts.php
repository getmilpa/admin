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
 * THE ONE PLACE THIS PANEL NAMES `Milpa\AppRuntime\Framework` — by string, on purpose.
 *
 * The provenance family lives in `milpa/app-runtime` because what a house was born from is a fact about
 * the APP, and because applying a reconciliation is a governed act, which is an Operation, which a
 * panel cannot declare (greenhouse decisions/0295).
 *
 * 🚨 AND IT IS REFERENCED BY STRING, WHICH IS THE HOUSE'S OWN IDIOM HERE — see
 * {@see PluginsSource::capabilities()}, which names `Milpa\AppRuntime\Support\Capabilities` exactly this
 * way. `milpa/admin` does not depend on `milpa/app-runtime`: the panel works without it, with fewer
 * facts to show. Adding it as a dev dependency to get typed calls was tried and measured: it turned six
 * green tests red, because the passkey gate's class becomes RESOLVABLE while `milpa/auth` is still
 * absent, so a middleware the container cannot build stops being invisible. Those six are a real
 * coupling and they are not this slice's to fix — installing a package to type-check one file is not
 * worth surfacing them halfway.
 *
 * So the strings live HERE, once, and every caller in this package talks to a typed method instead.
 */
final class FrameworkFacts
{
    private const string STAMP = 'Milpa\\AppRuntime\\Framework\\FrameworkStamp';

    private const string DIVERGENCE = 'Milpa\\AppRuntime\\Framework\\FrameworkDivergence';

    private const string RELEASE = 'Milpa\\AppRuntime\\Framework\\FrameworkRelease';

    private const string RECONCILIATION = 'Milpa\\AppRuntime\\Framework\\FrameworkReconciliation';

    /** Whether this app carries the package that knows any of this. */
    public static function available(): bool
    {
        return class_exists(self::STAMP);
    }

    /** The framework version this house runs on, or null when nothing can say. */
    public static function version(string $root): ?string
    {
        if (!self::available()) {
            return null;
        }
        /** @var string|null $version */
        $version = (self::STAMP)::version($root);

        return $version;
    }

    /**
     * The counts of what this house has changed since it was born, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function divergence(string $root): ?array
    {
        if (!self::available()) {
            return null;
        }
        /** @var array<string, mixed>|null $summary */
        $summary = (self::DIVERGENCE)::summary($root);

        return $summary;
    }

    /**
     * Every file that is not untouched, with what became of it.
     *
     * @return list<array<string, mixed>>
     */
    public static function divergenceRows(string $root): array
    {
        if (!self::available()) {
            return [];
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = (self::DIVERGENCE)::rows($root);

        return $rows;
    }

    /**
     * What the last update check found, or null when nobody has checked.
     *
     * @return array<string, string>|null
     */
    public static function lastCheck(string $root): ?array
    {
        if (!self::available()) {
            return null;
        }
        /** @var array<string, string>|null $check */
        $check = (self::RELEASE)::remembered($root);

        return $check;
    }

    /** Asks the registry which release is newest — the only method here that reaches the network. */
    public static function latest(): ?string
    {
        if (!self::available()) {
            return null;
        }
        /** @var string|null $latest */
        $latest = (self::RELEASE)::latest();

        return $latest;
    }

    /**
     * A release's tracked-file hashes, from cache when they are there.
     *
     * @return array<string, string>|null
     */
    public static function ships(string $version, string $root): ?array
    {
        if (!self::available()) {
            return null;
        }
        /** @var array<string, string>|null $ships */
        $ships = (self::RELEASE)::ships($version, $root);

        return $ships;
    }

    /** Remembers which release the last check found, so a render never has to ask. */
    public static function remember(string $root, string $version, string $at): void
    {
        if (!self::available()) {
            return;
        }
        (self::RELEASE)::remember($root, $version, $at);
    }

    /**
     * What a release would do to this house, judged, or null when it cannot be judged.
     *
     * @param array<string, string> $ships
     *
     * @return array{summary: array<string, int>, rows: list<array{path: string, status: string}>}|null
     */
    public static function reconcile(string $root, array $ships): ?array
    {
        if (!self::available()) {
            return null;
        }
        /** @var array<string, int>|null $summary */
        $summary = (self::RECONCILIATION)::summary($root, $ships);
        /** @var list<array{path: string, status: string}>|null $rows */
        $rows = (self::RECONCILIATION)::rows($root, $ships);
        if ($summary === null || $rows === null) {
            return null;
        }

        return ['summary' => $summary, 'rows' => $rows];
    }

    /*
     * NO HAY `states()`. La escribí para que el renderer nombrara los seis estados sin repetir strings,
     * y al cablearlo resultó que el renderer los compara con literales — así que nadie la llamaba. Una
     * conveniencia sin consumidor es la deuda que se ve como capacidad (decisions/0213).
     */
}
