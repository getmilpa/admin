<?php

/**
 * This file is part of Milpa Admin — the administration panel of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin\Components;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\View\AdminShell;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The identity of this request, as authenticated by the host's gate (greenhouse decisions/0322).
 * Declared scopes are a projection, never an authorization decision. The component has no actions,
 * reads no credential or enrollment store and never substitutes a historical session owner.
 */
final class IdentityComponent implements ComponentDefinitionInterface
{
    public const NAME = 'admin-identity';

    public const SECTION = 'identity';

    public function __construct(private readonly RoutesSource $routes, private readonly AdminSettings $settings)
    {
    }

    /** A read-only view of authenticated request facts and the ceremonies the house actually serves. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '1',
            summary: 'The identity authenticated for this request and its declared scopes, without granting permissions.',
            stateSchema: [
                'principal' => ['type' => ['string', 'null']],
                'scopes' => ['type' => ['array', 'null']],
                'gate' => ['type' => 'string'],
                'ceremonies' => ['type' => 'array'],
            ],
        );
    }

    /** Re-reads the host context on every mount; query parameters and travelling state have no say. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            componentId: $context->componentId,
            componentName: self::NAME,
            version: '1',
            data: [
                'principal' => $context->principal,
                'scopes' => $context->principal === null ? null : ($context->meta[AdminShell::META_SCOPES] ?? null),
                'gate' => $this->settings->gateLabel(),
                'ceremonies' => $this->ceremonies(),
            ],
        );
    }

    /** Refuses mutation, including attempts to change scopes through a signed component envelope. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state, errors: ['action' => 'Identity is read-only.']);
    }

    /** @return array<string, string> Mounted local ceremony URLs, returning to this section. */
    private function ceremonies(): array
    {
        $names = ['passkey.signin.page' => 'signin', 'passkey.enroll.page' => 'enroll'];
        $links = [];
        foreach ($this->routes->snapshot()['routes'] as $route) {
            $kind = $names[$route['name']] ?? null;
            $path = $route['path'];
            if ($kind === null || !\in_array('GET', explode(' ', $route['method']), true)
                || !str_starts_with($path, '/') || str_starts_with($path, '//')
                || strpbrk($path, "\\?#{}\r\n") !== false) {
                continue;
            }
            $links[$kind] = $path . '?next=' . rawurlencode($this->settings->route . '/s/' . self::SECTION);
        }

        return $links;
    }
}
