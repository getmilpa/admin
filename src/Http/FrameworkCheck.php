<?php

/**
 * This file is part of milpa/admin.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin\Http;

use Milpa\Admin\Data\FrameworkRelease;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * THE VERB: asks the registry what the newest `milpa/framework` is, and fetches it to be hashed.
 *
 * This is the only thing in the panel that reaches the network on purpose, and it exists as a route
 * precisely so that no RENDER ever does. Measured: 567 ms to ask which version is newest, 1.0 s to
 * fetch and hash a release, 0 ms once cached — 1.6 seconds that would otherwise sit inside a page
 * (greenhouse decisions/0281 measured what probing on paint costs; 0294 built this).
 *
 * ── WHAT IT IS AND IS NOT ───────────────────────────────────────────────────────────────────────────
 *
 * It READS. It writes two caches under `storage/` and nothing else: which version is newest, and that
 * release's file hashes. It applies nothing, touches no file of the house, and holds no bytes of the
 * new skeleton where anything could copy them. Applying is a governed act and its own slice — a route
 * that could both look and write would put «show me» and «do it» one request apart.
 *
 * Because it changes nothing about the house, it needs no operation ceiling and no consent: the panel's
 * own door is the gate, the same one `/workspace/settings` and the section pages carry. It is egress
 * with a cost, not authority.
 */
final class FrameworkCheck
{
    /** The path this route is mounted at, under the panel's own route. */
    public const string PATH = '/framework/check';

    public const string ROUTE_NAME = 'milpa_admin_framework_check';

    /**
     * 🚨 THE ROOT IS RESOLVED PER REQUEST, NOT AT CONSTRUCTION, and the first build got it wrong.
     *
     * The skeleton's `public/index.php` registers the Kernel in the container AFTER `Kernel::boot()`
     * returns — so while plugins are booting there is no Kernel to ask, and a root captured then is the
     * empty string. Pressed in a real browser, the verb answered `{"ok":true,"latest":"0.48.1"}` and
     * wrote nothing: `ships()` caches best-effort, so a bad root loses the cache silently and still
     * returns the hashes, while `remember()` — the file the render reads — failed. The button worked and
     * the page kept saying nobody had asked (greenhouse decisions/0294).
     *
     * Asked per request, like `HouseSource::root()` does for the same reason.
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ResponseFactoryInterface $responses = new Psr17Factory(),
    ) {
    }

    /** The app root, or '' when nothing can say — in which case this route refuses rather than writing nowhere. */
    private function root(): string
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;

        return $kernel instanceof Kernel ? $kernel->root() : '';
    }

    /**
     * Asks, fetches, remembers — and says what it could not do rather than throwing.
     *
     * A laptop with no network is the ordinary case for this button, and «I could not reach the
     * registry» is a sentence a person can act on. A stack trace is not.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $root = $this->root();
        if ($root === '') {
            return $this->json(200, ['ok' => false, 'error' => 'no_app_root']);
        }

        $latest = FrameworkRelease::latest();
        if ($latest === null) {
            return $this->json(200, ['ok' => false, 'error' => 'registry_unreachable']);
        }

        $ships = FrameworkRelease::ships($latest, $root);
        if ($ships === null) {
            return $this->json(200, ['ok' => false, 'error' => 'release_unavailable', 'latest' => $latest]);
        }

        FrameworkRelease::remember($root, $latest, gmdate('c'));

        return $this->json(200, ['ok' => true, 'latest' => $latest, 'files' => \count($ships)]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(int $status, array $payload): ResponseInterface
    {
        $response = $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write((string) json_encode($payload, \JSON_UNESCAPED_SLASHES));

        return $response;
    }
}
