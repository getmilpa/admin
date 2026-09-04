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
 * Every section the booted plugins declared, validated and ordered.
 *
 * Built per request from the plugin instances the kernel holds, so a plugin that boots after the
 * panel still shows up. Duplicate ids fail loudly ({@see SectionConflictException}); the panel never
 * picks one silently.
 */
final class SectionCatalogue
{
    /** @var array<string, AdminSection> */
    private array $sections = [];

    /** @var array<string, class-string> */
    private array $declaredBy = [];

    private function __construct()
    {
    }

    /**
     * Collects the sections of every plugin that implements {@see AdminSectionProvider}.
     *
     * @param iterable<object> $plugins the booted plugin instances, in any order
     */
    public static function discover(iterable $plugins): self
    {
        $catalogue = new self();

        foreach ($plugins as $plugin) {
            if (!$plugin instanceof AdminSectionProvider) {
                continue;
            }

            foreach ($plugin->adminSections() as $section) {
                if (!$section instanceof AdminSection) {
                    throw new SectionConflictException(\sprintf(
                        '%s::adminSections() must return %s instances, got %s.',
                        $plugin::class,
                        AdminSection::class,
                        get_debug_type($section),
                    ));
                }
                if (isset($catalogue->sections[$section->id])) {
                    throw new SectionConflictException(\sprintf(
                        'Admin section «%s» is declared twice: by %s and by %s.',
                        $section->id,
                        $catalogue->declaredBy[$section->id],
                        $plugin::class,
                    ));
                }
                $catalogue->sections[$section->id] = $section;
                $catalogue->declaredBy[$section->id] = $plugin::class;
            }
        }

        uasort(
            $catalogue->sections,
            static fn (AdminSection $a, AdminSection $b): int => [$a->order, $a->id] <=> [$b->order, $b->id],
        );

        return $catalogue;
    }

    /**
     * The sections in sidebar order.
     *
     * @return list<AdminSection>
     */
    public function sections(): array
    {
        return array_values($this->sections);
    }

    /** The section with that id, or null when no plugin declared it. */
    public function find(string $id): ?AdminSection
    {
        return $this->sections[$id] ?? null;
    }

    /** The section the panel opens on — the first in sidebar order — or null when there is none. */
    public function first(): ?AdminSection
    {
        foreach ($this->sections as $section) {
            return $section;
        }

        return null;
    }

    /** The plugin class that declared a section, or null when no plugin declared it. */
    public function declaredBy(string $id): ?string
    {
        return $this->declaredBy[$id] ?? null;
    }

    /** True when no plugin declared any section. */
    public function isEmpty(): bool
    {
        return $this->sections === [];
    }
}
