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
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Admin\Contracts\RouteTableSource;
use Milpa\Admin\Tests\Fixtures\FixedRouteTable;
use Milpa\Admin\Commands\AdminCommand;
use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Settings\SettingsEntity;
use Milpa\Admin\Settings\SettingsRepository;
use Milpa\Admin\Tests\Fixtures\NeighbourPlugin;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * `coa:admin` — el SEGUNDO shell del admin: renderiza las mismas secciones descubiertas y el mismo
 * estado por-sección que el shell web, en la terminal. Read-only, confianza de proceso (sin gate web).
 * Aislado en su proceso por el constant global `rootPath`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminCommandTest extends TestCase
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
        $this->settingsFile = sys_get_temp_dir() . '/milpa-admin-cmd-' . bin2hex(random_bytes(4)) . '.json';
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

    private function tester(): CommandTester
    {
        $container = $this->createMock(DIContainerInterface::class);
        $dispatcher = new EventDispatcher();
        $logger = new NullLogger();
        $manager = new AdminCommandFakePluginsManager([
            'milpa-admin' => new AdminPlugin($container),
            'neighbour' => new NeighbourPlugin($container),
        ]);
        // La tabla de rutas entra por el puerto: el panel no sabe cómo la arma su host.
        $routes = FixedRouteTable::withOneRoute();
        $container->method('get')->willReturnCallback(static function (string $id) use ($logger, $dispatcher, $manager, $routes) {
            return match ($id) {
                LoggerInterface::class => $logger,
                EventDispatcherInterface::class => $dispatcher,
                PluginsManagerInterface::class => $manager,
                RouteTableSource::class => $routes,
                default => null,
            };
        });
        // `tryGet` responde lo mismo que `get`: un mock que sólo enseña uno deja invisible
        // todo servicio OPCIONAL, y la sección que dependa de él desaparece en silencio.
        $container->method('tryGet')->willReturnCallback(
            static fn (string $id): mixed => $container->get($id),
        );

        return new CommandTester(new AdminCommand($container));
    }

    public function test_no_argument_lists_the_discovered_sections(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exit, $display);
        self::assertStringContainsString('settings', $display);
        self::assertStringContainsString('system', $display);
        self::assertStringContainsString('architecture', $display);
    }

    public function test_system_section_renders_the_routes_table(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['section' => 'system']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exit, $display);
        self::assertStringContainsString('path', $display);
        self::assertStringContainsString('/', $display);
    }

    public function test_settings_section_renders_the_persisted_config(): void
    {
        (new SettingsRepository($this->settingsFile))->save(new SettingsEntity('Acme Corp', false, 'dark'));

        $tester = $this->tester();
        $exit = $tester->execute(['section' => 'settings']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exit, $display);
        self::assertStringContainsString('siteName', $display);
        self::assertStringContainsString('Acme Corp', $display);
    }

    public function test_unknown_section_fails_with_valid_ids(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['section' => 'nope']);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('settings', $display);
        self::assertStringContainsString('system', $display);
    }

    public function test_valid_section_without_state_reports_no_inspectable_state(): void
    {
        // 'architecture' (aportada por NeighbourPlugin) es una sección VÁLIDA pero sin
        // AdminSectionStateProvider — web-only. El comando lo informa suave, no como sección desconocida.
        $tester = $this->tester();
        $exit = $tester->execute(['section' => 'architecture']);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('no expone estado inspectable', $display);
    }
}

/**
 * Fake mínimo de PluginsManagerInterface (mismo idioma que el de los tests de settings/system):
 * expone las instancias de plugin registradas; el resto son no-ops.
 */
final class AdminCommandFakePluginsManager implements PluginsManagerInterface
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
        return isset($this->plugins[$name]);
    }
}
