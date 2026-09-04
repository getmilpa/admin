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
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionConflictException;
use PHPUnit\Framework\TestCase;

final class SectionCatalogueTest extends TestCase
{
    public function testDiscoversOnlyProvidersAndOrdersByOrderThenId(): void
    {
        $catalogue = SectionCatalogue::discover([
            new \stdClass(),
            self::provider([new AdminSection('zeta', 'Z', 'metric-card', order: 10), new AdminSection('alpha', 'A', 'metric-card', order: 10)]),
            self::provider([new AdminSection('first', 'F', 'metric-card', order: 1)]),
        ]);

        self::assertSame(['first', 'alpha', 'zeta'], array_map(static fn (AdminSection $s): string => $s->id, $catalogue->sections()));
        self::assertSame('first', $catalogue->first()?->id);
        self::assertSame('zeta', $catalogue->find('zeta')?->id);
        self::assertNull($catalogue->find('nope'));
        self::assertFalse($catalogue->isEmpty());
        self::assertNotNull($catalogue->declaredBy('alpha'));
        self::assertNull($catalogue->declaredBy('nope'));
    }

    public function testAnEmptyCatalogue(): void
    {
        $catalogue = SectionCatalogue::discover([new \stdClass()]);

        self::assertTrue($catalogue->isEmpty());
        self::assertNull($catalogue->first());
        self::assertSame([], $catalogue->sections());
    }

    public function testADuplicateIdIsLoud(): void
    {
        $this->expectException(SectionConflictException::class);
        $this->expectExceptionMessage('declared twice');

        SectionCatalogue::discover([
            self::provider([new AdminSection('same', 'One', 'metric-card')]),
            self::provider([new AdminSection('same', 'Two', 'metric-card')]),
        ]);
    }

    public function testAProviderReturningGarbageIsLoud(): void
    {
        $this->expectException(SectionConflictException::class);
        $this->expectExceptionMessage('must return');

        SectionCatalogue::discover([self::provider(['not a section'])]);
    }

    /** @param list<mixed> $sections */
    private static function provider(array $sections): AdminSectionProvider
    {
        return new class ($sections) implements AdminSectionProvider {
            /** @param list<mixed> $sections */
            public function __construct(private readonly array $sections)
            {
            }

            public function adminSections(): array
            {
                /** @var list<AdminSection> */
                return $this->sections;
            }
        };
    }
}
