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

use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Controllers\AdminController;
use Milpa\Admin\Controllers\LiveController;
use Milpa\Admin\Tests\Fixtures\CounterRenderer;
use Milpa\Admin\Tests\Fixtures\ViewPlugin;
use Milpa\Container\DIContainer;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The panel's own live wire (greenhouse decisions/0211): `POST /milpa/admin/live`, over the SAME registry
 * the page compiled with, verifying the SAME signature and the SAME CSRF token the page issued — measured
 * end to end, from the HTML a guest's component painted to the HTML the wire paints back.
 */
final class LiveControllerTest extends TestCase
{
    public function testAGuestsComponentTakesItsActionThroughThePanelsWire(): void
    {
        [$container, $plugin] = self::boot();
        $page = (string) self::admin($container)->section(self::sectionRequest(ViewPlugin::SECTION))->getBody();

        // Everything the runtime would read off the page: the boot the panel issued, and the envelope the
        // guest's own renderer signed. Nothing is minted here — this is the page talking to its own wire.
        $boot = self::boot_payload($page);
        self::assertSame('/milpa/admin/live', $boot['endpoint']);
        $envelope = self::envelopeOf($page, 'lab-a');
        self::assertStringContainsString('data-count="1">1</div>', $page, 'the guest painted 1');

        $response = self::wire($container)->live(self::action([
            'action' => 'bump',
            'state' => $envelope,
            'payload' => [],
            'sessionId' => $boot['sessionId'],
            'csrfToken' => $boot['csrfToken'],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = self::json($response);
        self::assertTrue($body['ok'] ?? false, json_encode($body));
        self::assertStringContainsString('data-count="2">2</div>', (string) ($body['html'] ?? ''), 'the wire re-rendered the guest\'s component with the renderer the guest declared');
        self::assertNotSame($envelope, (string) ($body['state'] ?? ''), 'and handed back a fresh envelope');
        self::assertSame('lab-a', $body['componentId'] ?? null);
        self::assertInstanceOf(Kernel::class, $plugin);
    }

    /**
     * The page a declared view lands on: ONE runtime for the whole document, every declared file once,
     * Alpine last, and no frame anywhere (greenhouse decisions/0211, retiring the iframe of 0210).
     */
    public function testThePageOfADeclaredViewEmitsOneRuntimeAndTheGuestsAssetsOnce(): void
    {
        [$container] = self::boot();

        $page = (string) self::admin($container)->section(self::sectionRequest(ViewPlugin::SECTION))->getBody();

        self::assertStringNotContainsString('<iframe', $page);
        self::assertSame(1, substr_count($page, 'src="/milpa/admin/assets/milpa-live.js"'), 'one local runtime');
        self::assertSame(1, substr_count($page, 'src="/milpa/admin/assets/milpa-live-remote.js"'), 'one remote runtime');
        self::assertSame(1, substr_count($page, 'src="/milpa/admin/assets/alpine.min.js"'), 'one Alpine');
        self::assertSame(1, substr_count($page, CounterRenderer::SCRIPT), 'the guest\'s module, although two nodes declared it');
        self::assertSame(1, substr_count($page, CounterRenderer::STYLE));
        self::assertSame(1, substr_count($page, 'id="milpa-live-boot"'));
        self::assertSame(1, substr_count($page, 'id="milpa-live-signals"'));
        self::assertStringContainsString('"' . ViewPlugin::SIGNAL . '":0', $page, 'the view\'s seed is in the page\'s one signals tag');
        self::assertStringContainsString('"admin.section":"' . ViewPlugin::SECTION . '"', $page, 'next to the panel\'s own');
        self::assertTrue(
            strpos($page, CounterRenderer::SCRIPT) < strpos($page, 'alpine.min.js'),
            'Alpine last: it cannot be guarded against a second copy, only emitted once, after every factory is registered',
        );

        // The panel's own sections still render, on the same page shape.
        $plugins = (string) self::admin($container)->section(self::sectionRequest('plugins'))->getBody();
        self::assertStringContainsString('admin-section--admin-plugins', $plugins);
        self::assertStringNotContainsString(CounterRenderer::SCRIPT, $plugins, 'a guest\'s files travel only with the page that mounts it');
        self::assertSame(1, substr_count($plugins, 'src="/milpa/admin/assets/alpine.min.js"'));
    }

    /** Even with nothing declared, the panel emits exactly one runtime and its three seed tags. */
    public function testTheEmptyPanelStillEmitsOneRuntime(): void
    {
        $container = new DIContainer();
        $settings = \Milpa\Admin\AdminSettings::fromConfig(null);
        $catalog = new \Milpa\Admin\I18n\Catalog();
        $codec = new \Milpa\Live\Security\SignedXhtmlStateTransferCodec(
            new \Milpa\Live\Transport\XhtmlStateTransferCodec(),
            new \Milpa\Live\Security\HmacStateSigner('test-secret-0123456789'),
            null,
        );
        $controller = new AdminController(
            $container,
            new \stdClass(),
            $catalog,
            new \Milpa\Admin\View\AdminShell($settings, $catalog, $codec),
            new \Milpa\Admin\View\AdminPage($settings, $catalog),
            new \Milpa\Live\Security\HmacCsrfGuard('test-secret-0123456789'),
            $settings,
        );

        $html = (string) $controller->index(new ServerRequest('GET', '/milpa/admin'))->getBody();

        self::assertStringContainsString('No plugin declared an admin section yet', $html);
        self::assertSame(1, substr_count($html, 'src="/milpa/admin/assets/alpine.min.js"'));
        self::assertSame(1, substr_count($html, 'id="milpa-live-boot"'));
        self::assertStringContainsString('"admin.section":""', $html, 'no section open: the panel still says which gate and which locale');
    }

    /** The CSRF token is bound to the panel's own route and session: another page's token is refused. */
    public function testAForeignOrMissingTokenIsRefused(): void
    {
        [$container] = self::boot();
        $page = (string) self::admin($container)->section(self::sectionRequest(ViewPlugin::SECTION))->getBody();
        $boot = self::boot_payload($page);
        $envelope = self::envelopeOf($page, 'lab-a');

        $refused = self::wire($container)->live(self::action([
            'action' => 'bump',
            'state' => $envelope,
            'sessionId' => $boot['sessionId'],
            'csrfToken' => 'not-a-token',
        ]));
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('csrf', self::json($refused)['error'] ?? null);

        $wrongSession = self::wire($container)->live(self::action([
            'action' => 'bump',
            'state' => $envelope,
            'sessionId' => 'live-someone-else',
            'csrfToken' => $boot['csrfToken'],
        ]));
        self::assertSame(403, $wrongSession->getStatusCode(), 'a token is bound to the session the boot named');

        $notPost = self::wire($container)->live(
            new ServerRequest('GET', '/milpa/admin/live', ['Content-Type' => 'application/json'], '{}'),
        );
        self::assertSame(405, $notPost->getStatusCode());
    }

    /** An envelope nobody signed — or one signed with another key — never mounts a component. */
    public function testATamperedEnvelopeIsRefused(): void
    {
        [$container] = self::boot();
        $page = (string) self::admin($container)->section(self::sectionRequest(ViewPlugin::SECTION))->getBody();
        $boot = self::boot_payload($page);

        $refused = self::wire($container)->live(self::action([
            'action' => 'bump',
            'state' => str_replace('lab-a', 'lab-x', self::envelopeOf($page, 'lab-a')),
            'sessionId' => $boot['sessionId'],
            'csrfToken' => $boot['csrfToken'],
        ]));

        self::assertSame(400, $refused->getStatusCode());
        self::assertSame('invalid_signature', self::json($refused)['error'] ?? null);
    }

    /**
     * A DECLARATION the panel cannot honour — here a section naming a component nothing registered — is the
     * same refusal the page turns into its 500 document, said as JSON because this surface answers a runtime.
     */
    public function testASectionTheBookRefusesIsA500ThatSaysWhy(): void
    {
        $container = new DIContainer();
        $broken = new class () implements \Milpa\Admin\Section\AdminSectionProvider {
            public function adminSections(): array
            {
                return [new \Milpa\Admin\Section\AdminSection('ghost', 'Ghost', 'no-such-component')];
            }
        };
        $wire = new LiveController(
            $container,
            $broken,
            new \Milpa\Live\Security\SignedXhtmlStateTransferCodec(
                new \Milpa\Live\Transport\XhtmlStateTransferCodec(),
                new \Milpa\Live\Security\HmacStateSigner('test-secret-0123456789'),
                null,
            ),
            new \Milpa\Live\Security\HmacCsrfGuard('test-secret-0123456789'),
            \Milpa\Admin\AdminSettings::fromConfig(null),
        );

        $response = $wire->live(self::action(['action' => 'bump', 'state' => 'x']));

        self::assertSame(500, $response->getStatusCode());
        $body = self::json($response);
        self::assertSame('sections', $body['error'] ?? null);
        self::assertStringContainsString('names component «no-such-component»', (string) ($body['message'] ?? ''));
    }

    /** A body that is not JSON at all is an ordinary bad request, never a crash. */
    public function testAnEmptyBodyIsABadRequest(): void
    {
        [$container] = self::boot();

        $response = self::wire($container)->live(
            new ServerRequest('POST', '/milpa/admin/live', ['Content-Type' => 'application/json'], 'not json'),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse(self::json($response)['ok'] ?? true);
    }

    /**
     * The panel and one guest that declares a VIEW, booted by the real kernel — the same reading the page
     * and the wire each do per request.
     *
     * @return array{0: DIContainerInterface, 1: object}
     */
    private static function boot(): array
    {
        $container = new DIContainer();
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [AdminPlugin::class, ViewPlugin::class], 'config' => [], 'container' => $container]);
        $container->registerService(Kernel::class, $kernel);

        return [$container, $kernel];
    }

    private static function admin(DIContainerInterface $container): AdminController
    {
        $controller = $container->get(AdminController::class);
        \assert($controller instanceof AdminController);

        return $controller;
    }

    private static function wire(DIContainerInterface $container): LiveController
    {
        $controller = $container->get(LiveController::class);
        \assert($controller instanceof LiveController);

        return $controller;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function action(array $body): ServerRequest
    {
        return new ServerRequest('POST', '/milpa/admin/live', ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private static function sectionRequest(string $id): ServerRequest
    {
        $route = new Route(path: '/milpa/admin/s/{id}', handler: HandlerReference::method(AdminController::class, 'section'));

        return (new ServerRequest('GET', '/milpa/admin/s/' . $id))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route, ['id' => $id]));
    }

    /**
     * The boot payload the page emitted — what the client runtime reads out of `#milpa-live-boot`.
     *
     * @return array{endpoint: string, sessionId: string, csrfToken: string}
     */
    private static function boot_payload(string $page): array
    {
        self::assertSame(1, preg_match('~<script id="milpa-live-boot" type="application/json">(.*?)</script>~s', $page, $found), 'the page carries exactly one boot');
        $payload = json_decode($found[1], true);
        self::assertIsArray($payload);

        return [
            'endpoint' => (string) ($payload['endpoint'] ?? ''),
            'sessionId' => (string) ($payload['sessionId'] ?? ''),
            'csrfToken' => (string) ($payload['csrfToken'] ?? ''),
        ];
    }

    /** The signed envelope a component left on the page, read the way the runtime reads it. */
    private static function envelopeOf(string $page, string $componentId): string
    {
        $pattern = '~<script type="application/milpa\+xhtml" data-milpa-state="' . preg_quote($componentId, '~') . '">(.*?)</script>~s';
        self::assertSame(1, preg_match($pattern, $page, $found), 'the component left its envelope on the page');

        return html_entity_decode($found[1], \ENT_QUOTES | \ENT_XML1, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
