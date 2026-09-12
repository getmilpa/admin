<?php

/**
 * This file is part of Milpa Admin — the administration panel of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin\Tests;

use Milpa\Admin\AdminPlugin;
use Milpa\Admin\AdminSettings;
use Milpa\Admin\Components\IdentityComponent;
use Milpa\Admin\Controllers\AdminController;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\Http\RequestPrincipal;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Rendering\AdminHtmlRenderer;
use Milpa\Admin\View\AdminShell;
use Milpa\Container\DIContainer;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RouteProviderInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/** The panel projects this request's authenticated facts, never a prior visitor or submitted state. */
final class IdentitySectionTest extends TestCase
{
    public function testUnknownEmptyAndExplicitScopesRemainDifferentFacts(): void
    {
        foreach ([null, [], ['*'], ['plugins.Owned:write'], ['a', 1], [''], ['role' => 'admin']] as $scopes) {
            $request = self::request()->withAttribute(RequestPrincipal::ATTRIBUTE, self::actor('key:one', $scopes));
            $expected = \in_array($scopes, [[], ['*'], ['plugins.Owned:write']], true) ? $scopes : null;
            self::assertSame(['principal' => 'key:one', 'scopes' => $expected], RequestPrincipal::identity($request));
        }
        self::assertSame(['principal' => null, 'scopes' => null], RequestPrincipal::identity(self::request()));
        self::assertSame(['principal' => null, 'scopes' => null], RequestPrincipal::identity(
            self::request()->withAttribute(RequestPrincipal::ATTRIBUTE, self::actor('', ['*'])),
        ));
    }

    public function testOneControllerUsesEachRequestsIdentityAndIgnoresQueryClaims(): void
    {
        $container = new DIContainer();
        $plugin = new AdminPlugin($container);
        $plugin->boot();
        $controller = $container->get(AdminController::class);
        self::assertInstanceOf(AdminController::class, $controller);
        self::assertSame('no-store', $controller->section(self::request())->getHeaderLine('Cache-Control'));
        $first = (string) $controller->section(self::request()->withAttribute(RequestPrincipal::ATTRIBUTE, self::actor('passkey:one', ['plugins.Owned:write'])))->getBody();
        self::assertStringContainsString('Your identity in this house', $first);
        self::assertStringContainsString('<code>plugins.Owned:write</code>', $first);

        $second = (string) $controller->section(self::request()->withAttribute(RequestPrincipal::ATTRIBUTE, self::actor('passkey:two', ['agent:read'])))->getBody();
        self::assertStringContainsString('<code>agent:read</code>', $second);
        self::assertStringNotContainsString('plugins.Owned:write', $second);
        self::assertStringNotContainsString('passkey:one', $second);

        $anonymous = (string) $controller->section(self::request()->withQueryParams(['principal' => 'forged-visitor', 'scopes' => ['forged:scope']]))->getBody();
        self::assertStringContainsString('This surface has no authenticated identity.', $anonymous);
        self::assertStringNotContainsString('forged-visitor', $anonymous);
        self::assertStringNotContainsString('forged:scope', $anonymous);
        self::assertStringNotContainsString('passkey:two', $anonymous);

        $spanish = (string) $controller->section(self::request()->withQueryParams(['lang' => 'es']))->getBody();
        self::assertStringContainsString('Tu identidad en esta casa', $spanish);
        self::assertStringContainsString('>Identidad</span>', $spanish);

        $terminal = $plugin->sectionStates()['identity']->state();
        self::assertNull($terminal['principal']);
        self::assertNull($terminal['scopes']);
    }

