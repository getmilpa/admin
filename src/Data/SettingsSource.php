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
 * value the panel is using and where it came from.
 *
 * Pure — it reads the {@see AdminSettings} the panel booted with and nothing else. Values are shown as
 * declared (route, locale, title verbatim; middleware as class names), except the secret: its row carries
 * only where it came from, never the value nor a fragment of it. A middleware class that does not exist
 * is kept in the row under its full name — that is the one to fix — and listed in `unresolved`, so the
 * renderer can say the panel fell back to the strict gate (greenhouse decisions/0204).
 */
final class SettingsSource
{
    public function __construct(private readonly AdminSettings $settings)
    {
    }

    /**
     * The five rows, the effective gate, whether the app declared anything, and the snippet to paste when it did not.
     *
     * @return array{declared: bool, gate: string, locale: string, rows: list<array{key: string, value: string, source: string}>, unresolved: list<string>, snippet: string}
     */
    public function snapshot(): array
    {
        $settings = $this->settings;
        $sources = $settings->sources();
        $unresolved = $settings->unresolvedMiddleware();

        $values = [
            'route' => $settings->route,
            'locale' => $settings->locale,
            'middleware' => self::middleware($settings->middleware, $unresolved),
            'secret' => $settings->secretSource(),
            'title' => $settings->title,
        ];

        $rows = [];
        foreach (AdminSettings::KEYS as $key) {
            $rows[] = ['key' => $key, 'value' => $values[$key], 'source' => $sources[$key] ?? AdminSettings::SOURCE_DEFAULT];
        }

        return [
            'declared' => $settings->declared(),
            'gate' => $settings->gateKind(),
            'locale' => $settings->locale,
            'rows' => $rows,
            'unresolved' => $unresolved,
            'snippet' => self::snippet(),
        ];
    }

    /** The `admin` key a fresh app pastes into `config/app.php` — the defaults, spelled out. */
    public static function snippet(): string
    {
        return \sprintf(
            "'admin' => ['route' => '%s', 'locale' => '%s', 'middleware' => [\\%s::class]],",
            AdminSettings::DEFAULT_ROUTE,
            AdminSettings::DEFAULT_LOCALE,
            LoopbackOnlyMiddleware::class,
        );
    }

    /**
     * The declared stack for a human: `[]` when the app opened the panel on purpose, else short class
     * names — except the unresolved ones, kept whole so the typo is visible.
     *
     * @param list<string> $middleware
     * @param list<string> $unresolved
     */
    private static function middleware(array $middleware, array $unresolved): string
    {
        if ($middleware === []) {
            return '[]';
        }

        return implode(', ', array_map(
            static fn (string $class): string => \in_array($class, $unresolved, true) ? $class : self::shortName($class),
            $middleware,
        ));
    }

    private static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }
}
