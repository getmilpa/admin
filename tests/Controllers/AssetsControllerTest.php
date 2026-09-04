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

use Milpa\Admin\Controllers\AssetsController;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class AssetsControllerTest extends TestCase
{
    public function testServesPackagedCssAndTheLiveRuntime(): void
    {
        $controller = new AssetsController();

        $tokens = $controller->file('tokens.css');
        self::assertSame(200, $tokens->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $tokens->getHeaderLine('Content-Type'));
        self::assertStringContainsString('immutable', $tokens->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('--', (string) $tokens->getBody());

        self::assertSame(200, $controller->file('bundle.css')->getStatusCode());
        self::assertSame(200, $controller->file('milpa-live.js')->getStatusCode());
        self::assertSame(200, $controller->file('alpine.min.js')->getStatusCode());
        self::assertStringContainsString('javascript', $controller->file('milpa-live.js')->getHeaderLine('Content-Type'));
    }

    public function testAnythingElseIs404(): void
    {
        $controller = new AssetsController();

        self::assertSame(404, $controller->file('../composer.json')->getStatusCode());
        self::assertSame(404, $controller->file('')->getStatusCode());
        self::assertSame(404, $controller->file('evil.js')->getStatusCode());
    }

    public function testReadsTheFileNameFromTheRouteResult(): void
    {
        $route = new Route(path: '/milpa/admin/assets/{file}', handler: HandlerReference::method(AssetsController::class, 'serve'));
        $request = (new ServerRequest('GET', '/milpa/admin/assets/tokens.css'))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route, ['file' => 'tokens.css']));

        self::assertSame(200, (new AssetsController())->serve($request)->getStatusCode());
        self::assertSame(404, (new AssetsController())->serve(new ServerRequest('GET', '/x'))->getStatusCode(), 'no route result → no file');
    }
}
