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

namespace Milpa\Admin\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who the gate let in, read from the request — never from a cookie, never from a session store.
 *
 * A gate that authenticates (app-runtime's `PasskeyGateMiddleware`, or anything built on `milpa/auth`'s
 * `AuthenticateMiddleware`) leaves its verdict on the request under the attribute `milpa.auth`: an
 * `AuthContext` — `isAuthenticated()`, and a public `actor` whose public `id` is the principal. The
 * panel takes no dependency on `milpa/auth`, so it reads that shape by duck-typing, and fails closed:
 * no attribute, a non-object, a context that does not say it is authenticated, an actor without a
 * non-empty string id — each is «nobody», and the topbar shows no chip. What comes back is the ACTOR's
 * id (`passkey:…`), never a session id: the session is the gate's, and the panel never sees it
 * (greenhouse decisions/0206).
 */
final class RequestPrincipal
{
    /** The request attribute `AuthenticateMiddleware` (milpa/auth) and `PasskeyGateMiddleware` (app-runtime) leave the `AuthContext` under. */
    public const ATTRIBUTE = 'milpa.auth';

    /** The authenticated actor's id, or null when the request carries no authenticated context. */
    public static function of(ServerRequestInterface $request): ?string
    {
        $context = $request->getAttribute(self::ATTRIBUTE);
        if (!\is_object($context) || !method_exists($context, 'isAuthenticated') || $context->isAuthenticated() !== true) {
            return null;
        }
        $actor = self::member($context, 'actor');
        if (!\is_object($actor)) {
            return null;
        }
        $id = self::member($actor, 'id');

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /** One member of a foreign object: its public property of that name, else its public method of that name called, else null. */
    private static function member(object $subject, string $name): mixed
    {
        $public = get_object_vars($subject);
        if (\array_key_exists($name, $public)) {
            return $public[$name];
        }

        return method_exists($subject, $name) ? $subject->{$name}() : null;
    }
}
