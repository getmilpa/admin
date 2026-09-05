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
use Milpa\Admin\I18n\Catalog;
use Milpa\Runtime\Config;
use Psr\Http\Server\MiddlewareInterface;

/**
 * What the app declared about its admin panel, read once from the `admin` key of its config bag —
 * and, per key, whether it declared it, the panel is running on a default, or the panel refused it.
 *
 * Every knob has a default that keeps a fresh app both safe and served: the panel lives at
 * `/milpa/admin`, speaks English, answers only to loopback, and signs component state with a
 * secret derived from this package unless the app declares one (`admin.secret`, else `live.secret`).
 * Declaring is the whole interface — nothing here is read from the environment.
 *
 * The gate is the one knob the panel judges instead of copying. The rule: **only a literally empty
 * list `[]` opens the panel**. Anything else that is not a list of strings naming a PSR-15 middleware
 * class — a non-string entry, an associative map, a value that is not a list at all, an empty string,
 * a class that does not exist, a class that exists but is not a middleware — is a misdeclaration, and
 * the effective stack is the STRICT gate ({@see LoopbackOnlyMiddleware}) and nothing else: never «open»,
 * never the half that loads. The panel says so in Settings and in the topbar (greenhouse decisions/0204).
 * Falling to strict keeps the panel safe; dying with a 500 would hide the cause.
 */
