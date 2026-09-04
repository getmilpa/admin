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

namespace Milpa\Admin\Controllers;

use Milpa\Http\Routing\RouteResult;
use Milpa\Live\Support\ClientRuntime;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the panel's static assets from where they already live — no build, no copy into `public/`.
 *
 * The design tokens and bundle ship with this package (`assets/milpa/`); the client runtime and Alpine
 * ship with `milpa/live-web` and are resolved through its own {@see ClientRuntime}. Anything else is 404.
 */
final class AssetsController
{
    /** @var array<string, string> */
    private const PACKAGED = [
        'tokens.css' => 'tokens.css',
        'bundle.css' => 'bundle.css',
    ];

    /** @var list<string> */
    private const RUNTIME = [ClientRuntime::LOCAL, ClientRuntime::REMOTE, ClientRuntime::ALPINE];

    /** `GET {route}/assets/{file}` — one asset by name. */
    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::ATTRIBUTE);
        $file = $result instanceof RouteResult ? (string) ($result->parameters['file'] ?? '') : '';

        return $this->file($file);
    }

    /** The response for one asset name — 404 for anything the panel does not ship. */
    public function file(string $name): ResponseInterface
    {
        if (isset(self::PACKAGED[$name])) {
            return $this->read(\dirname(__DIR__, 2) . '/assets/milpa/' . self::PACKAGED[$name], 'text/css; charset=utf-8');
        }
        if (\in_array($name, self::RUNTIME, true)) {
            $path = ClientRuntime::path($name);

            return $path === null ? $this->missing() : $this->read($path, ClientRuntime::contentType());
        }

        return $this->missing();
    }

    private function read(string $path, string $contentType): ResponseInterface
    {
        $body = is_file($path) ? (string) file_get_contents($path) : '';
        if ($body === '') {
            return $this->missing();
        }

        return new Response(200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ], $body);
    }

    private function missing(): ResponseInterface
    {
        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], "Not an asset of Milpa Admin.\n");
    }
}
