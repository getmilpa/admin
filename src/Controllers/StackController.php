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
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Stack\ComposeProjection;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the compose file of every service the booted plugins declared — readable by a human and by the agent.
 *
 * The same discovery the Stack section reads ({@see StackSource::declarations()}), projected whole by
 * {@see ComposeProjection}. Secrets come out as `${NAME}`; the operator supplies them. Nothing is cached:
 * the file is the declarations as they are on this request. When two plugins declare the same service
 * name there is no file to serve — compose keys services by name and one would silently overwrite the
 * other — so the answer is a 409 that names the collision instead ({@see StackSource::conflicts()}).
 */
final class StackController
{
    public function __construct(
        private readonly StackSource $source,
        private readonly ComposeProjection $projection,
        private readonly Catalog $catalog,
    ) {
    }

    /**
     * `GET {route}/stack/compose.yml` — the whole stack as a compose file, `text/yaml`, served as a
     * download; `409 text/plain` with one line per colliding service name when there is no honest file.
     */
    public function compose(ServerRequestInterface $request): ResponseInterface
    {
        $conflicts = $this->source->conflicts();
        if ($conflicts !== []) {
            $lines = [];
            foreach ($conflicts as $name => $plugins) {
                $lines[] = $this->catalog->tr('stack.compose_conflict', $name, $this->join($plugins));
            }

            return new Response(409, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ], implode("\n", $lines) . "\n");
        }

        $yaml = $this->projection->yaml($this->source->declarations(), $this->source->config());

        return new Response(200, [
            'Content-Type' => 'text/yaml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="compose.yml"',
            'Cache-Control' => 'no-store',
        ], $yaml);
    }

    /**
     * «A, B and C» in the catalog's language.
     *
     * @param list<string> $items
     */
    private function join(array $items): string
    {
        if (\count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' ' . $this->catalog->tr('list.and') . ' ' . $last;
    }
}