final readonly class AdminSettings
{
    public const DEFAULT_ROUTE = '/milpa/admin';
    public const DEFAULT_LOCALE = 'en';
    public const DEFAULT_TITLE = 'Milpa Admin';

    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_REJECTED = 'rejected';

    public const SECRET_ADMIN = 'declared:admin.secret';
    public const SECRET_LIVE = 'declared:live.secret';
    public const SECRET_DERIVED = 'derived';

    public const GATE_LOOPBACK = 'loopback';
    public const GATE_CUSTOM = 'custom';
    public const GATE_OPEN = 'open';
    public const GATE_FALLBACK = 'fallback';
    public const GATE_PASSKEY = 'passkey';

    /**
     * The gate `milpa/app-runtime`'s PasskeyPlugin registers in the container — named here as a string,
     * so the panel names it without depending on it (greenhouse decisions/0206).
     */
    public const PASSKEY_GATE = 'Milpa\\AppRuntime\\Web\\PasskeyGateMiddleware';

    /** How an empty string is described wherever a declared value is shown — never as nothing. */
    public const EMPTY = '(empty)';

    /** The keys the app can declare under `admin`, in the order the Settings section lists them. */
    public const KEYS = ['route', 'locale', 'middleware', 'secret', 'title'];

    /** @var array<string, string> key → `default` | `config` | `rejected` */
    private array $sources;

    /** @var array<string, string> key → what the app declared and the panel refused, described */
    private array $rejected;

    private string $secretSource;

    /**
     * @param string                $route        the panel's mount point, an absolute local path
     * @param string                $locale       the language of the panel's own copy — one the {@see Catalog} carries
     * @param array<mixed>          $middleware   what the app DECLARED under `admin.middleware` when it declared an array: every entry as written, non-strings included, keys included when it was a map; `[]` when it declared no array at all — {@see self::malformed()} tells that apart from an empty list. See {@see self::effectiveMiddleware()} for what the routes get
     * @param string                $secret       the HMAC secret that signs component state envelopes; empty derives one
     * @param string                $title        the brand shown in the sidebar and the document title
     * @param array<string, string> $sources      per key, `config` when the app declared the value the panel is using; anything else, or a key left out, is `default`
     * @param bool                  $declared     whether the `admin` key exists in the app's config at all
     * @param string|null           $secretSource where the secret came from (`declared:admin.secret`, `declared:live.secret`); null reads it from `$secret` — a declared secret is `admin.secret`'s, an empty one is derived
     * @param array<string, string> $rejected     per key, what the app declared and the panel refused, described (the value for a string, the type otherwise) — a key listed here is `rejected` whatever `$sources` says
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
        array $rejected = [],
    ) {
        $normalizedSources = [];
        $normalizedRejected = [];
        foreach (self::KEYS as $key) {
            if (isset($rejected[$key])) {
                $normalizedSources[$key] = self::SOURCE_REJECTED;
                $normalizedRejected[$key] = $rejected[$key];
                continue;
            }
            $normalizedSources[$key] = ($sources[$key] ?? self::SOURCE_DEFAULT) === self::SOURCE_CONFIG ? self::SOURCE_CONFIG : self::SOURCE_DEFAULT;
        }
        $this->sources = $normalizedSources;
        $this->rejected = $normalizedRejected;
        $this->secretSource = $secret === ''
            ? self::SECRET_DERIVED
            : ($secretSource === self::SECRET_LIVE ? self::SECRET_LIVE : self::SECRET_ADMIN);
    }

    /**
     * Reads the `admin.*` keys, falling back to the defaults for anything the app did not declare —
     * and remembering, per key, which of three things happened: declared and used (`config`), left out
     * (`default`), or declared and refused (`rejected` — the default runs, and the row says what was written).
     *
     * A missing config bag (the plugin booted without a kernel, as in unit tests) yields the defaults.
     * A key set to null is not a declaration. A route that is a bare slash, a locale the catalog lacks,
     * an empty title, a value of the wrong type — those the app DID write, so they are `rejected`, never
     * painted `default`. The middleware is kept exactly as declared; judging it is {@see self::effectiveMiddleware()}'s.
     */
    public static function fromConfig(?Config $config): self
    {
        $raw = $config?->get('admin');
        $declared = \is_array($raw);
        $admin = $declared ? $raw : [];
        $live = $config?->get('live');
        $live = \is_array($live) ? $live : [];

        $rawRoute = $admin['route'] ?? null;
        $rawLocale = $admin['locale'] ?? null;
        $rawMiddleware = $admin['middleware'] ?? null;
        $rawTitle = $admin['title'] ?? null;
        $adminSecret = $admin['secret'] ?? null;
        $liveSecret = $live['secret'] ?? null;

        $sources = [];
        $rejected = [];

        [$route, $sources['route'], $rejected['route']] = self::judge(
            $rawRoute,
            \is_string($rawRoute) ? self::normalizeRoute($rawRoute) : null,
            self::DEFAULT_ROUTE,
        );
        [$locale, $sources['locale'], $rejected['locale']] = self::judge(
            $rawLocale,
            \is_string($rawLocale) && \in_array($rawLocale, Catalog::locales(), true) ? $rawLocale : null,
            self::DEFAULT_LOCALE,
        );
        [$title, $sources['title'], $rejected['title']] = self::judge(
            $rawTitle,
            \is_string($rawTitle) && $rawTitle !== '' ? $rawTitle : null,
            self::DEFAULT_TITLE,
        );

        if ($rawMiddleware === null) {
            $middleware = [LoopbackOnlyMiddleware::class];
            $sources['middleware'] = self::SOURCE_DEFAULT;
        } elseif (\is_array($rawMiddleware) && array_is_list($rawMiddleware)) {
            $middleware = $rawMiddleware;
            $sources['middleware'] = self::SOURCE_CONFIG;
        } else {
            $middleware = \is_array($rawMiddleware) ? $rawMiddleware : [];
            $sources['middleware'] = self::SOURCE_REJECTED;
            $rejected['middleware'] = get_debug_type($rawMiddleware);
        }

        [$secret, $secretSource] = match (true) {
            \is_string($adminSecret) && $adminSecret !== '' => [$adminSecret, self::SECRET_ADMIN],
            \is_string($liveSecret) && $liveSecret !== '' => [$liveSecret, self::SECRET_LIVE],
            default => ['', self::SECRET_DERIVED],
        };
        $sources['secret'] = $secret === '' ? self::SOURCE_DEFAULT : self::SOURCE_CONFIG;

        return new self(
            route: $route,
            locale: $locale,
            middleware: $middleware,
            secret: $secret,
            title: $title,
            sources: $sources,
            declared: $declared,
            secretSource: $secretSource,
            rejected: array_filter($rejected, static fn (?string $description): bool => $description !== null),
        );
    }

    /** True when the `admin` key exists in the app's config — false means the panel read nothing under it. */
    public function declared(): bool
    {
        return $this->declared;
    }

    /**
     * Per key, `config` when the app declared the value the panel is using, `default` when it did not,
     * `rejected` when it declared one the panel refused — the default runs, and {@see self::rejected()} says what was written.
     *
     * @return array<string, string> `route`, `locale`, `middleware`, `secret`, `title` → `default` | `config` | `rejected`
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * What the app declared and the panel refused, per key, described: the value for a string
     * (`fr`, `/`, {@see self::EMPTY}), the type for anything else (`int`, `bool`, `array`).
     *
     * @return array<string, string> only the rejected keys
     */
    public function rejected(): array
    {
        return $this->rejected;
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
     * True when `admin.middleware` was declared as something other than a list: a string, a bool, an
     * int, an associative map. The declaration cannot be read entry by entry, so it is refused whole.
     */
    public function malformed(): bool
    {
        return isset($this->rejected['middleware']) || !array_is_list($this->middleware);
    }

    /**
     * Every reason the declared gate cannot be carried, for a human — empty when it can.
     *
     * One entry per declared entry that fails, described: `Acme\Nope (class does not exist)`,
     * `stdClass (not a PSR-15 middleware)`, `int (not a class name)`, `(empty)`. A declaration that is
     * not a list at all yields one entry naming what was received: `string (not a list)`.
     *
     * @return list<string>
     */
    public function unresolvedMiddleware(): array
    {
        if ($this->malformed()) {
            return [\sprintf('%s (not a list)', $this->rejected['middleware'] ?? 'array')];
        }

        $unresolved = [];
        foreach ($this->middleware as $entry) {
            $defect = self::middlewareDefect($entry);
            if ($defect !== null) {
                $unresolved[] = $defect;
            }
        }

        return $unresolved;
    }

    /**
     * The middleware the panel's routes actually carry.
     *
     * The declared stack only when every entry is a string naming a class that exists and implements
     * {@see MiddlewareInterface} — a literally empty list included: the app opened the panel on purpose.
     * Anything else replaces the WHOLE stack with the strict gate: a gate with a hole in it is not a
     * gate, and mixing the half that loads with a silent fallback would hide which half is running.
     *
     * @return list<class-string>
     */
    public function effectiveMiddleware(): array
    {
        if ($this->malformed()) {
            return [LoopbackOnlyMiddleware::class];
        }

        $stack = [];
        foreach ($this->middleware as $entry) {
            if (!self::isMiddlewareClass($entry)) {
                return [LoopbackOnlyMiddleware::class];
            }
            $stack[] = $entry;
        }

        return $stack;
    }

    /**
     * What kind of gate the panel is behind. The topbar chip says {@see self::gateLabel()}, which is this
     * except for the one custom stack it knows by name.
     *
     * @return string `loopback` (the strict default, declared or not) | `custom` (the app's own stack) | `open` (a literally empty list, on purpose) | `fallback` (a misdeclared stack fell to loopback-only)
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

    /**
     * The gate as the panel names it — {@see self::gateKind()}, except that a stack that is exactly
     * app-runtime's passkey gate (that one class, loadable, alone) is named `passkey`, not `custom`.
     * A presentation rule over the kind, nothing more: the kind stays `custom` and the routes carry the
     * class as declared; a passkey gate that cannot be loaded is a `fallback` like any other.
     *
     * @return string `loopback` | `custom` | `passkey` | `open` | `fallback`
     */
    public function gateLabel(): string
    {
        $effective = $this->effectiveMiddleware();
        if (\count($effective) === 1 && strcasecmp(ltrim($effective[0], '\\'), self::PASSKEY_GATE) === 0) {
            return self::GATE_PASSKEY;
        }

        return $this->gateKind();
    }

    /**
     * Why one declared middleware entry cannot be a panel gate, described for a human — or null when
     * the runtime can load it: a non-empty string naming a class that exists and is a PSR-15 middleware.
     */
    public static function middlewareDefect(mixed $entry): ?string
    {
        if (!\is_string($entry)) {
            return get_debug_type($entry) . ' (not a class name)';
        }
        if ($entry === '') {
            return self::EMPTY;
        }
        if (!class_exists($entry)) {
            return $entry . ' (class does not exist)';
        }
        if (!is_a($entry, MiddlewareInterface::class, true)) {
            return $entry . ' (not a PSR-15 middleware)';
        }

        return null;
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
     * The same test {@see self::effectiveMiddleware()} applies, as a predicate the type checker follows.
     *
     * @phpstan-assert-if-true class-string<MiddlewareInterface> $entry
     */
    private static function isMiddlewareClass(mixed $entry): bool
    {
        return \is_string($entry) && $entry !== '' && class_exists($entry) && is_a($entry, MiddlewareInterface::class, true);
    }

    /**
     * One key judged: left out → the default, `default`; accepted → as accepted, `config`; written but
     * refused → the default, `rejected`, with what was written described.
     *
     * @return array{0: string, 1: string, 2: string|null} value, source, and the description of a rejected declaration
     */
    private static function judge(mixed $raw, ?string $accepted, string $default): array
    {
        if ($raw === null) {
            return [$default, self::SOURCE_DEFAULT, null];
        }
        if ($accepted !== null) {
            return [$accepted, self::SOURCE_CONFIG, null];
        }

        return [$default, self::SOURCE_REJECTED, self::describe($raw)];
    }

    /** A declared value for a human: the string itself (an empty one as {@see self::EMPTY}), the type of anything else. */
    private static function describe(mixed $raw): string
    {
        if (!\is_string($raw)) {
            return get_debug_type($raw);
        }

        return $raw === '' ? self::EMPTY : $raw;
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