    public function testScopesAreEscapedAndAnOldEnvelopeCannotPaintAnotherIdentity(): void
    {
        $component = self::component();
        $renderer = self::renderer();
        $stale = new StateSnapshot('identity-test', IdentityComponent::NAME, '1', ['principal' => 'old-owner', 'scopes' => ['old:scope']]);
        $context = new ComponentContext(componentId: 'identity-test', principal: 'key:<script>visitor</script>', meta: [AdminShell::META_SCOPES => ['<script>scope</script>']]);
        $rendered = $renderer->render($component, new RenderRequest(context: $context, state: $stale));
        self::assertSame('key:<script>visitor</script>', $rendered->state?->data['principal']);
        self::assertStringNotContainsString('old-owner', $rendered->output);
        self::assertStringNotContainsString('old:scope', $rendered->output);
        self::assertStringNotContainsString('<script>scope', $rendered->output);
        self::assertStringContainsString('&lt;script&gt;scope&lt;/script&gt;', $rendered->output);

        $empty = $renderer->render($component, new RenderRequest(context: new ComponentContext(componentId: 'i', principal: 'key:one', meta: [AdminShell::META_SCOPES => []])))->output;
        self::assertStringContainsString('This identity declares no scopes.', $empty);
        $unknown = $renderer->render($component, new RenderRequest(context: new ComponentContext(componentId: 'i', principal: 'key:one')))->output;
        self::assertStringContainsString('The gate did not provide a readable list of scopes.', $unknown);
    }

    public function testOnlyMountedLocalGetCeremoniesAreOfferedWithTheDeclaredReturnRoute(): void
    {
        $component = self::component([
            new Route('/keys/signin', HttpMethod::GET, name: 'passkey.signin.page'),
            new Route('/keys/register', HttpMethod::GET, name: 'passkey.enroll.page'),
            new Route('//outside.invalid/enroll', HttpMethod::GET, name: 'passkey.enroll.page'),
            new Route('/keys/unsafe?destination=elsewhere', HttpMethod::GET, name: 'passkey.enroll.page'),
            new Route('/keys/{id}', HttpMethod::GET, name: 'passkey.enroll.page'),
            new Route('/keys/post-only', HttpMethod::POST, name: 'passkey.signin.page'),
        ]);
        $context = new ComponentContext(componentId: 'i');
        self::assertSame([
            'enroll' => '/keys/register?next=%2Fdesk%2Fs%2Fidentity',
            'signin' => '/keys/signin?next=%2Fdesk%2Fs%2Fidentity',
        ], $component->mount([], $context)->data['ceremonies']);
        $html = self::renderer()->render($component, new RenderRequest(context: $context))->output;
        self::assertStringContainsString('href="/keys/signin?next=%2Fdesk%2Fs%2Fidentity"', $html);
        self::assertStringContainsString('Register a passkey', $html);
        self::assertStringNotContainsString('outside.invalid', $html);
        self::assertSame([], self::component()->mount([], $context)->data['ceremonies']);
        self::assertStringContainsString('does not currently serve a passkey ceremony', self::renderer()->render(self::component(), new RenderRequest(context: $context))->output);
    }

    public function testIdentityDoesNotAcceptAScopeChangingAction(): void
    {
        $component = self::component();
        $state = $component->mount([], new ComponentContext(componentId: 'i'));
        $result = $component->handle(new InteractionRequest('i', IdentityComponent::NAME, 'enroll', $state, ['scopes' => ['*']]));
        self::assertNotEmpty($result->errors);
        self::assertSame($state, $result->state);
    }

    /** @param list<Route> $routes */
    private static function component(array $routes = []): IdentityComponent
    {
        $provider = new class ($routes) implements RouteProviderInterface {
            /** @param list<Route> $declared */
            public function __construct(private array $declared)
            {
            }

            /** @return list<Route> */
            public function routes(): array
            {
                return $this->declared;
            }
        };

        return new IdentityComponent(new RoutesSource(new DIContainer(), $provider), AdminSettings::fromConfig(new Config(['admin' => ['route' => '/desk']])));
    }

    private static function renderer(): AdminHtmlRenderer
    {
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('identity-test-secret'), null);

        return new AdminHtmlRenderer($codec, new Catalog('en'), AdminSettings::fromConfig(null));
    }

    private static function request(): ServerRequest
    {
        return (new ServerRequest('GET', '/milpa/admin/s/identity'))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched(new Route('/milpa/admin/s/{id}'), ['id' => 'identity']));
    }

    private static function actor(string $id, mixed $scopes): object
    {
        return new class ($id, $scopes) {
            public object $actor;

            public function __construct(string $id, mixed $scopes)
            {
                $this->actor = (object) ['id' => $id, 'scopes' => $scopes];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
