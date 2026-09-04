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

namespace Milpa\Admin\Tests\Http;

use Milpa\Admin\Http\RequestPrincipal;
use Milpa\Admin\Tests\Fixtures\PasskeyGateStub;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class RequestPrincipalTest extends TestCase
{
    public function testTheAuthenticatedActorsIdIsThePrincipalWhicheverShapeTheContextHas(): void
    {
        self::assertSame('milpa.auth', RequestPrincipal::ATTRIBUTE, 'the attribute milpa/auth\'s AuthenticateMiddleware leaves its context under');
        self::assertSame('passkey:rod', RequestPrincipal::of(self::with(PasskeyGateStub::context('passkey:rod'))), 'public actor, public id — milpa/auth\'s own shape');

        $accessors = new class () {
            public function isAuthenticated(): bool
            {
                return true;
            }

            public function actor(): object
            {
                return new class () {
                    public function id(): string
                    {
                        return 'agent:qwen';
                    }
                };
            }
        };
        self::assertSame('agent:qwen', RequestPrincipal::of(self::with($accessors)), 'a context that answers through methods is read the same');
    }

    public function testEverythingElseIsNobody(): void
    {
        self::assertNull(RequestPrincipal::of(new ServerRequest('GET', '/milpa/admin')), 'no gate ran: no attribute');
        self::assertNull(RequestPrincipal::of(self::with('passkey:rod')), 'a bare string is not a context — never trusted');
        self::assertNull(RequestPrincipal::of(self::with(['actor' => ['id' => 'passkey:rod']])), 'nor an array');
        self::assertNull(RequestPrincipal::of(self::with(new \stdClass())), 'an object that cannot say it is authenticated is not');

        $anonymous = new class () {
            public ?object $actor = null;

            public function isAuthenticated(): bool
            {
                return false;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($anonymous)), 'anonymous: fail closed');

        $liar = new class () {
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($liar)), 'authenticated with no actor at all: nobody');

        $blank = new class () {
            public object $actor;

            public function __construct()
            {
                $this->actor = new class () {
                    public string $id = '';
                };
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($blank)), 'an empty id is not a principal');

        $numeric = new class () {
            public object $actor;

            public function __construct()
            {
                $this->actor = new class () {
                    public int $id = 42;
                };
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($numeric)), 'an id that is not a string is not rendered');

        $scalarActor = new class () {
            public string $actor = 'passkey:rod';

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        self::assertNull(RequestPrincipal::of(self::with($scalarActor)), 'an actor that is not an object has no id to read');
    }

    private static function with(mixed $context): ServerRequest
    {
        return (new ServerRequest('GET', '/milpa/admin'))->withAttribute(RequestPrincipal::ATTRIBUTE, $context);
    }
}
