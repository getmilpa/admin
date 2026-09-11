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
use Milpa\Live\Support\DesignTokens;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the panel's static assets from where they already live — no build, no copy into `public/`.
 *
 * Three sources, one door each: the panel's OWN component bundle ships with this package
 * (`assets/milpa/`); the design system — tokens, the fonts stylesheet, its faces, the wordmark and the
 * icon — comes from `milpa/live-web` through {@see DesignTokens::path()}; the client runtime and Alpine
 * come from the same package through {@see ClientRuntime}. Anything else is 404.
 *
 * 🚨 THE PANEL USED TO CARRY ITS OWN COPY OF THE TOKENS, AND IT HAD DRIFTED. `decisions/0243` ended
 * the copies — three packages held identical ones, all three missing `--space-32`, and the file moved
 * into `milpa/live-web`. This copy survived that fix: 9875 bytes against the system's 9892, one line
 * apart, short exactly `--space-32`, which is why `height: var(--space-32)` measured `0px` in a
 * rendered panel. Nothing read it yet, so the cost today was zero and the cost tomorrow was every
 * token the system adds (greenhouse decisions/0309).
 *
 * 🚨 AND THE PANEL'S TYPE CAME FROM GOOGLE. Line 1 of the vendored bundle is an
 * `@import url('https://fonts.googleapis.com/…')` the vendoring step prepends — three outbound
 * requests per load, from a surface whose default gate is loopback-only, while this family ships the
 * six woff2 faces itself. Measured with the import removed, on a machine where the family is not
 * installed: «Space Grotesk» rendered at exactly the width of a family that does not exist. The
 * failure is silent — the text stays legible in `system-ui` — which is why it lasted.
 */
final class AssetsController
{
    /**
     * The panel's own stylesheet — its component layers, vendored from `@milpa/design`.
     *
     * `tokens.css` is NOT here any more: it was a copy of the system's, and the system serves its own.
     *
     * @var array<string, string>
     */
    private const PACKAGED = [
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

    /**
     * `GET {route}/assets/fonts/{face}` — one font face.
     *
     * A second route because `milpa-fonts.css` names its files RELATIVELY (`url('fonts/x.woff2')`), so
     * a browser that loaded the stylesheet at `{route}/assets/milpa-fonts.css` asks for
     * `{route}/assets/fonts/x.woff2` and nothing else. A host that flattens that serves a stylesheet
     * whose every `src` is a 404 — and that failure shows up as MISSING TYPE, never as an error.
     */
    public function face(ServerRequestInterface $request): ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::ATTRIBUTE);

        return $this->file($result instanceof RouteResult ? (string) ($result->parameters['face'] ?? '') : '');
    }

    /** The response for one asset name — 404 for anything the panel does not ship. */
    public function file(string $name): ResponseInterface
    {
        if (isset(self::PACKAGED[$name])) {
            return $this->read(\dirname(__DIR__, 2) . '/assets/milpa/' . self::PACKAGED[$name], 'text/css; charset=utf-8', true);
        }
        // THE DESIGN SYSTEM COMES FROM THE DESIGN SYSTEM. The wordmark arrived first and as a vector:
        // the panel used to paint its name as escaped TEXT in a span called `wordmark`, which is the
        // one thing the logo kit forbids — built from type, the grain floats between letters and the
        // `i` keeps its own dot, so the mark reads with two (greenhouse decisions/0249). The tokens,
        // the fonts stylesheet and its faces now come the same way (decisions/0309).
        //
        // `path()` IS the whitelist and answers `null` for anything this family does not own, so
        // asking it is both the resolution and the guard — a second list here could only disagree with
        // it, and the name that fell out of the second list would 404 while the file sat right there.
        $shipped = DesignTokens::path($name);
        if ($shipped !== null) {
            return $this->read($shipped, DesignTokens::contentType($name));
        }
        if (\in_array($name, self::RUNTIME, true)) {
            $path = ClientRuntime::path($name);

            return $path === null ? $this->missing() : $this->read($path, ClientRuntime::contentType());
        }

        return $this->missing();
    }

    /**
     * One asset's response, or a 404 when there is nothing to send.
     *
     * 🚨 `$vendored` STRIPS THE OUTBOUND FONT IMPORT, AND IT IS STRIPPED HERE ON PURPOSE. The line is
     * prepended by the vendoring step, not written in this repo, so an edit to the file would be gone
     * at the next re-vendor and nobody would notice — the panel would just quietly go back to fetching
     * its type from a third party. Stripping it at the door survives the next bundle
     * (greenhouse decisions/0309).
     */
    private function read(string $path, string $contentType, bool $vendored = false): ResponseInterface
    {
        $body = is_file($path) ? (string) file_get_contents($path) : '';
        if ($body === '') {
            return $this->missing();
        }
        if ($vendored) {
            $body = (string) preg_replace('#^@import\s+url\([^)]*fonts\.googleapis\.com[^)]*\);?\s*#i', '', $body);
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
