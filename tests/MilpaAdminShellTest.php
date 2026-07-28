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

namespace Milpa\Admin\Tests;

use Milpa\Auth\ActorType;
use Milpa\Auth\Contracts\SessionStore;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\SessionRecord;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Admin\Controllers\SettingsController;
use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Tests\Fixtures\NeighbourPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fake `PluginsManagerInterface` mínimo para este archivo: la Tarea 5 hace que
 * {@see SettingsController::show()} resuelva el discovery (ADR#12) — envuelve los dos providers
 * reales (mismo par que {@see MilpaAdminSettingsGetTest}) para que el shell renderizado traiga el
 * sidebar real.
 */
final class ShellTestFakePluginsManager implements PluginsManagerInterface
{
    /** @param array<string, PluginInterface> $plugins */
    public function __construct(private readonly array $plugins)
    {
    }

    public function addPluginPath(string $path): void
    {
    }

    public function loadPlugins(): void
    {
    }

    public function getToolProviderPromptSections(): array
    {
        return [];
    }

    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function getPlugin(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    public function isEnabled(string $name): bool
    {
        return true;
    }
}

/**
 * Task 5 — {@see \Milpa\Admin\Http\ShellRenderHandler} behind the gate now
 * renders the REAL milpa/live-web dashboard shell (`DashboardHtmlRenderer` +
 * `XhtmlComponentCompiler` over `dashboard-shell`/`dashboard-sidebar`/`dashboard-main`), not the
 * Task-4 placeholder.
 *
 * P5.3b (Task 4 of "Milpa Admin Client Runtime") composed `dashboard-topbar` back in: ADR#5 is
 * SALDADO, not violated — the toggle button (`mui-topbar__nav-toggle`) exists in the markup now,
 * but stays JS-gated by the `html:not(.milpa-js) .mui-topbar__nav-toggle{display:none}` rule
 * {@see \Milpa\Admin\View\AdminPage} emits, so it is never a dead control: without
 * the client runtime loaded it simply never reveals, and with it loaded (`milpa-live.js` sets
 * `<html class="milpa-js">`) Alpine backs its `@click`. The old "no toggle at all" invariant this
 * test asserted is therefore obsolete; the new invariant is "toggle present AND gated" — see
 * {@see MilpaAdminSettingsGetTest::testRenderedShellComposesTheTopbarAndEmitsTheClientRuntimeScripts()}
 * for the fuller assertion set (scripts order, `aria-controls` == sidebar `id`).
 *
 * `MILPA_DESIGN_PATH` is forced to a path that does not exist (not left ambient) so this suite is
 * deterministic AND exercises {@see \Milpa\Admin\View\AdminPage}'s
 * structure-only fallback on purpose: the real `@milpa/design` CSS (checked against the sibling
 * `../milpa-design` checkout on this machine) genuinely contains the substring "toggle" (nav-toggle
 * / motion-transition classes unrelated to this handler's own markup) — inlining it here would
 * make a literal `assertStringContainsString('toggle', ...)` pass for a reason that has nothing to
 * do with what this handler renders. The CSS-resolves success path is proven separately in
 * {@see \Tests\Unit\Plugins\MilpaAdminPlugin\View\AdminPageTest}.
 */
final class MilpaAdminShellTest extends TestCase
{
    private const COOKIE = 'milpa_session';

    private string|false $previousDesignPath = false;

    protected function setUp(): void
    {
        if (!defined('rootPath')) {
            define('rootPath', dirname(__DIR__, 4));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        $this->previousDesignPath = getenv('MILPA_DESIGN_PATH');
        putenv('MILPA_DESIGN_PATH=' . sys_get_temp_dir() . '/milpa-design-does-not-exist');
    }

    protected function tearDown(): void
    {
        if ($this->previousDesignPath === false) {
            putenv('MILPA_DESIGN_PATH');
        } else {
            putenv('MILPA_DESIGN_PATH=' . $this->previousDesignPath);
        }
    }

    private function session(string $sessionId, array $scopes): SessionRecord
    {
        $now = new \DateTimeImmutable();

        return new SessionRecord(
            id: $sessionId,
            actorId: 'actor-' . $sessionId,
            actorType: ActorType::User,
            createdAt: $now,
            expiresAt: $now->modify('+1 hour'),
            scopes: $scopes,
        );
    }

    private function renderShellFor(string $sessionId): string
    {
        $store = new InMemorySessionStore();
        $store->write($this->session($sessionId, ['milpa.admin']));

        $container = new DIContainer();
        $container->registerService(LoggerInterface::class, new NullLogger());
        $container->registerService(SessionStore::class, $store);
        $container->registerService(PluginsManagerInterface::class, new ShellTestFakePluginsManager([
            'milpa-admin' => new AdminPlugin($container),
            'neighbour' => new NeighbourPlugin($container),
        ]));

        // Ola 5b: HttpResponderInterface, misma construcción que los extintos WebManager/CliManager
        // registraban tras loadPlugins() (Ola 7c) — BaseController (el paquete) lo resuelve en el constructor.
        $container->registerService(
            \Milpa\Http\Symfony\HttpResponderInterface::class,
            new \Milpa\Admin\Tests\Fixtures\EchoResponder(),
        );

        $controller = new SettingsController($container);
        $request = Request::create('/milpa/admin/settings', 'GET', [], [self::COOKIE => $sessionId]);
        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getContent();
    }

    public function test_shell_renders_real_dashboard_markup_behind_the_gate(): void
    {
        $html = $this->renderShellFor('admin-1');

        self::assertStringContainsString('data-milpa-component-id', $html);
        self::assertStringContainsString('dashboard-sidebar', $html);
        self::assertStringContainsString('Settings', $html);

        // P5.3b (ADR#5 saldado): el toggle YA existe — no es un control muerto porque queda
        // JS-gated por la regla de reveal que AdminPage emite (Tarea 3). Ya NO se afirma su
        // ausencia; se afirma la nueva invariante: presente Y gateado.
        self::assertStringContainsString('mui-topbar__nav-toggle', $html);
        self::assertStringContainsString('html:not(.milpa-js) .mui-topbar__nav-toggle', $html);
    }
}
