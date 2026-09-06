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

namespace Milpa\Admin\Controllers;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Components\ComponentBook;
use Milpa\Admin\Http\RequestPrincipal;
use Milpa\Admin\Section\BootedPlugins;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Http\LiveEndpoint;
use Milpa\Live\Http\LiveHttpRequest;
use Milpa\Live\Security\ContractInteractionAuthorizer;
use Milpa\Live\ValueObjects\SecurityPrincipal;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The panel's live wire: `POST {route}/live` — one endpoint over the ONE registry the page compiled with,
 * so a component of the panel's own and a component a guest declared take their actions through the same
 * door (greenhouse decisions/0211).
 *
 * The registry is rebuilt from the SAME reading the page used ({@see BootedPlugins}, then
 * {@see ComponentBook::forSections()}): the sections are discovered per request on both surfaces, so an
 * envelope minted while painting a page RESOLVES here, and a `RenderEffect` from a host component can
 * repaint a guest's.
 *
 * **Resolving is not verifying, and the key is the app's.** This endpoint verifies with the PANEL's codec
 * ({@see AdminSettings::signingSecret()} — `admin.secret`, else `live.secret`, else one derived from this
 * install), while a guest's renderer signed with the guest package's own key. An app that declares neither
 * gives the two different derived keys, and a guest's envelope is refused `400 invalid_signature` — loud,
 * per call, never silent. Declaring one house key (`live.secret`) is what makes host and guest sign alike;
 * measured both ways on a fresh app. The residue is named in the README: {@see \Milpa\Admin\Section\DeclaredView}
 * does not receive the panel's codec, so a guest cannot borrow the host's key without the app saying so.
 *
 * **Behind the same door.** The route carries the panel's effective middleware stack — the gate of
 * greenhouse decisions/0204 and 0206, whatever the app declared — because a wire outside the door would
 * be a hole: an unauthenticated caller could act on any mounted component of any section. The endpoint
 * adds no second policy of its own; it names WHO acted (the actor the gate authenticated, with the
 * component scopes) so a component whose state is bound to a principal recognises its owner. Nobody
 * signed in is `null` — the panel invents no identity, exactly as the topbar does not.
 *
 * The page session the CSRF token is bound to comes from the REQUEST BODY: `LiveBoot::issue()` minted it
 * when the page was rendered and the runtime echoes it as `sessionId` on every action. No cookie carries
 * it — a cookie another page set is not this page's session.
 */
final class LiveController
{
    /**
     * @param object $self the admin plugin instance — the one section provider the panel can count on without a kernel
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly object $self,
        private readonly StateTransferCodecInterface $codec,
        private readonly CsrfGuardInterface $csrf,
        private readonly AdminSettings $settings,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
    }

    /** Handle one component interaction: `{action, state, payload, sessionId, csrfToken}` in, re-rendered HTML + a fresh envelope out. */
    public function live(ServerRequestInterface $request): ResponseInterface
    {
        $decoded = json_decode((string) $request->getBody(), true);
        $body = \is_array($decoded) ? $decoded : [];

        try {
            $book = ComponentBook::forSections(
                SectionCatalogue::discover(BootedPlugins::of($this->container, $this->self)),
                $this->codec,
                $this->events,
            );
        } catch (\Throwable $refused) {
            // The same refusal the page turns into its 500 document — said as JSON, because this surface
            // answers a runtime, not a reader.
            return $this->json(500, ['ok' => false, 'error' => 'sections', 'message' => $refused->getMessage()]);
        }

        $endpoint = new LiveEndpoint(
            components: $book->registry(),
            codec: $this->codec,
            authorizer: new ContractInteractionAuthorizer($book->registry()),
            csrf: $this->csrf,
            route: $this->settings->liveUrl(),
            renderers: $book->renderers(),
            dispatcher: $this->events,
        );

        $response = $endpoint->handle(
            new LiveHttpRequest(
                method: $request->getMethod(),
                action: \is_string($body['action'] ?? null) ? $body['action'] : '',
                stateEnvelope: \is_string($body['state'] ?? null) ? $body['state'] : '',
                payload: \is_array($body['payload'] ?? null) ? $body['payload'] : [],
                sessionId: \is_string($body['sessionId'] ?? null) ? $body['sessionId'] : '',
                csrfToken: \is_string($body['csrfToken'] ?? null) && $body['csrfToken'] !== '' ? $body['csrfToken'] : $request->getHeaderLine('X-CSRF-Token'),
            ),
            self::principal($request),
        );

        return $this->json($response->status, $response->body);
    }

    /**
     * Who the gate let in, as the live layer names one: the actor's id with the component scopes, because
     * the authorization already happened at the door. Nobody signed in is null — which the authorizer
     * reads as «no principal to check the state's owner against», not as a denial.
     */
    private static function principal(ServerRequestInterface $request): ?SecurityPrincipal
    {
        $id = RequestPrincipal::of($request);

        return $id === null ? null : new SecurityPrincipal($id, ['milpa:*']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            (string) json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    }
}
