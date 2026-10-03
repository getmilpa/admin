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

namespace Milpa\Admin\Tests;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Components\IdentityComponent;
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Data\PluginsSource;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\HouseCli;
use Milpa\Admin\Http\CapabilityInstaller;
use Milpa\Admin\Http\FrameworkApplier;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Rendering\AdminHtmlRenderer;
use Milpa\Command\Operation;
use Milpa\Console\Http\HttpProjector;
use Milpa\Container\DIContainer;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Runtime\Http\RouteProviderInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE PANEL PAINTS WHEN THE HOUSE SAYS HOW IT IS REACHED — every surface that tells a person what to type.
 *
 * Each test runs in a process of its own because it loads a stand-in for app-runtime's `Capabilities`
 * under its real name ({@see \Milpa\AppRuntime\Support\Capabilities} in `tests/Fixtures/AppRuntimeSaysTheWayIn.php`):
 * the panel asks that class by string, and no other test of this suite may meet one that is not real.
 * The real one — and its judgement of what a declared way in may be — is measured on a house
 * (greenhouse evidence/1094).
 */
#[CoversClass(HouseCli::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ThePanelAsksTheHouseHowItIsReachedTest extends TestCase
{
    private const string WAY_IN = 'docker exec -it milpa-desktop-backend';

    protected function setUp(): void
    {
        require_once __DIR__ . '/Fixtures/AppRuntimeSaysTheWayIn.php';
        putenv('MILPA_CLI_PREFIX=' . self::WAY_IN);
    }

    protected function tearDown(): void
    {
        putenv('MILPA_CLI_PREFIX');
    }

    /** Plugins: the command form — the one place 1093 named. */
    public function testThePluginsCommandFormCarriesIt(): void
    {
        self::assertStringContainsString(
            '<kbd class="mui-kbd">docker exec -it milpa-desktop-backend php bin/coa capabilities:enable &lt;package&gt; --sign</kbd>',
            self::plugins('en'),
        );
        self::assertStringContainsString(
            '<kbd class="mui-kbd">docker exec -it milpa-desktop-backend php bin/coa capabilities:enable &lt;paquete&gt; --sign</kbd>',
            self::plugins('es'),
        );
    }

    /** House: each of the three next moves that has a command. */
    public function testTheNextMoveOfTheHouseCarriesIt(): void
    {
        $renderer = self::renderer('en');
        $method = new \ReflectionMethod($renderer, 'houseNextMove');

        self::assertSame(
            'docker exec -it milpa-desktop-backend php bin/coa foundation:found --domain="…" --objective="…" --boundaries="…"',
            $method->invoke($renderer, ['declared' => false], [], '')[1],
        );
        self::assertSame(
            'docker exec -it milpa-desktop-backend php bin/coa capabilities:refresh',
            $method->invoke($renderer, ['declared' => true, 'domain' => 'x'], [], 'registry index derived …, offline floor beneath')[1],
        );
        self::assertSame(
            'docker exec -it milpa-desktop-backend php bin/coa capabilities:enable milpa/agent --sign',
            $method->invoke($renderer, ['declared' => true, 'domain' => 'x'], [['id' => 'admin']], 'registry index derived …')[1],
        );
        self::assertSame('', $method->invoke($renderer, ['declared' => true, 'domain' => 'x'], [['id' => 'agent']], 'registry index derived …')[1], 'and a house with nothing to run still has nothing to run');
    }

    /** Identity: the note about the invitation names the command that mints another. */
    public function testTheIdentityNoteCarriesIt(): void
    {
        $provider = new class () implements RouteProviderInterface {
            public function routes(): array
            {
                return [new Route('/keys/signin', HttpMethod::GET, name: 'passkey.signin.page')];
            }
        };
        $component = new IdentityComponent(new RoutesSource(new DIContainer(), $provider), AdminSettings::fromConfig(null));

        $en = self::renderer('en')->render($component, new RenderRequest(context: new ComponentContext(componentId: 'i')))->output;
        $es = self::renderer('es')->render($component, new RenderRequest(context: new ComponentContext(componentId: 'i', locale: 'es')))->output;

        self::assertStringContainsString('(`docker exec -it milpa-desktop-backend php bin/coa identity:invite --sign` mints another).', $en);
        self::assertStringContainsString('(`docker exec -it milpa-desktop-backend php bin/coa identity:invite --sign` acuña otra).', $es);
    }

    /** The two refusals that name the terminal a person can still use. */
    public function testTheRefusalsThatNameTheTerminalCarryIt(): void
    {
        $psr17 = new Psr17Factory();
        $act = static fn (string $name, string $scope): Operation => new Operation(
            name: $name,
            description: 'An act that declares a scope, in an app with no judge to hold it.',
            handler: static fn (): array => ['ok' => true],
            mutating: true,
            scopes: [$scope],
        );

        $install = new CapabilityInstaller(new HttpProjector([$act(CapabilityInstaller::OPERATION, 'capabilities:enable')], new DIContainer(), $psr17, $psr17), $psr17);
        $apply = new FrameworkApplier(new HttpProjector([$act(FrameworkApplier::OPERATION, 'framework:apply')], new DIContainer(), $psr17, $psr17), $psr17);

        $installed = json_decode((string) $install->handle(new ServerRequest('POST', '/milpa/admin/capabilities/enable'))->getBody(), true);
        $applied = json_decode((string) $apply->handle(new ServerRequest('POST', '/milpa/admin/framework/apply'))->getBody(), true);

        self::assertIsArray($installed);
        self::assertIsArray($applied);
        self::assertStringEndsWith('or run `docker exec -it milpa-desktop-backend php bin/coa capabilities:enable <package> --sign` from a terminal.', (string) $installed['error']);
        self::assertStringEndsWith('or run `docker exec -it milpa-desktop-backend php bin/coa framework:apply --sign` from a terminal.', (string) $applied['error']);
    }

    /** And with the same house saying nothing, the same surfaces say what they always said. */
    public function testAHouseThatDeclaresNothingChangesNothing(): void
    {
        putenv('MILPA_CLI_PREFIX');

        self::assertSame('php bin/coa ', HouseCli::cli());
        self::assertStringContainsString('<kbd class="mui-kbd">php bin/coa capabilities:enable &lt;package&gt; --sign</kbd>', self::plugins('en'));
    }

    private static function plugins(string $locale): string
    {
        $state = new StateSnapshot('s1', PluginsComponent::NAME, '1', [
            'registry' => true,
            'plugins' => [],
            'installable' => false,
            'capabilities' => ['available' => [], 'installed' => []],
        ], ['title' => 'Plugins']);

        return self::renderer($locale)->render(
            new PluginsComponent(new PluginsSource(new DIContainer())),
            new RenderRequest(context: new ComponentContext(componentId: $state->componentId, locale: $locale), state: $state),
        )->output;
    }

    private static function renderer(string $locale): AdminHtmlRenderer
    {
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('way-in-test-secret-0123'), null);

        return new AdminHtmlRenderer($codec, new Catalog($locale), AdminSettings::fromConfig(null));
    }
}
