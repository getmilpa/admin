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

namespace Milpa\Admin\Controllers;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Components\RendererConflictException;
use Milpa\Admin\Components\ReservedComponentException;
use Milpa\Admin\Components\UnknownComponentException;
use Milpa\Admin\Http\RequestPrincipal;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\BootedPlugins;
use Milpa\Admin\Section\SeedConflictException;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionConflictException;
use Milpa\Admin\View\AdminPage;
use Milpa\Admin\View\AdminShell;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Http\LiveBoot;
use Milpa\Live\Runtime\ComponentNameConflictException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The panel's pages, PSR-7 in and PSR-7 out — the convention `milpa/runtime` dispatches.
 *
 * Every request discovers the sections again from the plugin instances the kernel holds, so what the
 * sidebar lists is what booted, not what was cached. Without a kernel in the container (the app's
 * `public/index.php` registers it) the panel still serves its own sections.
 *
 * The language is the app's (`admin.locale`) unless the request says `?lang=<locale>` with a locale the
 * {@see Catalog} carries — the browser-side override the Settings section offers. The rest of the query
 * travels to the active section as `props['query']`, so a section can read its own (`?session=<id>` opens
 * a timeline inside Dev tools); the shell never interprets it.
 *
 * Who is signed in is whatever the gate in front of the route left on the request ({@see RequestPrincipal}):
 * the panel reads no cookie and mints no identity — it shows the actor the gate authenticated, or nobody.
 *
 * Every page it serves carries ONE live boot (greenhouse decisions/0211): a fresh page session and its
 * CSRF token, bound to the panel's own wire ({@see AdminSettings::liveUrl()}), issued here and emitted by
 * {@see AdminPage} through `LiveBoot::html()` together with whatever the compile declared. An error
 * document gets none: there is no live component on it.
 */
final class AdminController
{
    public const LANG_PARAM = 'lang';

    /**
     * @param object $self the admin plugin instance — the one provider the panel can count on without a kernel
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly object $self,
        private readonly Catalog $catalog,
        private readonly AdminShell $shell,
        private readonly AdminPage $page,
        private readonly CsrfGuardInterface $csrf,
        private readonly AdminSettings $settings,
    ) {
    }

    /** `GET {route}` — the first section in sidebar order. */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->show(null, $this->catalogFor($request), self::queryParams($request), RequestPrincipal::of($request));
    }

    /** `GET {route}/s/{id}` — one section, 404 when no plugin declared it. */
    public function section(ServerRequestInterface $request): ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::ATTRIBUTE);
        $id = $result instanceof RouteResult ? (string) ($result->parameters['id'] ?? '') : '';

        return $this->show($id, $this->catalogFor($request), self::queryParams($request), RequestPrincipal::of($request));
    }

    /**
     * The catalog this request reads in: the one `?lang=` names when the catalog carries that locale,
     * else the panel's own.
     */
    public function catalogFor(ServerRequestInterface $request): Catalog
    {
        $lang = self::query($request, self::LANG_PARAM);
        if ($lang === null || $lang === $this->catalog->locale() || !\in_array($lang, Catalog::locales(), true)) {
            return $this->catalog;
        }

        return new Catalog($lang);
    }

    /**
     * @param array<string, mixed> $query     the request's query params, handed to the active section
     * @param string|null          $principal the actor the gate authenticated, or null when nobody is signed in
     */
    private function show(?string $id, Catalog $catalog, array $query, ?string $principal): ResponseInterface
    {
        $shell = $this->shell->withCatalog($catalog);
        $page = $this->page->withCatalog($catalog);

        try {
            $catalogue = SectionCatalogue::discover(BootedPlugins::of($this->container, $this->self));
        } catch (SectionConflictException $conflict) {
            return $this->html(500, $page->error(500, $catalog->tr('section.conflict', $conflict->getMessage())));
        }

        $boot = LiveBoot::issue($this->csrf, $this->settings->liveUrl());

        if ($catalogue->isEmpty()) {
            $composed = $shell->composeEmpty($catalogue, $principal);

            return $this->html(200, $page->render($composed->html, '', $boot, $composed->assets, $composed->seeds));
        }

        $active = $id === null ? $catalogue->first() : $catalogue->find($id);
        if (!$active instanceof AdminSection) {
            $present = implode(', ', array_map(static fn (AdminSection $s): string => $s->id, $catalogue->sections()));

            return $this->html(404, $page->error(
                404,
                $catalog->tr('section.unknown', (string) $id) . ' ' . $catalog->tr('section.present', $present),
            ));
        }

        try {
            $composed = $shell->compose($catalogue, $active, $query, $principal);
        } catch (UnknownComponentException|ReservedComponentException|ComponentNameConflictException|RendererConflictException|SeedConflictException $refused) {
            // A DECLARATION the panel cannot honour — a name nothing registered, a name two sections bind
            // to different definitions or different renderers, a signal seeded twice with different values.
            // Not the same thing as a component that throws while rendering, which paints its own region
            // and leaves the panel standing.
            return $this->html(500, $page->error(500, $catalog->tr('section.conflict', $refused->getMessage())));
        }

        return $this->html(200, $page->render($composed->html, $shell->title($active), $boot, $composed->assets, $composed->seeds));
    }

    /**
     * One query parameter as a string — from the parsed params when the host filled them, else from
     * the URI's own query string, so `?lang=` works however the request was built.
     */
    private static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = self::queryParams($request)[$name] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Every query parameter of the request — the parsed params when the host filled them, else the
     * URI's own query string parsed here, so a section reads the same query however the request was built.
     *
     * @return array<string, mixed>
     */
    private static function queryParams(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        if ($params === []) {
            parse_str($request->getUri()->getQuery(), $params);
        }

        $out = [];
        foreach ($params as $name => $value) {
            $out[(string) $name] = $value;
        }

        return $out;
    }

    private function html(int $status, string $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }
}
