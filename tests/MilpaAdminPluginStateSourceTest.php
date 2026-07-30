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

use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Console\Contracts\RouteTableSource;
use Milpa\Admin\Tests\Fixtures\FixedRouteTable;
use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Settings\SettingsEntity;
use Milpa\Admin\Settings\SettingsRepository;
use Milpa\Console\State\SectionStateProvider;
use Milpa\Console\State\SectionStateSource;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * `MilpaAdminPlugin` es un `SectionStateSource`: declara el estado de SUS secciones (settings +
 * plugins + system), cada uno un `SectionStateProvider` real — el mismo estado que el shell web consume,
 * ahora disponible para el shell CLI. Aislado en su proceso por el constant global `rootPath` (el load
 * de rutas de `system` lo usa).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MilpaAdminPluginStateSourceTest extends TestCase
{
    private string $settingsFile;
    private string|false $previousSettingsPath;

    protected function setUp(): void
    {
        if (!defined('rootPath')) {
            define('rootPath', dirname(__DIR__, 4));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
        $this->previousSettingsPath = getenv('MILPA_ADMIN_SETTINGS_PATH');
        $this->settingsFile = sys_get_temp_dir() . '/milpa-admin-statesource-' . bin2hex(random_bytes(4)) . '.json';
        putenv('MILPA_ADMIN_SETTINGS_PATH=' . $this->settingsFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousSettingsPath === false) {
            putenv('MILPA_ADMIN_SETTINGS_PATH');
        } else {
            putenv('MILPA_ADMIN_SETTINGS_PATH=' . $this->previousSettingsPath);
        }
        if (is_file($this->settingsFile)) {
            unlink($this->settingsFile);
        }
    }

    private function container(): DIContainerInterface
    {
        $container = $this->createMock(DIContainerInterface::class);
        $dispatcher = new EventDispatcher();
        $logger = new NullLogger();
        // La tabla de rutas entra por el puerto: el panel no sabe cómo la arma su host.
        $routes = FixedRouteTable::withOneRoute();
        $container->method('get')->willReturnCallback(static function (string $id) use ($logger, $dispatcher, $routes) {
            return match ($id) {
                LoggerInterface::class => $logger,
                EventDispatcherInterface::class => $dispatcher,
                RouteTableSource::class => $routes,
                default => null,
            };
        });
        // `tryGet` responde lo mismo que `get`: un mock que sólo enseña uno deja invisible
        // todo servicio OPCIONAL, y la sección que dependa de él desaparece en silencio.
        $container->method('tryGet')->willReturnCallback(
            static fn (string $id): mixed => $container->get($id),
        );

        return $container;
    }

    public function test_declares_settings_plugins_and_system_as_state_providers(): void
    {
        (new SettingsRepository($this->settingsFile))->save(new SettingsEntity('Acme', false, 'light'));

        $plugin = new AdminPlugin($this->container());
        self::assertInstanceOf(SectionStateSource::class, $plugin);

        $states = $plugin->sectionStates();

        self::assertSame(['settings', 'plugins', 'system'], array_keys($states));
        self::assertInstanceOf(SectionStateProvider::class, $states['settings']);
        self::assertInstanceOf(SectionStateProvider::class, $states['system']);
        // settings → la config persistida; system → la tabla de rutas (con la `/` de HomeController).
        self::assertSame('Acme', $states['settings']->state()['siteName']);
        $routes = $states['system']->state()['routes'];
        self::assertContains('/', array_column($routes, 'path'));
    }
}
