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

namespace Milpa\Admin\Data;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Http\LoopbackOnlyMiddleware;

/**
 * What the app declared about its panel, as the Settings section shows it: one row per key with the
 * value the panel is using, where it came from, and — when the panel refused what was declared — what
 * the app wrote.
 *
 * Pure — it reads the {@see AdminSettings} the panel booted with and nothing else. Values are shown as
 * declared (route, locale, title verbatim; middleware as class names), except the secret: its row carries
 * only where it came from, never the value nor a fragment of it. A declared middleware list is shown
 * entry by entry — short names for the classes that load, the whole name for a typo, the type for an
 * entry that is not a name, `(empty)` for an empty string — and every defect is listed in `unresolved`,
 * so the renderer can say the panel fell back to the strict gate (greenhouse decisions/0204). The `gate`
 * is what the panel NAMES it ({@see AdminSettings::gateLabel()}): app-runtime's passkey gate, alone, is
 * `passkey`; and the empty state offers that gate as the alternative of its snippet — the same `admin`
 * key whole, with the passkey gate as its middleware, so it pastes as-is (decisions/0206).
 */
final class SettingsSource
{
    public function __construct(private readonly AdminSettings $settings)
    {
    }

    /**
     * The five rows, the gate as the panel names it, whether the app declared anything, whether the gate
     * was malformed, and the snippet to paste when the app declared nothing — plus the same key with the
     * passkey gate, the alternative it offers whole.
     *
     * @return array{declared: bool, gate: string, locale: string, rows: list<array{key: string, value: string, source: string, declared: string|null}>, unresolved: list<string>, malformed: bool, snippet: string, passkeySnippet: string}
     */
    public function snapshot(): array
    {
        $settings = $this->settings;
        $sources = $settings->sources();
        $rejected = $settings->rejected();
        $malformed = $settings->malformed();

        $values = [
            'route' => $settings->route,
            'locale' => $settings->locale,
            'middleware' => self::middleware($malformed ? $settings->effectiveMiddleware() : $settings->middleware),
            'secret' => $settings->secretSource(),
            'title' => $settings->title,
        ];

        $rows = [];
        foreach (AdminSettings::KEYS as $key) {
            $rows[] = [
                'key' => $key,
                'value' => $values[$key],
                'source' => $sources[$key] ?? AdminSettings::SOURCE_DEFAULT,
                'declared' => $rejected[$key] ?? null,
            ];
        }

        return [
            'declared' => $settings->declared(),
            'gate' => $settings->gateLabel(),
            'locale' => $settings->locale,
            'rows' => $rows,
            'unresolved' => $settings->unresolvedMiddleware(),
            'malformed' => $malformed,
            'snippet' => self::snippet(),
            'passkeySnippet' => self::passkeySnippet(),
        ];
    }

    /**
     * The `admin` key that puts the panel behind app-runtime's passkey gate — the alternative the empty
     * state offers: the whole line, not a fragment, so it replaces {@see self::snippet()} pasted as-is.
     */
    public static function passkeySnippet(): string
    {
        return self::adminKey(AdminSettings::PASSKEY_GATE);
    }

    /** The `admin` key a fresh app pastes into `config/app.php` — the defaults, spelled out. */
    public static function snippet(): string
    {
        return self::adminKey(LoopbackOnlyMiddleware::class);
    }

    /** The `admin` key with the default route and locale and the given class as its one middleware. */
    private static function adminKey(string $middleware): string
    {
        return \sprintf(
            "'admin' => ['route' => '%s', 'locale' => '%s', 'middleware' => [\\%s::class]],",
            AdminSettings::DEFAULT_ROUTE,
            AdminSettings::DEFAULT_LOCALE,
            $middleware,
        );
    }

    /**
     * A middleware list for a human: `[]` when the app opened the panel on purpose, else one item per
     * entry — see {@see self::entry()}.
     *
     * @param list<mixed> $middleware
     */
    private static function middleware(array $middleware): string
    {
        if ($middleware === []) {
            return '[]';
        }

        return implode(', ', array_map(self::entry(...), $middleware));
    }

    /**
     * One declared entry: the short name of a class that loads, the whole name of one that does not
     * (that is the one to fix), the type of anything that is not a name, `(empty)` for an empty string.
     */
    private static function entry(mixed $entry): string
    {
        if (!\is_string($entry)) {
            return get_debug_type($entry);
        }
        if ($entry === '') {
            return AdminSettings::EMPTY;
        }

        return AdminSettings::middlewareDefect($entry) === null ? self::shortName($entry) : $entry;
    }

    private static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }
}
