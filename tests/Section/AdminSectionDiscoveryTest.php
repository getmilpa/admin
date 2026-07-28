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

namespace Milpa\Admin\Tests\Section;

use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionDiscovery;
use Milpa\Admin\Section\AdminSectionDiscoveryException;
use Milpa\Admin\Section\AdminSectionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Provider sintético — cualquier objeto sirve; el discovery filtra por instanceof. */
final class FakeSectionProvider implements AdminSectionProvider
{
    /** @param list<AdminSection> $sections */
    public function __construct(private readonly array $sections)
    {
    }

    public function adminSections(): array
    {
        return $this->sections;
    }
}

final class NotAProvider
{
}

final class AdminSectionDiscoveryTest extends TestCase
{
    public function test_merges_sections_from_multiple_providers_and_ignores_non_providers(): void
    {
        $discovery = new AdminSectionDiscovery([
            new FakeSectionProvider([new AdminSection('settings', 'Settings', '/milpa/admin/settings', 10)]),
            new NotAProvider(),
            new FakeSectionProvider([new AdminSection('architecture', 'Arquitectura', '/agency/architecture', 20)]),
        ]);

        $ids = array_map(static fn (AdminSection $s): string => $s->id, $discovery->sections());
        self::assertSame(['settings', 'architecture'], $ids);
    }

    public function test_order_is_deterministic_by_order_then_id_never_boot_order(): void
    {
        // mismo order (5) → desempata por id alfabético, NO por orden de registro
        $discovery = new AdminSectionDiscovery([
            new FakeSectionProvider([new AdminSection('zeta', 'Zeta', '/z', 5)]),
            new FakeSectionProvider([new AdminSection('alfa', 'Alfa', '/a', 5)]),
            new FakeSectionProvider([new AdminSection('primero', 'Primero', '/p', 1)]),
        ]);

        $ids = array_map(static fn (AdminSection $s): string => $s->id, $discovery->sections());
        self::assertSame(['primero', 'alfa', 'zeta'], $ids);
    }

    public function test_default_section_is_the_first_by_order(): void
    {
        $discovery = new AdminSectionDiscovery([
            new FakeSectionProvider([
                new AdminSection('b', 'B', '/b', 20),
                new AdminSection('a', 'A', '/a', 10),
            ]),
        ]);

        self::assertSame('a', $discovery->defaultSection()->id);
    }

    public function test_duplicate_ids_block_with_the_duplicate_code(): void
    {
        $discovery = new AdminSectionDiscovery([
            new FakeSectionProvider([new AdminSection('settings', 'Settings', '/x', 1)]),
            new FakeSectionProvider([new AdminSection('settings', 'Otra', '/y', 2)]),
        ]);

        try {
            $discovery->sections();
            self::fail('debió lanzar por id duplicado');
        } catch (AdminSectionDiscoveryException $e) {
            self::assertStringContainsString('MILPA_ADMIN_SECTION_DUPLICATE', $e->getMessage());
            self::assertStringContainsString('settings', $e->getMessage());
        }
    }

    #[DataProvider('invalidSections')]
    public function test_invalid_sections_block_with_the_invalid_code(AdminSection $bad): void
    {
        $discovery = new AdminSectionDiscovery([new FakeSectionProvider([$bad])]);

        try {
            $discovery->sections();
            self::fail('debió lanzar por sección inválida');
        } catch (AdminSectionDiscoveryException $e) {
            self::assertStringContainsString('MILPA_ADMIN_SECTION_INVALID', $e->getMessage());
        }
    }

    /** @return iterable<string, array{AdminSection}> */
    public static function invalidSections(): iterable
    {
        yield 'id vacío' => [new AdminSection('', 'T', '/x')];
        yield 'id con mayúsculas' => [new AdminSection('Settings', 'T', '/x')];
        yield 'id que arranca con dígito' => [new AdminSection('1abc', 'T', '/x')];
        yield 'title vacío' => [new AdminSection('ok', '', '/x')];
        yield 'href vacío' => [new AdminSection('ok', 'T', '')];
        yield 'href relativo' => [new AdminSection('ok', 'T', 'agency/architecture')];
        yield 'href protocol-relative' => [new AdminSection('ok', 'T', '//evil.example')];
        yield 'href con esquema' => [new AdminSection('ok', 'T', 'https://evil.example')];
        yield 'href con control char' => [new AdminSection('ok', 'T', "/x\t/y")];
    }

    public function test_zero_sections_block_with_the_empty_code(): void
    {
        $discovery = new AdminSectionDiscovery([new NotAProvider()]);

        try {
            $discovery->sections();
            self::fail('debió lanzar por cero secciones');
        } catch (AdminSectionDiscoveryException $e) {
            self::assertStringContainsString('MILPA_ADMIN_NO_SECTIONS', $e->getMessage());
        }
    }
}
