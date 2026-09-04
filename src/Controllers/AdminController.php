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

use Milpa\Admin\Components\UnknownComponentException;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionConflictException;
use Milpa\Admin\View\AdminPage;
use Milpa\Admin\View\AdminShell;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The panel's pages, PSR-7 in and PSR-7 out — the convention `milpa/runtime` dispatches.
 *
 * Every request discovers the sections again from the plugin instances the kernel holds, so what the
 * sidebar lists is what booted, not what was cached. Without a kernel in the container (the app's
 * `public/index.php` registers it) the panel still serves its own sections.
 */
final class AdminController
{
    /**
     * @param object $self the admin plugin instance — the one provider the panel can count on without a kernel
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly object $self,
        private readonly Catalog $catalog,
        private readonly AdminShell $shell,
        private readonly AdminPage $page,
    ) {
    }

    /** `GET {route}` — the first section in sidebar order. */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->show(null);
    }

    /** `GET {route}/s/{id}` — one section, 404 when no plugin declared it. */
    public function section(ServerRequestInterface $request): ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::ATTRIBUTE);
        $id = $result instanceof RouteResult ? (string) ($result->parameters['id'] ?? '') : '';

        return $this->show($id);
    }

    private function show(?string $id): ResponseInterface
    {
        try {
            $catalogue = SectionCatalogue::discover($this->plugins());
        } catch (SectionConflictException $conflict) {
            return $this->html(500, $this->page->error(500, $this->catalog->tr('section.conflict', $conflict->getMessage())));
        }

        if ($catalogue->isEmpty()) {
            return $this->html(200, $this->page->render($this->shell->renderEmpty($catalogue)));
        }

        $active = $id === null ? $catalogue->first() : $catalogue->find($id);
        if (!$active instanceof AdminSection) {
            return $this->html(404, $this->page->error(404, $this->catalog->tr('section.unknown', (string) $id)));
        }

        try {
            $body = $this->shell->render($catalogue, $active);
        } catch (UnknownComponentException $unknown) {
            return $this->html(500, $this->page->error(500, $this->catalog->tr('section.conflict', $unknown->getMessage())));
        }

        return $this->html(200, $this->page->render($body, $this->shell->title($active)));
    }

    /**
     * The booted plugin instances — from the kernel when the app registered it, else the panel alone.
     *
     * @return list<object>
     */
    private function plugins(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        $plugins = $kernel instanceof Kernel ? $kernel->plugins() : [];

        foreach ($plugins as $plugin) {
            if ($plugin === $this->self) {
                return $plugins;
            }
        }
        $plugins[] = $this->self;

        return $plugins;
    }

    private function html(int $status, string $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }
}
