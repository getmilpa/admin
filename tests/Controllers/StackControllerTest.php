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
use Milpa\Admin\Stack\ComposeProjection;
use Milpa\Admin\Tests\Fixtures\FakeProbe;
use Milpa\Admin\Tests\Fixtures\HubPlugin;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class StackControllerTest extends TestCase
{
    public function testServesTheWholeStackAsYaml(): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['hub' => ['public_url' => 'http://localhost:3000', 'key' => 'config-secret']]));
        $controller = new StackController(new StackSource($container, new FakeProbe(), new HubPlugin($container)), new ComposeProjection());

        $response = $controller->compose(new ServerRequest('GET', '/milpa/admin/stack/compose.yml'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/yaml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('compose.yml', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $yaml = (string) $response->getBody();
        self::assertStringStartsWith("services:\n  hub:\n", $yaml);
        self::assertStringContainsString("    image: 'example/hub:1'\n", $yaml);
        self::assertStringContainsString("      HUB_PUBLIC_URL: 'http://localhost:3000'\n", $yaml);
        self::assertStringContainsString("      HUB_JWT_KEY: \${HUB_JWT_KEY}\n", $yaml);
        self::assertStringNotContainsString(HubPlugin::SECRET, $yaml);
        self::assertStringNotContainsString('config-secret', $yaml);
    }

    public function testAnEmptyStackIsStillAComposeFile(): void
    {
        $controller = new StackController(new StackSource(new DIContainer(), new FakeProbe()), new ComposeProjection());

        $response = $controller->compose(new ServerRequest('GET', '/milpa/admin/stack/compose.yml'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("services: {}\n", (string) $response->getBody());
    }
}
