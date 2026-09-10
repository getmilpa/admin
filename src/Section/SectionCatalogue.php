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

        // ONE LEVEL, AND THE REFUSAL IS HERE BECAUSE ONLY HERE IS THE WHOLE SET KNOWN. A section cannot
        // see, at construction, whether the parent it names is itself somebody's child — that fact
        // belongs to the catalogue. A gear that opens a gear is a menu nobody asked for, and the depth
        // is refused rather than flattened so the author learns it instead of discovering that their
        // third level silently became a second (greenhouse decisions/0268).
        //
        // A parent nobody declared is NOT an error: that section is a root (see roots()). Only a parent
        // that exists AND is itself a child is refused.
        foreach ($catalogue->sections as $section) {
            if ($section->parent === '') {
                continue;
            }
            $parent = $catalogue->sections[$section->parent] ?? null;
            if ($parent !== null && $parent->parent !== '' && isset($catalogue->sections[$parent->parent])) {
                throw new SectionConflictException(\sprintf(
                    'Admin section «%s» is declared under «%s», which is itself under «%s»: sections nest one level, and a gear that opens a gear is a menu nobody asked for.',
                    $section->id,
                    $parent->id,
                    $parent->parent,
                ));
            }
        }

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

    /**
     * The sections the MAIN NAVIGATION shows: the ones that belong under nobody.
     *
     * 🚨 THIS IS A SECOND READER AND NOT A NARROWING OF {@see sections()}, deliberately. Three of that
     * method's five callers want every section: the panel's 404 lists the ids present, and a child
     * missing from the error whose whole job is naming what exists would be invisible exactly when
     * somebody is looking for it; the component book must cover a child's component like any other;
     * and the TUI needs state for every section it can open. Narrowing the old method would have
     * broken all three in silence (greenhouse decisions/0268).
     *
     * A section whose parent nobody declared comes back HERE, as a root. The alternative hides a
     * working section because a different plugin is absent, which turns uninstalling one thing into
     * losing another.
     *
     * @return list<AdminSection>
     */
    public function roots(): array
    {
        $roots = [];
        foreach ($this->sections as $section) {
            if ($section->parent === '' || !isset($this->sections[$section->parent])) {
                $roots[] = $section;
            }
        }

        return $roots;
    }

    /**
     * The sections declared under that one, in the same order the sidebar uses.
     *
     * Empty for a section nobody named as a parent — which is what the shell reads to decide whether
     * to paint a gear at all.
     *
     * @return list<AdminSection>
     */
    public function children(string $id): array
    {
        // 🚨 AN UNDECLARED PARENT HAS NO CHILDREN, and this line is the invariant.
        //
        // Without it a section whose parent nobody declared came back BOTH from roots() — where it
        // belongs, so it stays findable — and from here, under an id that does not exist. Every
        // section appears exactly once between the roots and somebody's children; two answers about
        // one section is how a navigation ends up listing it twice or not at all.
        if (!isset($this->sections[$id])) {
            return [];
        }

        $children = [];
        foreach ($this->sections as $section) {
            if ($section->parent === $id && $section->id !== $id) {
                $children[] = $section;
            }
        }

        return $children;
    }

    /** The section with that id, or null when no plugin declared it. */
    public function find(string $id): ?AdminSection
    {
        return $this->sections[$id] ?? null;
    }

    /**
     * The section the panel opens on — the first ROOT in sidebar order — or null when there is none.
     *
     * A child can never be the front page: it is reached through its parent's gear, and a panel that
     * opened on somebody's settings screen would answer «what is this house» with «here are its
     * knobs» (greenhouse decisions/0264, decisions/0268).
     */
    public function first(): ?AdminSection
    {
        foreach ($this->roots() as $section) {
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
