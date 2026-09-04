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

namespace Milpa\Admin\Tests\Data;

use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

final class RoutesSourceTest extends TestCase
{
    public function testWithoutAKernelItReadsTheFallbackProviderOnly(): void
    {
        $container = new DIContainer();
        $snapshot = (new RoutesSource($container, new HolaPlugin($container)))->snapshot();

        self::assertFalse($snapshot['kernel']);
        self::assertCount(1, $snapshot['routes']);
        self::assertSame('/hola', $snapshot['routes'][0]['path']);
        self::assertSame('GET', $snapshot['routes'][0]['method']);
        self::assertSame('HolaPlugin::boot', $snapshot['routes'][0]['handler']);
        self::assertSame(['LoopbackOnlyMiddleware'], $snapshot['routes'][0]['middleware']);
        self::assertSame('HolaPlugin', $snapshot['routes'][0]['plugin']);
    }

    public function testWithoutAnythingItIsEmpty(): void
    {
        $snapshot = (new RoutesSource(new DIContainer()))->snapshot();

        self::assertFalse($snapshot['kernel']);
        self::assertSame([], $snapshot['routes']);
    }

    public function testWithAKernelItReadsEveryBootedProvider(): void
    {
        $container = new DIContainer();
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [AdminPlugin::class, HolaPlugin::class], 'container' => $container]);
        $container->registerService(Kernel::class, $kernel);

        $snapshot = (new RoutesSource($container))->snapshot();

        self::assertTrue($snapshot['kernel']);
        $paths = array_column($snapshot['routes'], 'path');
        self::assertContains('/hola', $paths);
        self::assertContains('/milpa/admin', $paths);
        self::assertContains('/milpa/admin/s/{id}', $paths);
        self::assertSame($paths, self::sorted($paths), 'sorted by path');
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function sorted(array $paths): array
    {
        sort($paths);

        return $paths;
    }
}
