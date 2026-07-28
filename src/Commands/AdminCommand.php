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
use Milpa\Admin\Section\AdminSectionDiscovery;
use Milpa\Admin\State\AdminSectionStateDiscovery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `coa:admin` — el SEGUNDO shell del Milpa Admin: renderiza en la terminal las mismas secciones
 * descubiertas ({@see AdminSectionDiscovery}) y el mismo estado por-sección
 * ({@see \Milpa\Admin\State\AdminSectionStateProvider}) que el shell web. Read-only,
 * confianza de proceso (sin gate `milpa.admin` ni CSRF — la seguridad web no existe en el loop CLI).
 * No construye un TUI interactivo (eso es P6): un solo shot y sale.
 */
#[AsCommand(name: 'coa:admin')]
final class AdminCommand extends Command
{
    public function __construct(private readonly DIContainerInterface $container, ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('El segundo shell del Milpa Admin: lista las secciones y muestra el estado de una en la terminal')
            ->addArgument('section', InputArgument::OPTIONAL, 'El id de la sección cuyo estado mostrar (sin argumento: lista las secciones)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var PluginsManagerInterface $plugins */
        $plugins = $this->container->get(PluginsManagerInterface::class);
        $booted = $plugins->getPlugins();

        $sectionId = $input->getArgument('section');
        if ($sectionId === null) {
            return $this->listSections($booted, $output);
        }

        return $this->showSection($booted, (string) $sectionId, $output);
    }

    /** @param iterable<object> $booted */
    private function listSections(iterable $booted, OutputInterface $output): int
    {
        $sections = (new AdminSectionDiscovery($booted))->sections();
        $withState = (new AdminSectionStateDiscovery($booted))->all();

        $table = new Table($output);
        $table->setHeaders(['Sección', 'id', 'Ruta', 'Estado inspectable']);
        foreach ($sections as $section) {
            $table->addRow([
                $section->title,
                $section->id,
                $section->href,
                isset($withState[$section->id]) ? 'sí' : 'no',
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    /** @param iterable<object> $booted */
    private function showSection(iterable $booted, string $sectionId, OutputInterface $output): int
    {
        $stateDiscovery = new AdminSectionStateDiscovery($booted);
        $provider = $stateDiscovery->providerFor($sectionId);

        if ($provider === null) {
            $ids = array_map(static fn ($s): string => $s->id, (new AdminSectionDiscovery($booted))->sections());
            if (in_array($sectionId, $ids, true)) {
                $output->writeln("<comment>La sección '{$sectionId}' no expone estado inspectable.</comment>");
            } else {
                $output->writeln("<error>Sección desconocida: '{$sectionId}'.</error>");
            }
            $output->writeln('Secciones con estado: ' . implode(', ', array_keys($stateDiscovery->all())));

            return Command::FAILURE;
        }

        $this->renderState($provider->state(), $output);

        return Command::SUCCESS;
    }

    /**
     * Render genérico del estado de una sección. Si el estado es "una lista de filas" (una sola llave
     * cuyo valor es una lista de arrays asociativos, p.ej. `['routes' => [...]]`) → tabla con esas
     * columnas. Si no (escalares planos, p.ej. la config) → tabla clave/valor.
     *
     * @param array<string,mixed> $state
     */
    private function renderState(array $state, OutputInterface $output): void
    {
        if (count($state) === 1) {
            $rows = reset($state);
            if (is_array($rows) && $rows !== [] && array_is_list($rows) && is_array($rows[0])) {
                $table = new Table($output);
                $table->setHeaders(array_map('strval', array_keys($rows[0])));
                foreach ($rows as $row) {
                    $table->addRow(array_map($this->scalar(...), array_values((array) $row)));
                }
                $table->render();

                return;
            }
        }

        $table = new Table($output);
        $table->setHeaders(['Campo', 'Valor']);
        foreach ($state as $key => $value) {
            $table->addRow([(string) $key, $this->scalar($value)]);
        }
        $table->render();
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'sí' : 'no';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '';
    }
}
