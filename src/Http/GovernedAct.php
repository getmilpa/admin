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

namespace Milpa\Admin\Http;

use Milpa\Console\Http\UnguardedOperationException;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * RUNS ONE NAMED OPERATION THROUGH THE PANEL'S DOOR — the shape both governed buttons share.
 *
 * The panel has two: installing a capability and applying a newer framework. They are the same act with
 * a different name — a scoped, consent-demanding operation, projected over HTTP, behind the gate the
 * section pages already carry. Written twice they would drift, and the half that drifted would be the
 * refusal, which is the part a person reads (greenhouse decisions/0297).
 *
 * ── THE ROUTE NAME TRICK, AND WHY IT IS HERE ────────────────────────────────────────────────────────
 *
 * `HttpProjector` resolves which operation a request is for from the MATCHED ROUTE'S NAME. The panel's
 * routes carry their own names so they can never collide with a host that also exposes the same
 * operation through `config/http.php`, so the operation's name is handed over here — the one place that
 * knows both.
 *
 * ── AND A NAMEABLE REFUSAL IS NOT AN EXCEPTION ──────────────────────────────────────────────────────
 *
 * 🚨 Measured in a browser on fresh cattle: the install button answered `internal_error`, and only the
 * app's log said why — «exige los scopes […] y este host no cableó una OperationHttpPolicy». The
 * framework names that same condition at BOOT when an app lists the operation in `config/http.php`;
 * letting it escape here turns a sentence somebody can act on into a stack trace (decisions/0289).
 *
 * 501 and not 403: the caller is not being denied, and nothing about them would change the answer. This
 * app has not implemented a way to judge the act at all.
 */
final class GovernedAct
{
    /**
     * Projects the request as `$operation` and answers what the ceremony answers.
     *
     * @param string $noJudge the sentence to return when this app registered no policy for the act
     */
    public static function run(
        object $projector,
        string $operation,
        ServerRequestInterface $request,
        ResponseFactoryInterface $responses,
        string $noJudge,
    ): ResponseInterface {
        $routed = $request->withAttribute(
            RouteResult::ATTRIBUTE,
            RouteResult::matched(new Route(
                path: '/',
                methods: HttpMethod::POST,
                name: $operation,
                handler: null,
            )),
        );

        try {
            /** @var ResponseInterface $answer */
            $answer = $projector->handle($routed);

            return $answer;
        } catch (UnguardedOperationException) {
            $response = $responses->createResponse(501)->withHeader('Content-Type', 'application/json');
            $response->getBody()->write((string) json_encode(
                ['ok' => false, 'error' => $noJudge],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ));

            return $response;
        }
    }
}
