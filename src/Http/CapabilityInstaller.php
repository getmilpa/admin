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

use Milpa\Console\Http\HttpProjector;
use Milpa\Console\Http\UnguardedOperationException;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The route the panel's Install button posts to.
 *
 * The panel listed what a house could grow and offered a button that answered 404, because
 * `capabilities:enable` reaches HTTP only when the APP names it in `config/http.php` — and a fresh
 * app names nothing. The button told the truth about it and pointed at the command, which is honest
 * and is not station 4: the whole point of that station is equipping the house FROM the panel
 * (greenhouse decisions/0248).
 *
 * So the panel mounts what its own button needs, exactly as it already mounts its page and its
 * assets. The app's global operations surface is untouched: nothing else becomes reachable, and a
 * house that exposes nothing keeps exposing nothing.
 *
 * ── THE TWO GATES ARE BOTH KEPT, AND THAT IS THE CONDITION FOR DOING IT THIS WAY ────────────────
 *
 *   - the NETWORK gate — this route carries the panel's own middleware, loopback-only by default,
 *     the same one that decides who may look at the panel at all;
 *   - the CONSENT gate — the operation still demands its confirmation: the first POST answers
 *     `requires_confirmation` with a token and does nothing, and only a second POST carrying that
 *     token proceeds. The ceremony is the projector's, not a copy: a second implementation of one
 *     act could only ever be the wrong one.
 *
 * Mounting a route grants no authority (`decisions/0240`, invariant 1). The operation goes on
 * declaring what it declares — privileged, downloads third-party code, manual recovery — and the
 * gate reads that.
 *
 * ── WHY IT DELEGATES INSTEAD OF BEING A PROJECTOR ──────────────────────────────────────────────
 *
 * `HttpProjector` resolves which operation to run from the matched ROUTE NAME, and it registers
 * under its own class so every projected route resolves to one instance. Two packages each mounting
 * their own would leave one set of routes resolving against an instance that has never heard of
 * their operations — a 404 with no error anywhere, which is exactly the failure this class exists to
 * remove. So the panel keeps its own class key, its own path and its own route name, and supplies
 * the name the projector needs rather than borrowing it from the router.
 */
final class CapabilityInstaller
{
    public const string OPERATION = 'capabilities:enable';

    public const string ROUTE_NAME = 'milpa_admin_capability_enable';

    /**
     * The sentence this panel answers with when the app has no judge for the act.
     *
     * A constant because two surfaces say it: this route, when a click arrives anyway, and the section
     * that decides whether to paint the button at all. One text, so the refusal and the explanation
     * cannot drift (greenhouse decisions/0289).
     */
    public const string NO_JUDGE = 'This app cannot authorize «' . self::OPERATION . '» over HTTP: it registered no OperationHttpPolicy, so nothing here can hold the scope the act declares. Install milpa/auth and enrol a passkey, or run `coa capabilities:enable <package> --sign` from a terminal.';

    public function __construct(
        private readonly HttpProjector $projector,
        private readonly ResponseFactoryInterface $responses = new Psr17Factory(),
    ) {
    }


    /**
     * Runs the install the panel's button asked for, through the operation's own HTTP ceremony.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // The projector looks the operation up by matched route name. This route has its own name so
        // it can never collide with a host that exposes the same operation, so the name it needs is
        // handed over here — the one place that knows both.
        $routed = $request->withAttribute(
            RouteResult::ATTRIBUTE,
            RouteResult::matched(new Route(
                path: '/',
                methods: HttpMethod::POST,
                name: self::OPERATION,
                handler: null,
            )),
        );

        // 🚨 A NAMEABLE REFUSAL IS NOT AN EXCEPTION. Measured in a browser on fresh cattle: this button
        // answered `internal_error`, and only the app's log said why — «exige los scopes […] y este host
        // no cableó una OperationHttpPolicy». The framework names that same condition at BOOT when an
        // app lists the operation in `config/http.php`; letting it escape HERE turns a sentence somebody
        // can act on into a stack trace and a 500 (greenhouse decisions/0289).
        //
        // 501 and not 403: the caller is not being denied, and nothing about them would change the
        // answer. This app has not implemented a way to judge the act at all.
        try {
            return $this->projector->handle($routed);
        } catch (UnguardedOperationException) {
            $response = $this->responses->createResponse(501)->withHeader('Content-Type', 'application/json');
            $response->getBody()->write((string) json_encode(
                ['ok' => false, 'error' => self::NO_JUDGE],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ));

            return $response;
        }
    }
}
