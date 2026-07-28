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

namespace Milpa\Admin\Tests\Fixtures;

use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;

/**
 * Un plugin vecino que aporta su propia sección al panel.
 *
 * Existe porque lo que estos tests prueban NO es que el panel pinte sus tres secciones: es que
 * descubra las de OTROS. Antes ese vecino era un plugin del host, lo cual amarraba el paquete a una
 * app concreta y —peor— hacía que la prueba dependiera de que esa app siguiera teniendo una sección.
 *
 * Su `order` es 20 a propósito: cae entre Plugins (15) y Sistema (30), así que el orden resultante
 * demuestra que la navegación se arma por `order` y no por el orden en que se descubrieron.
 */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'Neighbour',
    type: 'Web',
)]
final class NeighbourPlugin implements PluginInterface, AdminSectionProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * La sección que este vecino aporta.
     *
     * @return list<AdminSection>
     */
    public function adminSections(): array
    {
        return [new AdminSection('architecture', 'Arquitectura', '/vecino/arquitectura', 20)];
    }

    /** Nada que arrancar: sólo aporta una sección. */
    public function boot(): void
    {
    }

    /** @inheritDoc */
    public function install(): void
    {
    }

    /** @inheritDoc */
    public function uninstall(): void
    {
    }

    /** @inheritDoc */
    public function enable(): void
    {
    }

    /** @inheritDoc */
    public function disable(): void
    {
    }
}
