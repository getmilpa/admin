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
use Milpa\Admin\Data\StackSource;
use Milpa\Admin\Stack\ComposeProjection;
use Milpa\Admin\Tests\Fixtures\DeclaringProvider;
use Milpa\Admin\Tests\Fixtures\FakeProbe;
use Milpa\Admin\Tests\Fixtures\HubPlugin;
use Milpa\Admin\Tests\Fixtures\RivalHubPlugin;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\Runtime\Stack\EnvVar;
use Milpa\Runtime\Stack\PortMapping;
use Milpa\Runtime\Stack\ServiceDeclaration;
use PHPUnit\Framework\TestCase;

final class StackSourceTest extends TestCase
{
    public function testTheProbeDecidesUpAndDownAndAServiceWithoutAHostPortIsUnknown(): void
    {
        $provider = new DeclaringProvider([
            new ServiceDeclaration(name: 'zeta', image: 'z', ports: [new PortMapping(container: 80, host: 4000)]),
            new ServiceDeclaration(name: 'alpha', image: 'a', ports: [new PortMapping(container: 80, host: 3000)]),
            new ServiceDeclaration(name: 'inner', image: 'i', ports: [new PortMapping(container: 6379)]),
            new ServiceDeclaration(name: 'health', image: 'h', ports: [new PortMapping(container: 80, host: 8080)], healthPort: 9090),
        ]);
        $probe = new FakeProbe([3000, 9090]);
        $source = new StackSource(new DIContainer(), $probe, new ComposeProjection(), $provider);

        $snapshot = $source->snapshot();

        self::assertFalse($snapshot['kernel']);
        $byName = array_column($snapshot['services'], null, 'name');
        self::assertSame(['alpha', 'health', 'inner', 'zeta'], array_keys($byName), 'sorted by name');
        self::assertSame('up', $byName['alpha']['state']);
        self::assertSame(3000, $byName['alpha']['probePort']);
        self::assertSame(FakeProbe::HOST, $byName['alpha']['probeHost'], 'the host is the probe\'s, not a constant of the source');
        self::assertSame('down', $byName['zeta']['state']);
        self::assertSame('unknown', $byName['inner']['state']);
        self::assertNull($byName['inner']['probePort']);
        self::assertSame(FakeProbe::HOST, $byName['inner']['probeHost']);
        self::assertSame(['6379'], $byName['inner']['ports']);
        self::assertSame('up', $byName['health']['state'], 'the declared health port wins over the first published port');
        self::assertSame(9090, $byName['health']['probePort']);
        self::assertSame([3000, 9090, 4000], $probe->probed, 'unknown services are never probed');
        self::assertSame('DeclaringProvider', $byName['alpha']['plugin']);
        self::assertSame([], $byName['alpha']['conflictsWith']);
        self::assertSame([], $source->conflicts(), 'four distinct names, no collision');
    }

