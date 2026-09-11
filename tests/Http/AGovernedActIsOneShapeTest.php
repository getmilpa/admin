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

use Milpa\Admin\Http\GovernedAct;
use Milpa\Console\Http\UnguardedOperationException;
use Milpa\Http\Routing\RouteResult;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ONE SHAPE FOR BOTH GOVERNED BUTTONS — and the refusal is the part that must not drift.
 *
 * Installing a capability and applying a newer framework are the same act with a different name: a
 * scoped, consent-demanding operation projected behind the panel's own door. Written twice, the half
 * that would drift is the refusal — the part a person reads (greenhouse decisions/0297).
 */
#[CoversClass(GovernedAct::class)]
final class AGovernedActIsOneShapeTest extends TestCase
{
    /**
     * 🚨 THE PROJECTOR IS TOLD WHICH OPERATION BY THE MATCHED ROUTE'S NAME.
     *
     * `HttpProjector` reads the operation out of the route it matched, and the panel's routes carry
     * their OWN names so they can never collide with a host that also exposes the same operation
     * through `config/http.php`. So the operation name is put on a synthetic matched route here — the
     * one place that knows both names.
     */
    public function testItNamesTheOperationOnTheMatchedRouteTheProjectorReads(): void
    {
        $seen = null;
        $projector = new class ($seen) {
            public function __construct(public ?string &$seen)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $routed = $request->getAttribute(RouteResult::ATTRIBUTE);
                $this->seen = $routed instanceof RouteResult ? $routed->route?->name : null;

                return new Response(200, [], '{"ok":true}');
            }
        };

        $answer = GovernedAct::run(
            $projector,
            'framework:apply',
            new ServerRequest('POST', '/milpa/admin/framework/apply'),
            new Psr17Factory(),
            'no judge',
        );

        self::assertSame('framework:apply', $projector->seen, 'the projector resolves by route NAME');
        self::assertSame(200, $answer->getStatusCode());
        self::assertSame('{"ok":true}', (string) $answer->getBody());
    }

    /**
     * 🚨 A NAMEABLE REFUSAL IS NOT AN EXCEPTION — 501, with a sentence.
     *
     * Measured in a browser on fresh cattle: the install button answered `internal_error` and only the
     * app's log said why. The framework names that same condition at BOOT when an app lists the
     * operation in `config/http.php`; letting it escape here turns a sentence somebody can act on into
     * a stack trace.
     */
    public function testWithoutAPolicyItAnswers501AndSaysWhy(): void
    {
        $projector = new class () {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new UnguardedOperationException('capabilities:enable');
            }
        };

        $answer = GovernedAct::run(
            $projector,
            'capabilities:enable',
            new ServerRequest('POST', '/milpa/admin/capabilities/enable'),
            new Psr17Factory(),
            'This app has no way to judge the act: install milpa/auth.',
        );

        // 501 and NOT 403: the caller is not being denied, and nothing about them would change the
        // answer. This app has not implemented a way to judge the act at all.
        self::assertSame(501, $answer->getStatusCode());
        self::assertSame('application/json', $answer->getHeaderLine('Content-Type'));

        $body = json_decode((string) $answer->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['ok']);
        self::assertSame('This app has no way to judge the act: install milpa/auth.', $body['error']);
    }

    /**
     * The ceremony passes through untouched — including the 428 that asks for consent.
     *
     * The panel does not interpret the answer: a `requires_confirmation` reaching the browser as
     * anything but itself would be a second consent mechanism, and this house has one.
     */
    public function testTheConsentCeremonyReachesTheCallerUnchanged(): void
    {
        $projector = new class () {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(428, ['Content-Type' => 'application/json'], '{"requires_confirmation":true,"confirm_token":"t0k"}');
            }
        };

        $answer = GovernedAct::run(
            $projector,
            'framework:apply',
            new ServerRequest('POST', '/x'),
            new Psr17Factory(),
            'no judge',
        );

        self::assertSame(428, $answer->getStatusCode());
        self::assertStringContainsString('"confirm_token":"t0k"', (string) $answer->getBody());
    }

    /** Any other failure is NOT swallowed: only the one condition that has a sentence is caught. */
    public function testItOnlyCatchesTheConditionItCanName(): void
    {
        $projector = new class () {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('the disk is on fire');
            }
        };

        $this->expectException(\RuntimeException::class);
        GovernedAct::run($projector, 'framework:apply', new ServerRequest('POST', '/x'), new Psr17Factory(), 'no judge');
    }
}
