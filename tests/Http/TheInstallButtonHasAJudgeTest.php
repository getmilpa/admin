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

namespace Milpa\Admin\Tests\Http;

use Milpa\Admin\Http\CapabilityInstaller;
use Milpa\Command\Operation;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Console\Http\HttpProjector;
use Milpa\Container\DIContainer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * THE PANEL'S INSTALL BUTTON NEEDS A JUDGE, AND IT WAS BUILT WITHOUT ONE.
 *
 * Measured in a real browser on fresh cattle (greenhouse decisions/0289, evidence/0615): clicking
 * «Install milpa/devtools» in the Plugins section answered `internal_error`, and the app's log — wired
 * the same day — named the cause on its first real use:
 *
 *     UnguardedOperationException: La operación «capabilities:enable» exige los scopes
 *     [capabilities:enable] y este host no cableó una Milpa\Console\Http\OperationHttpPolicy.
 *
 * `AdminPlugin` builds the projector with no `policy:` argument at all, so `$policy` is null on EVERY
 * app — with `milpa/auth` installed or without it. The projector throws whenever an operation declares
 * scopes and no policy is present, and `capabilities:enable` declares `capabilities:enable`. So the
 * button could not work anywhere, and the pin's fourth station — «desde el panel equipa la casa» — was
 * a painted screen with a control that always 500s.
 *
 * Two things are wrong and each has its own test below: the policy the app registered was not handed
 * over, and a NAMEABLE refusal escaped as an unhandled throwable, which reaches the person as
 * `internal_error` and reaches the log as a stack trace instead of an answer.
 */
#[CoversClass(CapabilityInstaller::class)]
final class TheInstallButtonHasAJudgeTest extends TestCase
{
    /** The operation as the family declares it: scoped, so it demands a judge. */
    private static function scopedOperation(): Operation
    {
        return new Operation(
            name: CapabilityInstaller::OPERATION,
            description: 'installs a capability',
            handler: static fn (array $input): array => ['ok' => true, 'enabled' => $input['capability'] ?? ''],
            scopes: [CapabilityInstaller::OPERATION],
            surfaces: ['http'],
            mutating: true,
        );
    }

    private static function request(): ServerRequestInterface
    {
        return (new ServerRequest('POST', '/milpa/admin/capabilities/enable', ['Content-Type' => 'application/json']))
            ->withBody((new Psr17Factory())->createStream('{"capability":"milpa/devtools"}'));
    }

    /** A policy that lets everything through — the point is that ONE EXISTS, not what it decides. */
    private static function permissive(): OperationHttpPolicy
    {
        return new class () implements OperationHttpPolicy {
            public function enforce(Operation $operation, ServerRequestInterface $request): ?ResponseInterface
            {
                return null;
            }
        };
    }

    /** WITH a judge the act is served — it reaches the confirm gate instead of throwing. */
    public function testWithAPolicyTheActIsJudgedAndNotThrown(): void
    {
        $psr17 = new Psr17Factory();
        $installer = new CapabilityInstaller(new HttpProjector(
            [self::scopedOperation()],
            new DIContainer(),
            $psr17,
            $psr17,
            policy: self::permissive(),
        ));

        $response = $installer->handle(self::request());

        self::assertNotSame(500, $response->getStatusCode(), 'a judged act does not answer internal_error');
        self::assertSame(428, $response->getStatusCode(), 'it reaches the confirm gate — installing downloads third-party code, so it asks first');
        self::assertStringContainsString('confirm_token', (string) $response->getBody(), 'and hands back the token the second click carries');
    }

    /**
     * WITHOUT a judge it REFUSES IN WORDS — it does not throw.
     *
     * This is the half that reached Rod as `internal_error`. The condition is perfectly nameable at the
     * moment it happens, and the framework already names it at BOOT when an app lists the operation in
     * `config/http.php`. Letting it escape here turns a sentence somebody can act on into a stack trace
     * and a five-hundred.
     */
    public function testWithoutAPolicyItSaysSoInsteadOfThrowing(): void
    {
        $psr17 = new Psr17Factory();
        $installer = new CapabilityInstaller(new HttpProjector(
            [self::scopedOperation()],
            new DIContainer(),
            $psr17,
            $psr17,
        ));

        $response = $installer->handle(self::request());

        self::assertSame(501, $response->getStatusCode(), 'not implemented BY THIS APP — the act is fine, this house cannot judge it');
        $body = (string) $response->getBody();
        self::assertStringContainsString('capabilities:enable', $body, 'it names the operation');
        self::assertStringContainsString('milpa/auth', $body, 'and the package that supplies the judge, because that is the fix');
        self::assertStringNotContainsString('internal_error', $body, 'a nameable refusal is never internal_error');
    }
}
