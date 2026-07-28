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

namespace Milpa\Admin\Section;

/**
 * El discovery del Admin Hub (ADR#12): recolecta UNA vez las secciones de todos los plugins
 * booteados que implementen {@see AdminSectionProvider}, valida fail-fast, ordena determinista
 * (order y luego id — jamás el orden de boot) y congela el snapshot. `defaultSection()` es LA
 * política de redirect del Hub: hoy la primera por orden; mañana última-usada/favorita/config —
 * sin tocar el controller del Hub.
 */
final class AdminSectionDiscovery
{
    /** @var list<AdminSection>|null */
    private ?array $snapshot = null;

    /** @param iterable<object> $plugins los plugins booteados (PluginsManagerInterface::getPlugins()) */
    public function __construct(private readonly iterable $plugins)
    {
    }

    /**
     * Las secciones de todos los plugins booteados, ordenadas por su `order`.
     *
     * @return list<AdminSection>
     */
    public function sections(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $byId = [];
        foreach ($this->plugins as $plugin) {
            if (!$plugin instanceof AdminSectionProvider) {
                continue;
            }
            foreach ($plugin->adminSections() as $section) {
                $this->assertValid($section, $plugin::class);
                if (isset($byId[$section->id])) {
                    throw AdminSectionDiscoveryException::duplicateId($section->id, $plugin::class);
                }
                $byId[$section->id] = $section;
            }
        }

        if ($byId === []) {
            throw AdminSectionDiscoveryException::noSections();
        }

        $sections = array_values($byId);
        usort($sections, static fn (AdminSection $a, AdminSection $b): int => $a->order <=> $b->order ?: $a->id <=> $b->id);

        return $this->snapshot = $sections;
    }

    /** A dónde va el Hub: hoy la primera por orden. Cambiarlo no toca ningún controller. */
    public function defaultSection(): AdminSection
    {
        return $this->sections()[0];
    }

    private function assertValid(AdminSection $section, string $providerClass): void
    {
        if (preg_match('/^[a-z][a-z0-9.-]*$/', $section->id) !== 1) {
            throw AdminSectionDiscoveryException::invalidSection($providerClass, "id '{$section->id}' fuera de gramática");
        }
        if ($section->title === '') {
            throw AdminSectionDiscoveryException::invalidSection($providerClass, "la sección '{$section->id}' no tiene title");
        }
        $href = $section->href;
        if ($href === '' || $href[0] !== '/' || str_starts_with($href, '//')
            || str_contains($href, '://') || preg_match('/[\x00-\x1f\\\\]/', $href) === 1) {
            throw AdminSectionDiscoveryException::invalidSection($providerClass, "href '{$href}' no es un path local absoluto");
        }
    }
}
