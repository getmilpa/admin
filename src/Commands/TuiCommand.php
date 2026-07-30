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

namespace Milpa\Admin\Commands;

use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Live\Tui\StreamTerminal;
use Milpa\Console\State\InspectableSections;
use Milpa\Console\Tui\ConsoleScreen;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * El tercer shell del Milpa Admin: un motor, dos audiencias.
 *
 * El mismo estado por sección que el shell web y que `coa:admin`, servido a
 * quien pregunte en la forma que esa audiencia lee:
 *
 * - `--format=tui` (por defecto) — dashboard retenido y navegable para una
 *   persona: las secciones son la unidad de navegación (Tab, flechas, dígitos).
 * - `--format=json` — headless, para un agente: la misma lista de secciones y
 *   el mismo estado, sin terminal de por medio y sin una sola secuencia ANSI.
 *   Es el modo que sobrevive a una tubería, a CI y a no tener TTY.
 *
 * Las dos salen de {@see InspectableSections}, que es el motor. Armar la lista
 * dos veces es cómo el JSON y el TUI terminan contestando cosas distintas sobre
 * la misma app.
 *
 * El host no arma nodos: toma el `state()` de la sección, se lo pasa al mapper
 * del tier y le pone las palabras que el mapper no decide (ADR-0027). Lo que
 * pinta —y lo que no pierde— es del paquete.
 */
#[AsCommand(name: 'coa:tui')]
final class TuiCommand extends Command
{
    private const FORMAT_TUI = 'tui';
    private const FORMAT_JSON = 'json';

    public function __construct(private readonly DIContainerInterface $container, ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('El tercer shell del Milpa Admin: el estado de las secciones en un TUI navegable o en JSON headless')
            ->addArgument('section', InputArgument::OPTIONAL, 'El id de la sección a mostrar (sin argumento: la primera, y en JSON todas)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'tui (dashboard humano) o json (headless, para agentes)', self::FORMAT_TUI);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var PluginsManagerInterface $plugins */
        $plugins = $this->container->get(PluginsManagerInterface::class);
        $sections = new InspectableSections($plugins->getPlugins());

        $format = (string) $input->getOption('format');
        if (!\in_array($format, [self::FORMAT_TUI, self::FORMAT_JSON], true)) {
            $output->writeln("<error>Formato desconocido: '{$format}'. Usa tui o json.</error>");

            return Command::INVALID;
        }

        $sectionId = $input->getArgument('section');
        $sectionId = $sectionId === null ? null : (string) $sectionId;

        if ($sectionId !== null && $sections->find($sectionId) === null) {
            $output->writeln("<error>La sección '{$sectionId}' no expone estado inspectable.</error>");
            $output->writeln('Secciones con estado: ' . ($sections->ids() === [] ? '(ninguna)' : implode(', ', $sections->ids())));

            return Command::FAILURE;
        }

        return $format === self::FORMAT_JSON
            ? $this->headless($sections, $sectionId, $output)
            : $this->dashboard($sections, $sectionId, $output);
    }

    /**
     * El modo que lee un agente: la lista de secciones y su estado, en JSON.
     *
     * Sin argumento devuelve TODAS, porque una llamada que entrega el panorama
     * completo es la diferencia entre un agente que consulta y uno que sondea.
     */
    private function headless(InspectableSections $sections, ?string $sectionId, OutputInterface $output): int
    {
        $elegidas = $sectionId === null
            ? $sections->all()
            : array_filter($sections->all(), static fn (array $s): bool => $s['id'] === $sectionId);

        $payload = ['sections' => []];
        foreach ($elegidas as $section) {
            $payload['sections'][] = [
                'id' => $section['id'],
                'title' => $section['title'],
                'href' => $section['href'],
                'state' => $section['provider']->state(),
            ];
        }

        // Sin escapar barras ni unicode: un agente lee rutas y acentos, y
        // `\/` y `á` son ruido que tiene que deshacer para usarlos.
        $output->writeln((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return Command::SUCCESS;
    }

    /**
     * El modo que mira una persona: el dashboard navegable.
     *
     * Si el destino no es una terminal —una tubería, un redirect, CI— no hay con
     * qué ser interactivo: se emite un frame y se sale. `runOn()` no trae esta
     * compuerta a propósito (ADR-0025): es un hecho del DESTINO, y lo sabe quien
     * tiene el stream, o sea este comando.
     */
    private function dashboard(InspectableSections $sections, ?string $sectionId, OutputInterface $output): int
    {
        if ($sections->ids() === []) {
            $output->writeln('<error>Ninguna sección expone estado inspectable.</error>');

            return Command::FAILURE;
        }

        $terminal = new StreamTerminal('milpa · admin');
        $screen = new ConsoleScreen(
            $sections,
            $terminal->columns(),
            $terminal->rows(),
            true,
            $sectionId,
        );

        if (!$this->isInteractive()) {
            $output->writeln($screen->render());

            return Command::SUCCESS;
        }

        $screen->loop()->runOn($terminal);

        return Command::SUCCESS;
    }

    /**
     * Si la entrada es una terminal de verdad.
     */
    private function isInteractive(): bool
    {
        return function_exists('stream_isatty') && @stream_isatty(STDIN);
    }
}
