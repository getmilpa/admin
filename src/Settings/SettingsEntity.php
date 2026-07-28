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

use Milpa\Data\EntityInterface;

/**
 * El único registro de configuración del panel Milpa Admin — single-tenant, single-record, por eso
 * su id es la constante fija `'admin'` en lugar de un id autoincremental: no existe un segundo
 * registro con el que colisionar. `toArray()`/`fromArray()` round-trip completo (incluido el id) para
 * cumplir el contrato de {@see EntityInterface}.
 */
final class SettingsEntity implements EntityInterface
{
    private const string ID = 'admin';

    public function __construct(
        public readonly string $siteName,
        public readonly bool $maintenance,
        public readonly string $theme,
    ) {
    }

    /**
     * El id fijo `'admin'` — este registro nunca se guarda bajo ningún otro id.
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Proyecta este registro a un array plano, incluido el id fijo bajo la llave `'id'`.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => self::ID,
            'siteName' => $this->siteName,
            'maintenance' => $this->maintenance,
            'theme' => $this->theme,
        ];
    }

    /**
     * Reconstruye el registro desde el array producido por {@see self::toArray()}, tolerando llaves
     * faltantes con los valores por defecto (`'Milpa Admin'` / `false` / `'light'`).
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): static
    {
        return new self(
            siteName: (string) ($row['siteName'] ?? 'Milpa Admin'),
            maintenance: (bool) ($row['maintenance'] ?? false),
            theme: (string) ($row['theme'] ?? 'light'),
        );
    }
}
