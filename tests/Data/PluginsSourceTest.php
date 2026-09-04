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

use Milpa\Admin\Data\PluginsSource;
use Milpa\Admin\Tests\Fixtures\ArrayPluginRegistry;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
use Milpa\Container\DIContainer;
use Milpa\Plugin\Activation\DeclaredPlugins;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use PHPUnit\Framework\TestCase;

final class PluginsSourceTest extends TestCase
{
    public function testDegradesToNothingWithoutRegistryOrDeclaredList(): void
    {
        $snapshot = (new PluginsSource(new DIContainer()))->snapshot();

        self::assertFalse($snapshot['registry']);
        self::assertSame([], $snapshot['plugins']);
        self::assertNull($snapshot['capabilities'], 'app-runtime is not installed in this suite');
    }

    public function testMergesDeclaredClassesWithRegistryRecords(): void
    {
        $container = new DIContainer();
        $container->registerService(DeclaredPlugins::class, new DeclaredPlugins([HolaPlugin::class, 'App\\Plugins\\Ghost\\Ghost']));
        $container->registerService(PluginRegistryInterface::class, new ArrayPluginRegistry([
            new PluginRecord('Hola', '0.0.1', 'x', 'x', 'Web', installed: true, enabled: false, source: 'declared'),
            new PluginRecord('Remote', '2.0.0', 'x', 'x', 'Service', installed: true, enabled: true, source: 'packagist'),
        ]));

        $snapshot = (new PluginsSource($container))->snapshot();

        self::assertTrue($snapshot['registry']);
        $byName = array_column($snapshot['plugins'], null, 'name');
        self::assertSame(['Ghost', 'Hola', 'Remote'], array_keys($byName));
        self::assertSame('1.2.3', $byName['Hola']['version'], 'the attribute wins over the record');
        self::assertFalse($byName['Hola']['enabled'], 'the record says it is off');
        self::assertSame(HolaPlugin::class, $byName['Hola']['class']);
        self::assertSame('', $byName['Ghost']['version'], 'a declared class that does not exist has no metadata');
        self::assertTrue($byName['Ghost']['enabled']);
        self::assertNull($byName['Remote']['class'], 'installed but not declared');
        self::assertSame('packagist', $byName['Remote']['source']);
    }
}
