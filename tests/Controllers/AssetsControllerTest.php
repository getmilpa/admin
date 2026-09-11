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
use Milpa\Live\Support\DesignTokens;
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

        $tokens = $controller->file(DesignTokens::TOKENS);
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
        $request = (new ServerRequest('GET', '/milpa/admin/assets/bundle.css'))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route, ['file' => 'bundle.css']));

        self::assertSame(200, (new AssetsController())->serve($request)->getStatusCode());
        self::assertSame(404, (new AssetsController())->serve(new ServerRequest('GET', '/x'))->getStatusCode(), 'no route result → no file');
    }

    /**
     * The panel serves the house's wordmark as the vector the logo kit mandates.
     *
     * It painted its name as escaped TEXT in a span called `wordmark` — built from type, the grain
     * floats between letters and the `i` keeps its own dot, so the mark reads with two. The vector
     * ships in `milpa/live-web`; serving it is what lets the panel obey the rule instead of
     * approximating it (greenhouse decisions/0249).
     */
    public function testThePanelServesTheWordmarkAsAVector(): void
    {
        $response = (new AssetsController())->file(DesignTokens::WORDMARK);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('<svg', trim((string) $response->getBody()));
    }

    /**
     * 🚨 THE PANEL'S TOKENS ARE THE SYSTEM'S, NOT A COPY — and `tokens.css` is not a back door to one.
     *
     * It shipped its own `assets/milpa/tokens.css` for months: the system's file minus exactly one
     * line, short `--space-32`, 9875 bytes against 9892. `decisions/0243` had already ended the
     * copies — three packages held identical ones, all three missing that same token — and this one
     * survived the fix because it was named differently (greenhouse decisions/0309).
     */
    public function testTheTokensComeFromTheSystemAndTheOldCopyIsGone(): void
    {
        $controller = new AssetsController();

        $served = (string) $controller->file(DesignTokens::TOKENS)->getBody();
        $shipped = (string) file_get_contents((string) DesignTokens::path(DesignTokens::TOKENS));
        self::assertSame($shipped, $served, 'the panel serves the system\'s file, byte for byte');
        self::assertStringContainsString('--space-32', $served, 'the token the copy had lost');

        self::assertSame(404, $controller->file('tokens.css')->getStatusCode(), 'the copy is gone and its name is not an alias');
    }

    /**
     * 🚨 NOTHING THE PANEL SERVES ASKS A THIRD PARTY FOR THE HOUSE'S TYPE.
     *
     * Line 1 of the vendored bundle is `@import url('https://fonts.googleapis.com/…')` — three
     * outbound requests per load, from a surface whose default gate is loopback-only, while this
     * family ships the six woff2 faces itself. It is stripped AT THE DOOR rather than in the file
     * because the line is prepended by the vendoring step: an edit to the file would be gone at the
     * next re-vendor, and the panel would quietly go back to fetching its type from Google.
     *
     * Measured in a browser on a machine without the family installed: with the import removed and
     * nothing replacing it, «Space Grotesk» rendered at exactly the width of a family that does not
     * exist. The failure is silent — the text stays legible in `system-ui` — which is why it lasted.
     */
    public function testNothingServedReachesOutForTheType(): void
    {
        $controller = new AssetsController();

        $bundle = (string) $controller->file('bundle.css')->getBody();
        self::assertStringNotContainsString('fonts.googleapis.com', $bundle);
        self::assertStringNotContainsString('fonts.gstatic.com', $bundle);

        // The positive control: the file on disk DOES carry it, so the strip is doing work and this
        // test is not passing because the import was already absent.
        $onDisk = (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/milpa/bundle.css');
        self::assertStringContainsString('fonts.googleapis.com', $onDisk, 'if this fails the vendoring changed and the strip may be dead code');

        // And the faces it needs instead are served from here.
        $fonts = (string) $controller->file(DesignTokens::FONTS)->getBody();
        self::assertStringContainsString('@font-face', $fonts);
        self::assertStringContainsString("url('fonts/", $fonts, 'relative, which is why there is a second route');
    }

    /**
     * A face is served under `fonts/`, because that is the only place the stylesheet looks.
     *
     * `milpa-fonts.css` names its files relatively, so a host that flattens them serves a stylesheet
     * whose every `src` is a 404 — and a browser reports that as nothing at all.
     */
    public function testAFaceTheStylesheetAsksForIsServed(): void
    {
        $route = new Route(path: '/milpa/admin/assets/fonts/{face}', handler: HandlerReference::method(AssetsController::class, 'face'));
        $request = (new ServerRequest('GET', '/milpa/admin/assets/fonts/space-grotesk-latin.woff2'))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route, ['face' => 'space-grotesk-latin.woff2']));

        $response = (new AssetsController())->face($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('font/woff2', $response->getHeaderLine('Content-Type'));
        self::assertSame(404, (new AssetsController())->face(new ServerRequest('GET', '/x'))->getStatusCode());
    }

    /**
     * 🚨 NO FILE THIS PACKAGE SHIPS IS A COPY OF THE SYSTEM'S TOKENS — BY CONTENT, NOT BY NAME.
     *
     * A name check would have missed the one that was here: it was called `tokens.css`, not
     * `milpa-tokens.css`, and it is exactly as invisible renamed to anything else. What identifies a
     * copy is CONTENT — measured with the instrument below: the system declares 238 custom
     * properties, that file declared 237 of them. The panel's own bundle declares 46, of which 3 are
     * names the system also uses (`--danger`, `--ease-settle`, `--secondary`) — it OVERRIDES those on
     * purpose and loads last. So the threshold sits between 3 and 237, with headroom on both sides.
     *
     * Probed with the deleted file restored under its old name AND under a new one: both red.
     */
    public function testNoShippedFileIsASecondCopyOfTheTokens(): void
    {
        $systemTokens = self::declaredTokens((string) file_get_contents((string) DesignTokens::path(DesignTokens::TOKENS)));
        self::assertGreaterThan(200, \count($systemTokens), 'the instrument found the system\'s tokens');

        $suspects = [];
        foreach (glob(\dirname(__DIR__, 2) . '/assets/milpa/*.css') ?: [] as $file) {
            $shared = \count(array_intersect($systemTokens, self::declaredTokens((string) file_get_contents($file))));
            if ($shared > 20) {
                $suspects[] = basename($file) . " declares {$shared} of the system's tokens";
            }
        }

        self::assertSame([], $suspects, "serve the system's file instead of a copy:\n" . implode("\n", $suspects));
    }

    /**
     * @return list<string>
     */
    private static function declaredTokens(string $css): array
    {
        preg_match_all('/--[a-zA-Z0-9_-]+\s*:/', $css, $found);

        return array_values(array_unique($found[0]));
    }
}
