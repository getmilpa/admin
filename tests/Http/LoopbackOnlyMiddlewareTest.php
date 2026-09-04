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

namespace Milpa\Admin\Tests\Http;

use Milpa\Admin\Http\LoopbackOnlyMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LoopbackOnlyMiddlewareTest extends TestCase
{
    public function testLoopbackGetsThrough(): void
    {
        foreach (['127.0.0.1', '127.9.9.9', '::1', '::ffff:127.0.0.1'] as $address) {
            $response = (new LoopbackOnlyMiddleware())->process(self::request($address), self::handler());
            self::assertSame(200, $response->getStatusCode(), $address);
        }
    }

    public function testEverythingElseIsRefused(): void
    {
        foreach (['203.0.113.9', '10.0.0.1', '', '127.0.0.1.evil', 'localhost'] as $address) {
            $response = (new LoopbackOnlyMiddleware())->process(self::request($address), self::handler());
            self::assertSame(403, $response->getStatusCode(), $address === '' ? '(empty)' : $address);
            self::assertStringContainsString('admin.middleware', (string) $response->getBody());
        }
    }

    private static function request(string $address): ServerRequestInterface
    {
        return new ServerRequest('GET', '/milpa/admin', [], null, '1.1', $address === '' ? [] : ['REMOTE_ADDR' => $address]);
    }

    private static function handler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };
    }
}
