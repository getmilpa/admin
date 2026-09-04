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
 * What the app declared about its admin panel, read once from the `admin` key of its config bag.
 *
 * Every knob has a default that keeps a fresh app both safe and served: the panel lives at
 * `/milpa/admin`, speaks English, answers only to loopback, and signs component state with a
 * secret derived from this package unless the app declares one (`admin.secret`, else `live.secret`).
 * Declaring is the whole interface — nothing here is read from the environment.
 */
final readonly class AdminSettings
{
    public const DEFAULT_ROUTE = '/milpa/admin';
    public const DEFAULT_LOCALE = 'en';
    public const DEFAULT_TITLE = 'Milpa Admin';

    /**
     * @param string             $route      the panel's mount point, an absolute local path
     * @param string             $locale     the language of the panel's own copy (`en` or `es`)
     * @param list<class-string> $middleware PSR-15 middleware attached to EVERY admin route, outermost first
     * @param string             $secret     the HMAC secret that signs component state envelopes
     * @param string             $title      the brand shown in the sidebar and the document title
     */
    public function __construct(
        public string $route = self::DEFAULT_ROUTE,
        public string $locale = self::DEFAULT_LOCALE,
        public array $middleware = [LoopbackOnlyMiddleware::class],
        public string $secret = '',
        public string $title = self::DEFAULT_TITLE,
    ) {
    }

    /**
     * Reads the `admin.*` keys, falling back to the defaults for anything the app did not declare.
     *
     * A missing config bag (the plugin booted without a kernel, as in unit tests) yields the defaults.
     */
    public static function fromConfig(?Config $config): self
    {
        $admin = $config?->get('admin');
        $admin = \is_array($admin) ? $admin : [];
        $live = $config?->get('live');
        $live = \is_array($live) ? $live : [];

        $route = $admin['route'] ?? self::DEFAULT_ROUTE;
        $locale = $admin['locale'] ?? self::DEFAULT_LOCALE;
        $middleware = $admin['middleware'] ?? [LoopbackOnlyMiddleware::class];
        $secret = $admin['secret'] ?? ($live['secret'] ?? '');
        $title = $admin['title'] ?? self::DEFAULT_TITLE;

        return new self(
            route: self::normalizeRoute(\is_string($route) ? $route : self::DEFAULT_ROUTE),
            locale: \is_string($locale) && $locale !== '' ? $locale : self::DEFAULT_LOCALE,
            middleware: \is_array($middleware) ? array_values(array_filter($middleware, 'is_string')) : [LoopbackOnlyMiddleware::class],
            secret: \is_string($secret) && $secret !== '' ? $secret : self::derivedSecret(),
            title: \is_string($title) && $title !== '' ? $title : self::DEFAULT_TITLE,
        );
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

    /** The signing secret, derived when the app declared none — stable per install, never empty. */
    public function signingSecret(): string
    {
        return $this->secret !== '' ? $this->secret : self::derivedSecret();
    }

    private static function normalizeRoute(string $route): string
    {
        $normalized = '/' . trim($route, '/');

        return $normalized === '/' ? self::DEFAULT_ROUTE : $normalized;
    }

    private static function derivedSecret(): string
    {
        return hash('sha256', __DIR__ . '|milpa-admin|signing');
    }
}
