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

namespace Milpa\Admin\Tests\Fixtures;

use Milpa\Admin\Http\RequestPrincipal;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The SHAPE of app-runtime's passkey gate, for a suite that does not install app-runtime: the bootstrap
 * binds the real class name (`AdminSettings::PASSKEY_GATE`) to this fixture when the real class is not
 * there, so `admin.middleware => [PasskeyGateMiddleware::class]` resolves the way it does in an app that
 * has it. It is NOT the gate — it lets every request through — but it leaves what the real gate leaves
 * for a session holding the scope: an authenticated context under `milpa.auth` whose actor is the
 * principal the topbar shows.
 */
final class PasskeyGateStub implements MiddlewareInterface
{
    public const PRINCIPAL = 'passkey:stub';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute(RequestPrincipal::ATTRIBUTE, self::context(self::PRINCIPAL)));
    }

    /**
     * An object shaped like milpa/auth's `AuthContext` for an authenticated actor — `isAuthenticated()`
     * true and a public `actor` with a public string `id` — without the package.
     */
    public static function context(string $id): object
    {
        return new class ($id) {
            public object $actor;

            public function __construct(string $id)
            {
                $this->actor = new class ($id) {
                    public function __construct(public string $id)
                    {
                    }
                };
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
