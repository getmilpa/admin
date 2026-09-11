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
use Milpa\Admin\Http\FrameworkApplier;
use Milpa\Command\Operation;
use Milpa\Console\Http\HttpProjector;
use Milpa\Container\DIContainer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THE APPLY BUTTON IS THE INSTALL BUTTON WITH ANOTHER OPERATION'S NAME.
 *
 * Both are a scoped, consent-demanding operation projected behind the panel's own door, so both go
 * through {@see \Milpa\Admin\Http\GovernedAct}. What must not drift between them is the REFUSAL — the
 * part a person reads (greenhouse decisions/0297).
 *
 * The route list and the `no_app_root` answer are already claimed by
 * {@see \Milpa\Admin\Tests\AdminPluginTest}; this file does not restate them. A first draft did, and
 * measuring it showed it added zero covered lines — a test that asserts what another test already
 * proved is maintenance with no signal.
 */
#[CoversClass(FrameworkApplier::class)]
final class TheApplierIsTheInstallersTwinTest extends TestCase
{
    /**
     * 🚨 THE REFUSAL NAMES THE FIX AND THE DOOR THAT WAS NEVER CLOSED.
     *
     * `framework:apply` declares a scope, so an app with no `OperationHttpPolicy` has nothing that can
     * hold it. Naming only the problem would leave a person stuck at a button; the sentence names the
     * package to install AND the command they can still run, because someone with a terminal was never
     * blocked by the panel's missing judge (greenhouse decisions/0289).
     */
    public function testTheRefusalNamesTheOperationTheFixAndTheTerminal(): void
    {
        self::assertStringContainsString(FrameworkApplier::OPERATION, FrameworkApplier::NO_JUDGE);
        self::assertStringContainsString('milpa/auth', FrameworkApplier::NO_JUDGE);
        self::assertStringContainsString('coa framework:apply', FrameworkApplier::NO_JUDGE);

        // And it is the installer's sentence with a different act in it, not a second wording of the
        // same condition: the two refusals name the same fix.
        self::assertStringContainsString('milpa/auth', CapabilityInstaller::NO_JUDGE);
        self::assertNotSame(CapabilityInstaller::NO_JUDGE, FrameworkApplier::NO_JUDGE);
    }

    /**
     * With no policy registered, pressing apply answers 501 and that sentence — not a stack trace.
     *
     * This is the whole handler: it hands its operation's name to the shared act. An `HttpProjector`
     * built with no policy over an operation that declares a scope is exactly the app the panel ships
     * into before `milpa/auth` arrives.
     */
    public function testPressingItWithoutAPolicyAnswers501AndTheSentence(): void
    {
        $psr17 = new Psr17Factory();
        // The act as app-runtime declares it — what matters here is that it DECLARES A SCOPE, because
        // that is the condition with no judge to hold it.
        $act = new Operation(
            name: FrameworkApplier::OPERATION,
            description: 'Applies the files a newer framework ships.',
            handler: static fn (): array => ['ok' => true],
            mutating: true,
            scopes: ['framework:apply'],
        );
        $applier = new FrameworkApplier(new HttpProjector([$act], new DIContainer(), $psr17, $psr17), $psr17);

        $answer = $applier->handle(new ServerRequest('POST', '/milpa/admin/framework/apply'));

        self::assertSame(501, $answer->getStatusCode());
        self::assertSame('application/json', $answer->getHeaderLine('Content-Type'));

        $body = json_decode((string) $answer->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['ok']);
        self::assertSame(FrameworkApplier::NO_JUDGE, $body['error']);
    }

    /**
     * 🚨 AN UNKNOWN ACT IS A 404, NOT THIS SENTENCE — the two refusals are not the same refusal.
     *
     * The first run of the test above passed the projector no operations and got a 404, which is
     * right: «I do not know that act» and «I cannot judge that act» are different facts, and reading
     * the second into the first would tell a person to install `milpa/auth` for a typo.
     */
    public function testAnUnknownActIsNotMistakenForAnUnjudgeableOne(): void
    {
        $psr17 = new Psr17Factory();
        $applier = new FrameworkApplier(new HttpProjector([], new DIContainer(), $psr17, $psr17), $psr17);

        $answer = $applier->handle(new ServerRequest('POST', '/milpa/admin/framework/apply'));

        self::assertSame(404, $answer->getStatusCode());
        self::assertStringNotContainsString('milpa/auth', (string) $answer->getBody());
    }
}
