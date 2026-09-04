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

namespace Milpa\Admin;

use Milpa\Admin\Http\LoopbackOnlyMiddleware;
use Milpa\Runtime\Config;

/**
 * What the app declared about its admin panel, read once from the `admin` key of its config bag —
 * and, per key, whether it declared it or the panel is running on a default.
 *
 * Every knob has a default that keeps a fresh app both safe and served: the panel lives at
 * `/milpa/admin`, speaks English, answers only to loopback, and signs component state with a
 * secret derived from this package unless the app declares one (`admin.secret`, else `live.secret`).
 * Declaring is the whole interface — nothing here is read from the environment.
 *
 * The gate is the one knob the panel judges instead of copying: when `admin.middleware` names a class
 * that does not exist, the effective stack is the STRICT gate ({@see LoopbackOnlyMiddleware}) and
 * nothing else — never «open», never a half of what was declared — and the panel says so
 * (greenhouse decisions/0204). Falling to strict keeps the panel safe; dying with a 500 would hide the cause.
 */
final readonly class AdminSettings
{
    public const DEFAULT_ROUTE = '/milpa/admin';
    public const DEFAULT_LOCALE = 'en';
    public const DEFAULT_TITLE = 'Milpa Admin';

    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_CONFIG = 'config';

    public const SECRET_ADMIN = 'declared:admin.secret';
    public const SECRET_LIVE = 'declared:live.secret';
    public const SECRET_DERIVED = 'derived';

    public const GATE_LOOPBACK = 'loopback';
    public const GATE_CUSTOM = 'custom';
    public const GATE_OPEN = 'open';
    public const GATE_FALLBACK = 'fallback';

    /** The keys the app can declare under `admin`, in the order the Settings section lists them. */
    public const KEYS = ['route', 'locale', 'middleware', 'secret', 'title'];

    /** @var array<string, string> key → `default` | `config` */
    private array $sources;

    private string $secretSource;

    /**
     * @param string                $route        the panel's mount point, an absolute local path
     * @param string                $locale       the language of the panel's own copy (`en` or `es`)
     * @param list<class-string>    $middleware   PSR-15 middleware the app DECLARED for every admin route, outermost first — see {@see self::effectiveMiddleware()} for what the routes get
     * @param string                $secret       the HMAC secret that signs component state envelopes; empty derives one
     * @param string                $title        the brand shown in the sidebar and the document title
     * @param array<string, string> $sources      per key, `config` when the app declared it and `default` otherwise; keys left out are defaults
     * @param bool                  $declared     whether the `admin` key exists in the app's config at all
     * @param string|null           $secretSource where the secret came from (`declared:admin.secret`, `declared:live.secret`); null reads it from `$secret` — a declared secret is `admin.secret`'s, an empty one is derived
     */
    public function __construct(
        public string $route = self::DEFAULT_ROUTE,
        public string $locale = self::DEFAULT_LOCALE,
        public array $middleware = [LoopbackOnlyMiddleware::class],
        public string $secret = '',
        public string $title = self::DEFAULT_TITLE,
        array $sources = [],
        private bool $declared = false,
        ?string $secretSource = null,
    ) {
        $normalized = [];
        foreach (self::KEYS as $key) {
            $normalized[$key] = ($sources[$key] ?? self::SOURCE_DEFAULT) === self::SOURCE_CONFIG ? self::SOURCE_CONFIG : self::SOURCE_DEFAULT;
        }
        $this->sources = $normalized;
        $this->secretSource = $secret === ''
            ? self::SECRET_DERIVED
            : ($secretSource === self::SECRET_LIVE ? self::SECRET_LIVE : self::SECRET_ADMIN);
    }

    /**
     * Reads the `admin.*` keys, falling back to the defaults for anything the app did not declare —
     * and remembering, per key, which of the two happened.
     *
     * A missing config bag (the plugin booted without a kernel, as in unit tests) yields the defaults.
     * A value of the wrong type, or an empty one, is not a declaration: the default is used and recorded
     * as such, so the value the panel shows and the source it shows agree.
     */
    public static function fromConfig(?Config $config): self
    {
        $raw = $config?->get('admin');
        $declared = \is_array($raw);
        $admin = $declared ? $raw : [];
        $live = $config?->get('live');
        $live = \is_array($live) ? $live : [];

        $route = $admin['route'] ?? null;
        $locale = $admin['locale'] ?? null;
        $middleware = $admin['middleware'] ?? null;
        $title = $admin['title'] ?? null;
        $adminSecret = $admin['secret'] ?? null;
        $liveSecret = $live['secret'] ?? null;

        [$route, $routeSource] = self::pick(\is_string($route) ? self::normalizeRoute($route) : null, self::DEFAULT_ROUTE);
        [$locale, $localeSource] = self::pick(\is_string($locale) && $locale !== '' ? $locale : null, self::DEFAULT_LOCALE);
        [$title, $titleSource] = self::pick(\is_string($title) && $title !== '' ? $title : null, self::DEFAULT_TITLE);

        $middlewareSource = \is_array($middleware) ? self::SOURCE_CONFIG : self::SOURCE_DEFAULT;
        $middleware = \is_array($middleware)
            ? array_values(array_filter($middleware, 'is_string'))
            : [LoopbackOnlyMiddleware::class];

        [$secret, $secretSource] = match (true) {
            \is_string($adminSecret) && $adminSecret !== '' => [$adminSecret, self::SECRET_ADMIN],
            \is_string($liveSecret) && $liveSecret !== '' => [$liveSecret, self::SECRET_LIVE],
            default => ['', self::SECRET_DERIVED],
        };

        return new self(
            route: $route,
            locale: $locale,
            middleware: $middleware,
            secret: $secret,
            title: $title,
            sources: [
                'route' => $routeSource,
                'locale' => $localeSource,
                'middleware' => $middlewareSource,
                'secret' => $secret === '' ? self::SOURCE_DEFAULT : self::SOURCE_CONFIG,
                'title' => $titleSource,
            ],
            declared: $declared,
            secretSource: $secretSource,
        );
    }

    /** True when the `admin` key exists in the app's config — false means every value below is a default. */
    public function declared(): bool
    {
        return $this->declared;
    }

    /**
     * Per key, `config` when the app declared the value the panel is using, `default` when it did not.
     *
     * @return array<string, string> `route`, `locale`, `middleware`, `secret`, `title` → `default` | `config`
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Where the signing secret came from — never the secret itself, not even a fragment.
     *
     * @return string `declared:admin.secret` | `declared:live.secret` | `derived`
     */
    public function secretSource(): string
    {
        return $this->secretSource;
    }

    /**
     * The declared middleware classes that do not exist — what the app wrote and the runtime cannot load.
     *
     * @return list<string>
     */
    public function unresolvedMiddleware(): array
    {
        return array_values(array_filter($this->middleware, static fn (string $class): bool => !class_exists($class)));
    }

    /**
     * The middleware the panel's routes actually carry.
     *
     * The declared stack as long as every class in it exists — an empty list included: the app opened the
     * panel on purpose. The moment ONE declared class is unresolved, the whole stack is replaced by the
     * strict gate: a gate with a hole in it is not a gate, and mixing the half that loads with a silent
     * fallback would hide which half is running.
     *
     * @return list<class-string>
     */
    public function effectiveMiddleware(): array
    {
        return $this->unresolvedMiddleware() === [] ? $this->middleware : [LoopbackOnlyMiddleware::class];
    }

    /**
     * What kind of gate the panel is behind, as the topbar chip says it.
     *
     * @return string `loopback` (the strict default, declared or not) | `custom` (the app's own stack) | `open` (an empty stack, on purpose) | `fallback` (a misdeclared stack fell to loopback-only)
     */
    public function gateKind(): string
    {
        if ($this->unresolvedMiddleware() !== []) {
            return self::GATE_FALLBACK;
        }

        return match ($this->middleware) {
            [] => self::GATE_OPEN,
            [LoopbackOnlyMiddleware::class] => self::GATE_LOOPBACK,
            default => self::GATE_CUSTOM,
        };
    }

    /** The URL of one section of the panel. */
    public function sectionUrl(string $id): string
    {
        return $this->route . '/s/' . $id;
    }

    /** The URL of one of the panel's own assets (CSS, client runtime). */
    public function assetUrl(string $file): string
    {
        return $this->route . '/assets/' . $file;
    }

    /** The URL of the compose file the Stack section projects — every declared service, `text/yaml`. */
    public function composeUrl(): string
    {
        return $this->route . '/stack/compose.yml';
    }

    /** The signing secret, derived when the app declared none — stable per install, never empty. */
    public function signingSecret(): string
    {
        return $this->secret !== '' ? $this->secret : self::derivedSecret();
    }

    /**
     * @return array{0: string, 1: string} the value and its source: the declared one when accepted, else the default
     */
    private static function pick(?string $declared, string $default): array
    {
        return $declared === null ? [$default, self::SOURCE_DEFAULT] : [$declared, self::SOURCE_CONFIG];
    }

    /** An absolute path without a trailing slash; a bare slash is not a mount point and yields null. */
    private static function normalizeRoute(string $route): ?string
    {
        $normalized = '/' . trim($route, '/');

        return $normalized === '/' ? null : $normalized;
    }

    private static function derivedSecret(): string
    {
        return hash('sha256', __DIR__ . '|milpa-admin|signing');
    }
}
