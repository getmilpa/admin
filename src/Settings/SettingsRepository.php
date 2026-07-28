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

namespace Milpa\Admin\Settings;

use Milpa\Data\FileRepository;

/**
 * Persistencia de {@see SettingsEntity} sobre un {@see FileRepository} propio — un wrapper delgado
 * que fija la entidad y expone `current()` con los valores por defecto cuando todavía no se guardó
 * nada. El `$file` lo decide quien construye este repositorio (producción vs. test apuntan a rutas
 * distintas); esta clase no conoce ni asume la ruta de storage final.
 */
final class SettingsRepository
{
    /** @var FileRepository<SettingsEntity> */
    private readonly FileRepository $files;

    public function __construct(string $file)
    {
        $this->files = new FileRepository($file, SettingsEntity::class);
    }

    /**
     * Guarda la configuración bajo el id fijo `'admin'`.
     */
    public function save(SettingsEntity $settings): void
    {
        $this->files->save($settings);
    }

    /**
     * La configuración actual, o los valores por defecto (`'Milpa Admin'` / `false` / `'light'`)
     * cuando todavía no se guardó nada.
     */
    public function current(): SettingsEntity
    {
        /** @var SettingsEntity|null $stored */
        $stored = $this->files->find('admin');

        return $stored ?? new SettingsEntity('Milpa Admin', false, 'light');
    }
}
