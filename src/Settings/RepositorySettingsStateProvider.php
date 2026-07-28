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

/**
 * Adaptador de {@see SettingsStateProvider} que lee de {@see SettingsRepository} — proyecta
 * {@see SettingsEntity::toArray()} sin la llave `'id'`, que es un detalle de persistencia y no parte
 * de la configuración visible.
 */
final class RepositorySettingsStateProvider implements SettingsStateProvider
{
    public function __construct(
        private readonly SettingsRepository $repository,
    ) {
    }

    /**
     * Los valores de configuración actuales, sin el id interno.
     *
     * @return array<string,mixed>
     */
    public function state(): array
    {
        $row = $this->repository->current()->toArray();
        unset($row['id']);

        return $row;
    }
}