    public function testASecretHasNoDisplayAndTheGlyphIsTheRenderersNotTheSources(): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['hub' => ['key' => 'config-secret', 'public_url' => 'http://localhost:3000']]));

        $snapshot = (new StackSource($container, new FakeProbe(), new ComposeProjection(), new HubPlugin($container)))->snapshot();

        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('config-secret', $encoded, 'the config value a secret points at never leaves the app');
        self::assertStringNotContainsString('●●●', $encoded, 'no glyph in the state: the source says «secret», the renderer paints it');
        $env = array_column($snapshot['services'][0]['env'], null, 'name');
        self::assertSame(['name' => 'HUB_JWT_KEY', 'source' => 'secret', 'display' => null, 'configKey' => 'hub.key'], $env['HUB_JWT_KEY']);
        self::assertSame(['name' => 'SERVER_NAME', 'source' => 'literal', 'display' => ':80', 'configKey' => null], $env['SERVER_NAME']);
        self::assertSame(['name' => 'HUB_PUBLIC_URL', 'source' => 'config', 'display' => 'http://localhost:3000', 'configKey' => 'hub.public_url'], $env['HUB_PUBLIC_URL']);
        self::assertStringContainsString('HUB_JWT_KEY: ${HUB_JWT_KEY}', $snapshot['services'][0]['compose']);
        self::assertStringContainsString("HUB_PUBLIC_URL: 'http://localhost:3000'", $snapshot['services'][0]['compose']);
    }

    public function testAConfigKeyTheAppLacksIsUnsetWithNoDisplayEither(): void
    {
        $container = new DIContainer();

        $source = new StackSource($container, new FakeProbe(), new ComposeProjection(), new HubPlugin($container));
        $snapshot = $source->snapshot();

        self::assertFalse($source->config()?->has('hub.public_url') ?? false, 'no app config holds the key');
        $env = array_column($snapshot['services'][0]['env'], null, 'name');
        self::assertSame('unset', $env['HUB_PUBLIC_URL']['source']);
        self::assertNull($env['HUB_PUBLIC_URL']['display'], 'the source carries the meaning; the renderer owns the «(unset)» glyph');
        self::assertSame('hub.public_url', $env['HUB_PUBLIC_URL']['configKey']);
        self::assertStringNotContainsString('(unset)', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('HUB_PUBLIC_URL: ${HUB_PUBLIC_URL}', $snapshot['services'][0]['compose']);
        self::assertSame(['hub-data:/data'], $snapshot['services'][0]['volumes']);
        self::assertSame([], $snapshot['services'][0]['command']);
        self::assertSame('Pushes shell changes to the browser.', $snapshot['services'][0]['summary']);
        self::assertSame('example/hub:1', $snapshot['services'][0]['image']);
    }

    public function testWithoutAKernelItReadsTheFallbackProviderOnlyAndVerifiesTheForeignPromise(): void
    {
        $container = new DIContainer();
        $probe = new FakeProbe();
        $projection = new ComposeProjection();

        $nothing = new StackSource($container, $probe, $projection);
        self::assertFalse($nothing->snapshot()['kernel']);
        self::assertSame([], $nothing->snapshot()['services']);
        self::assertSame([], $nothing->declarations());
        self::assertSame([], $nothing->conflicts());

        $notAProvider = new StackSource($container, $probe, $projection, new AdminPlugin($container));
        self::assertSame([], $notAProvider->declarations(), 'the panel itself declares no service');

        $liar = new StackSource($container, $probe, $projection, new DeclaringProvider(['garbage', new ServiceDeclaration(name: 'real', image: 'r'), 42]));
        $declarations = $liar->declarations();
        self::assertCount(1, $declarations, 'entries that are not declarations are dropped, not trusted');
        self::assertSame('real', $declarations[0]->name);
    }

    public function testWithAKernelItReadsEveryBootedProvider(): void
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [AdminPlugin::class, HubPlugin::class],
            'config' => ['hub' => ['public_url' => 'http://localhost:3000']],
            'container' => $container,
        ]);
        $container->registerService(Kernel::class, $kernel);

        $source = new StackSource($container, new FakeProbe([HubPlugin::HOST_PORT]), new ComposeProjection());
        $snapshot = $source->snapshot();

        self::assertTrue($snapshot['kernel']);
        self::assertInstanceOf(Config::class, $source->config());
        self::assertCount(1, $snapshot['services']);
        self::assertSame('hub', $snapshot['services'][0]['name']);
        self::assertSame('HubPlugin', $snapshot['services'][0]['plugin']);
        self::assertSame('up', $snapshot['services'][0]['state']);
        self::assertSame(['3000:80'], $snapshot['services'][0]['ports']);
        $env = array_column($snapshot['services'][0]['env'], 'display', 'name');
        self::assertSame('http://localhost:3000', $env['HUB_PUBLIC_URL']);
        self::assertArrayHasKey('HUB_JWT_KEY', array_column($snapshot['services'][0]['env'], null, 'name'));
        self::assertNull($env['HUB_JWT_KEY'] ?? null);
        self::assertSame(['hub'], array_map(static fn (ServiceDeclaration $d): string => $d->name, $source->declarations()));
        self::assertSame(['SERVER_NAME', 'HUB_PUBLIC_URL', 'HUB_JWT_KEY'], array_map(static fn (EnvVar $v): string => $v->name, $source->declarations()[0]->env));
    }

    public function testTwoPluginsDeclaringOneNameAreBothKeptInConflictAndNameEachOther(): void
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [RivalHubPlugin::class, HubPlugin::class],
            'config' => [],
            'container' => $container,
        ]);
        $container->registerService(Kernel::class, $kernel);
        $probe = new FakeProbe([HubPlugin::HOST_PORT, RivalHubPlugin::HOST_PORT]);

        $source = new StackSource($container, $probe, new ComposeProjection());
        $snapshot = $source->snapshot();

        self::assertCount(2, $snapshot['services'], 'a collision drops nothing');
        self::assertCount(2, $source->declarations(), 'the declarations are all still there — refusing is the caller\'s call');
        [$hub, $rival] = $snapshot['services'];
        self::assertSame(['hub', 'HubPlugin', 'conflict', ['RivalHubPlugin']], [$hub['name'], $hub['plugin'], $hub['state'], $hub['conflictsWith']]);
        self::assertSame(['hub', 'RivalHubPlugin', 'conflict', ['HubPlugin']], [$rival['name'], $rival['plugin'], $rival['state'], $rival['conflictsWith']]);
        self::assertSame(3000, $hub['probePort'], 'the declaration is intact; only the state is the conflict');
        self::assertSame([], $probe->probed, 'a colliding service is not probed: whether it answers is not its state');
        self::assertSame(['hub' => ['HubPlugin', 'RivalHubPlugin']], $source->conflicts());
        self::assertStringContainsString("image: 'example/rival-hub:2'", $rival['compose'], 'each row still projects its own fragment');
    }

    public function testAPluginCollidingWithItselfIsAConflictToo(): void
    {
        $provider = new DeclaringProvider([
            new ServiceDeclaration(name: 'dup', image: 'one'),
            new ServiceDeclaration(name: 'solo', image: 'alone', ports: [new PortMapping(container: 80, host: 8080)]),
            new ServiceDeclaration(name: 'dup', image: 'two'),
        ]);
        $probe = new FakeProbe([8080]);

        $source = new StackSource(new DIContainer(), $probe, new ComposeProjection(), $provider);
        $snapshot = $source->snapshot();

        $states = array_map(static fn (array $row): array => [$row['name'], $row['state'], $row['conflictsWith']], $snapshot['services']);
        self::assertSame([
            ['dup', 'conflict', ['DeclaringProvider']],
            ['dup', 'conflict', ['DeclaringProvider']],
            ['solo', 'up', []],
        ], $states);
        self::assertSame(['dup' => ['DeclaringProvider', 'DeclaringProvider']], $source->conflicts());
        self::assertSame([8080], $probe->probed, 'the sound service is still probed');
    }
}
