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
use Milpa\Console\Contracts\RouteTableSource;
use Milpa\Admin\Tests\Fixtures\FixedRouteTable;
use Milpa\Admin\Commands\TuiCommand;
use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Settings\SettingsEntity;
use Milpa\Admin\Settings\SettingsRepository;
use Milpa\Console\State\InspectableSections;
use Milpa\Console\Tui\ConsoleScreen;
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
 * `coa:tui` — el TERCER shell del admin: un motor, dos audiencias.
 *
 * Las dos mitades del *done when* de P6 se prueban acá sin una terminal, que es
 * lo que las hace probables: la navegación entre secciones vive en
 * {@see ConsoleScreen}, que no toca `stty` ni `stream_isatty`, y el JSON
 * headless es justamente el modo que existe para cuando no hay TTY.
 *
 * Aislado en su proceso por el constant global `rootPath`.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TuiCommandTest extends TestCase
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
        $this->settingsFile = sys_get_temp_dir() . '/milpa-tui-cmd-' . bin2hex(random_bytes(4)) . '.json';
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

    private function sections(): InspectableSections
    {
        $container = $this->createMock(DIContainerInterface::class);
        $logger = new NullLogger();
        $manager = new TuiCommandFakePluginsManager([
            'milpa-admin' => new AdminPlugin($container),
            'neighbour' => new NeighbourPlugin($container),
        ]);
        $routes = FixedRouteTable::withOneRoute();
        $container->method('get')->willReturnCallback(static fn (string $id) => match ($id) {
            LoggerInterface::class => $logger,
            EventDispatcherInterface::class => new EventDispatcher(),
            PluginsManagerInterface::class => $manager,
            RouteTableSource::class => $routes,
            default => null,
        });
        // `tryGet` responde lo mismo que `get`: un mock que sólo enseña uno deja invisible
        // todo servicio OPCIONAL, y la sección que dependa de él desaparece en silencio.
        $container->method('tryGet')->willReturnCallback(
            static fn (string $id): mixed => $container->get($id),
        );

        return new InspectableSections($manager->getPlugins());
    }

    private function tester(): CommandTester
    {
        $container = $this->createMock(DIContainerInterface::class);
        $logger = new NullLogger();
        $manager = new TuiCommandFakePluginsManager([
            'milpa-admin' => new AdminPlugin($container),
            'neighbour' => new NeighbourPlugin($container),
        ]);
        $routes = FixedRouteTable::withOneRoute();
        $container->method('get')->willReturnCallback(static fn (string $id) => match ($id) {
            LoggerInterface::class => $logger,
            EventDispatcherInterface::class => new EventDispatcher(),
            PluginsManagerInterface::class => $manager,
            RouteTableSource::class => $routes,
            default => null,
        });
        // `tryGet` responde lo mismo que `get`: un mock que sólo enseña uno deja invisible
        // todo servicio OPCIONAL, y la sección que dependa de él desaparece en silencio.
        $container->method('tryGet')->willReturnCallback(
            static fn (string $id): mixed => $container->get($id),
        );

        return new CommandTester(new TuiCommand($container));
    }

    // ---- el motor compartido ------------------------------------------------------

    public function test_the_engine_lists_only_sections_that_expose_state(): void
    {
        // 'architecture' es una sección válida y web-only: sin estado inspectable
        // no hay nada que mostrar ni sobre qué navegar.
        $ids = $this->sections()->ids();

        self::assertContains('settings', $ids);
        self::assertContains('system', $ids);
        self::assertNotContains('architecture', $ids);
    }

    public function test_the_engine_carries_the_title_the_section_already_declared(): void
    {
        // Las dos audiencias muestran el mismo nombre porque lo toman del mismo
        // lado; el id es un apaño para una sección que expone estado sin
        // declararse en el menú.
        $settings = $this->sections()->find('settings');

        self::assertNotNull($settings);
        self::assertNotSame('', $settings['title']);
    }

    // ---- headless: la audiencia agente -----------------------------------------------

    public function test_headless_returns_every_section_in_one_call(): void
    {
        // Un agente que tiene que sondear sección por sección hace N llamadas
        // para saber lo que una debería contestar.
        $tester = $this->tester();
        $exit = $tester->execute(['--format' => 'json']);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $payload = json_decode($tester->getDisplay(), true);

        self::assertIsArray($payload);
        $ids = array_column($payload['sections'], 'id');
        self::assertContains('settings', $ids);
        self::assertContains('system', $ids);
    }

    public function test_headless_carries_the_same_state_the_dashboard_shows(): void
    {
        (new SettingsRepository($this->settingsFile))->save(new SettingsEntity('Acme Corp', false, 'dark'));

        $tester = $this->tester();
        $tester->execute(['section' => 'settings', '--format' => 'json']);

        $payload = json_decode($tester->getDisplay(), true);

        self::assertIsArray($payload);
        self::assertCount(1, $payload['sections'], 'Con sección nombrada, solo esa.');
        self::assertSame('settings', $payload['sections'][0]['id']);
        self::assertSame('Acme Corp', $payload['sections'][0]['state']['siteName'] ?? null);
    }

    public function test_headless_emits_json_and_nothing_else(): void
    {
        // Una sola línea de adorno —un título, un aviso— y el agente del otro
        // lado ya no puede parsear la salida.
        $tester = $this->tester();
        $tester->execute(['--format' => 'json']);

        self::assertNotNull(json_decode($tester->getDisplay(), true), $tester->getDisplay());
    }

    public function test_headless_does_not_escape_slashes_or_accents(): void
    {
        // Las rutas y los acentos los lee un agente; `\/` y `á` son ruido
        // que tiene que deshacer para usarlos.
        $tester = $this->tester();
        $tester->execute(['section' => 'system', '--format' => 'json']);
        $display = $tester->getDisplay();

        self::assertStringNotContainsString('\\/', $display);
        self::assertStringContainsString('"href"', $display);
    }

    // ---- el dashboard: la audiencia humana ---------------------------------------------

    public function test_the_dashboard_starts_on_the_first_section_when_none_is_named(): void
    {
        $screen = new ConsoleScreen($this->sections());

        self::assertSame($this->sections()->ids()[0], $screen->currentSectionId());
    }

    public function test_the_dashboard_starts_on_the_section_it_was_asked_for(): void
    {
        $screen = new ConsoleScreen($this->sections(), initialSection: 'system');

        self::assertSame('system', $screen->currentSectionId());
    }

    public function test_tab_moves_to_the_next_section_and_the_screen_follows(): void
    {
        // La navegación es lo que separa un dashboard de una captura de
        // pantalla: si la pantalla no cambia con el foco, no navegó nada.
        $screen = new ConsoleScreen($this->sections(), ansi: false);
        $primera = $screen->currentSectionId();
        $antes = $screen->render();

        $screen->press('tab');

        self::assertNotSame($primera, $screen->currentSectionId());
        self::assertNotSame($antes, $screen->render(), 'La pantalla siguió al foco.');
    }

    public function test_shift_tab_comes_back(): void
    {
        $screen = new ConsoleScreen($this->sections(), ansi: false);
        $primera = $screen->currentSectionId();

        $screen->press('tab');
        $screen->press('shift-tab');

        self::assertSame($primera, $screen->currentSectionId());
    }

    public function test_the_arrows_move_the_same_way_tab_does(): void
    {
        // Una lista horizontal de secciones se navega con flechas antes que con
        // Tab; que hagan cosas distintas sería el peor resultado.
        $conTab = new ConsoleScreen($this->sections(), ansi: false);
        $conFlecha = new ConsoleScreen($this->sections(), ansi: false);

        $conTab->press('tab');
        $conFlecha->press('right');

        self::assertSame($conTab->currentSectionId(), $conFlecha->currentSectionId());

        $conTab->press('shift-tab');
        $conFlecha->press('left');

        self::assertSame($conTab->currentSectionId(), $conFlecha->currentSectionId());
    }

    public function test_a_digit_jumps_straight_to_its_section(): void
    {
        // Llegar a la cuarta sección tabulando cuatro veces no es navegar.
        $screen = new ConsoleScreen($this->sections(), ansi: false);
        $ids = $this->sections()->ids();

        $screen->press('2');

        self::assertSame($ids[1], $screen->currentSectionId());

        $screen->press('1');

        self::assertSame($ids[0], $screen->currentSectionId());
    }

    public function test_a_digit_past_the_last_section_leaves_the_view_alone(): void
    {
        // Y no cae al "tecla no reconocida" del tier: lo que no existe es esa
        // sección, no la tecla.
        $screen = new ConsoleScreen($this->sections(), ansi: false);
        $antes = $screen->currentSectionId();

        $screen->press('9');

        self::assertSame($antes, $screen->currentSectionId());
    }

    public function test_the_status_bar_names_every_section_and_marks_the_current_one(): void
    {
        // Un dashboard que no dice qué más hay no se navega: se adivina.
        $screen = new ConsoleScreen($this->sections(), width: 160, ansi: false);
        $render = $screen->render();

        foreach ($this->sections()->ids() as $id) {
            self::assertStringContainsString($id, $render);
        }
        self::assertStringContainsString('[1 ' . $this->sections()->ids()[0] . ']', $render, 'La actual va marcada.');
        self::assertStringContainsString('tab · nº · q salir', $render, 'La barra anuncia exactamente las teclas que responden sobre el tier instalado.');
    }

    public function test_q_stops_the_loop(): void
    {
        $screen = new ConsoleScreen($this->sections(), ansi: false);

        self::assertFalse($screen->press('q'));
    }

    public function test_the_dashboard_reads_the_state_on_every_frame(): void
    {
        // Un dashboard que congela lo que leyó al abrirse es una captura de
        // pantalla con marco.
        (new SettingsRepository($this->settingsFile))->save(new SettingsEntity('Antes', false, 'dark'));

        $screen = new ConsoleScreen($this->sections(), width: 160, ansi: false, initialSection: 'settings');
        $antes = $screen->render();

        (new SettingsRepository($this->settingsFile))->save(new SettingsEntity('Después', false, 'dark'));
        $despues = $screen->render();

        self::assertStringContainsString('Antes', $antes);
        self::assertStringContainsString('Después', $despues);
    }

    // ---- lo que el comando rechaza -------------------------------------------------------

    public function test_an_unknown_section_is_refused_with_the_valid_ids(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['section' => 'nope']);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('no expone estado inspectable', $display);
        self::assertStringContainsString('settings', $display);
    }

    public function test_a_web_only_section_is_refused_in_both_formats(): void
    {
        // 'architecture' existe como sección pero no expone estado: las dos
        // audiencias tienen que contestar lo mismo sobre ella.
        foreach (['tui', 'json'] as $format) {
            $tester = $this->tester();
            $exit = $tester->execute(['section' => 'architecture', '--format' => $format]);

            self::assertSame(Command::FAILURE, $exit, "formato {$format}");
            self::assertStringContainsString('no expone estado inspectable', $tester->getDisplay());
        }
    }

    public function test_an_unknown_format_is_refused_by_name(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--format' => 'yaml']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('yaml', $tester->getDisplay());
    }

    public function test_without_a_terminal_the_dashboard_emits_one_frame_and_exits(): void
    {
        // Una tubería, un redirect, CI: no hay con qué ser interactivo, y
        // colgarse esperando una tecla que no llega sería lo peor que podría
        // hacer.
        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('q salir', $tester->getDisplay());
    }
}

/**
 * Fake mínimo de PluginsManagerInterface (mismo idioma que el de los otros tests
 * del admin): expone las instancias registradas; el resto son no-ops.
 */
final class TuiCommandFakePluginsManager implements PluginsManagerInterface
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
