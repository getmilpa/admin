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

namespace Milpa\Admin\Tests\Controllers;

use Milpa\Admin\Controllers\StackController;
use Milpa\Admin\Data\StackSource;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Stack\ComposeProjection;
use Milpa\Admin\Tests\Fixtures\DeclaringProvider;
use Milpa\Admin\Tests\Fixtures\FakeProbe;
use Milpa\Admin\Tests\Fixtures\HubPlugin;
use Milpa\Admin\Tests\Fixtures\RivalHubPlugin;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\Runtime\Stack\ServiceDeclaration;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class StackControllerTest extends TestCase
{
    public function testServesTheWholeStackAsAYamlDownload(): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['hub' => ['public_url' => 'http://localhost:3000', 'key' => 'config-secret']]));
        $controller = self::controller(new StackSource($container, new FakeProbe(), new ComposeProjection(), new HubPlugin($container)));

        $response = $controller->compose(new ServerRequest('GET', '/milpa/admin/stack/compose.yml'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/yaml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="compose.yml"', $response->getHeaderLine('Content-Disposition'), 'the «Download compose.yml» label is true');
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $yaml = (string) $response->getBody();
        self::assertStringStartsWith("services:\n  hub:\n", $yaml);
        self::assertStringContainsString("    image: 'example/hub:1'\n", $yaml);
        self::assertStringContainsString("      HUB_PUBLIC_URL: 'http://localhost:3000'\n", $yaml);
        self::assertStringContainsString("      HUB_JWT_KEY: \${HUB_JWT_KEY}\n", $yaml);
        self::assertStringNotContainsString('config-secret', $yaml);
    }

    public function testAnEmptyStackIsStillAComposeFile(): void
    {
        $controller = self::controller(new StackSource(new DIContainer(), new FakeProbe(), new ComposeProjection()));

        $response = $controller->compose(new ServerRequest('GET', '/milpa/admin/stack/compose.yml'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("services: {}\n", (string) $response->getBody());
    }

    public function testACollidingServiceNameIsA409ThatNamesThePluginsAndServesNoYaml(): void
    {
        $container = new DIContainer();
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [HubPlugin::class, RivalHubPlugin::class], 'config' => [], 'container' => $container]);
        $container->registerService(Kernel::class, $kernel);
        $source = new StackSource($container, new FakeProbe(), new ComposeProjection());

        $response = self::controller($source)->compose(new ServerRequest('GET', '/milpa/admin/stack/compose.yml'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Content-Disposition'), 'nothing to download');
        $body = (string) $response->getBody();
        self::assertSame(
            "Service «hub» is declared by HubPlugin and RivalHubPlugin — rename one or disable a plugin; no compose.yml is served while ids collide.\n",
            $body,
        );
        self::assertStringNotContainsString('services:', $body);
        self::assertStringNotContainsString('image:', $body);

        $spanish = self::controller($source, 'es')->compose(new ServerRequest('GET', '/milpa/admin/stack/compose.yml'));
        self::assertSame(409, $spanish->getStatusCode());
        self::assertStringContainsString('El servicio «hub» lo declaran HubPlugin y RivalHubPlugin', (string) $spanish->getBody());
    }

    public function testOneLinePerCollisionAndThreeDeclarersReadAsAList(): void
    {
        $provider = new DeclaringProvider([
            new ServiceDeclaration(name: 'cache', image: 'redis'),
            new ServiceDeclaration(name: 'cache', image: 'valkey'),
            new ServiceDeclaration(name: 'cache', image: 'keydb'),
            new ServiceDeclaration(name: 'db', image: 'postgres'),
            new ServiceDeclaration(name: 'db', image: 'mysql'),
            new ServiceDeclaration(name: 'fine', image: 'alone'),
        ]);
        $source = new StackSource(new DIContainer(), new FakeProbe(), new ComposeProjection(), $provider);

        $response = self::controller($source)->compose(new ServerRequest('GET', '/milpa/admin/stack/compose.yml'));

        self::assertSame(409, $response->getStatusCode());
        $lines = explode("\n", rtrim((string) $response->getBody(), "\n"));
        self::assertCount(2, $lines, 'one line per colliding name; the sound one is not mentioned');
        self::assertStringStartsWith('Service «cache» is declared by DeclaringProvider, DeclaringProvider and DeclaringProvider — ', $lines[0]);
        self::assertStringStartsWith('Service «db» is declared by DeclaringProvider and DeclaringProvider — ', $lines[1]);
        self::assertStringNotContainsString('fine', (string) $response->getBody());
    }

    private static function controller(StackSource $source, string $locale = 'en'): StackController
    {
        return new StackController($source, new ComposeProjection(), new Catalog($locale));
    }
}
