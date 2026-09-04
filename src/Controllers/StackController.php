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

use Milpa\Admin\Data\StackSource;
use Milpa\Admin\Stack\ComposeProjection;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the compose file of every service the booted plugins declared — readable by a human and by the agent.
 *
 * The same discovery the Stack section reads ({@see StackSource::declarations()}), projected whole by
 * {@see ComposeProjection}. Secrets come out as `${NAME}`; the operator supplies them. Nothing is cached:
 * the file is the declarations as they are on this request.
 */
final class StackController
{
    public function __construct(
        private readonly StackSource $source,
        private readonly ComposeProjection $projection,
    ) {
    }

    /** `GET {route}/stack/compose.yml` — the whole stack as a compose file, `text/yaml`. */
    public function compose(ServerRequestInterface $request): ResponseInterface
    {
        $yaml = $this->projection->yaml($this->source->declarations(), $this->source->config());

        return new Response(200, [
            'Content-Type' => 'text/yaml; charset=utf-8',
            'Content-Disposition' => 'inline; filename="compose.yml"',
            'Cache-Control' => 'no-store',
        ], $yaml);
    }
}
