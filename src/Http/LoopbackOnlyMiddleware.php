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

use Milpa\Admin\I18n\Catalog;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The panel's default gate: only requests from the loopback interface get through.
 *
 * A fresh app has no identity wired, and an admin panel with no gate at all is a door left open.
 * This is the posture until the app declares `admin.middleware` — a list of PSR-15 middleware the
 * panel attaches to every one of its routes, where a passkey or scope gate takes this one's place.
 * Fails closed: no remote address means no answer.
 */
final class LoopbackOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Catalog $catalog = new Catalog())
    {
    }

    /** Lets a loopback request through and answers 403 to everything else. */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $address = \is_string($address) ? $address : '';

        if (self::isLoopback($address)) {
            return $handler->handle($request);
        }

        return new Response(
            403,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            $this->catalog->tr('gate.loopback') . "\n",
        );
    }

    /** True for IPv4 127.0.0.0/8 and IPv6 ::1 (also in its IPv4-mapped form). */
    public static function isLoopback(string $address): bool
    {
        if ($address === '::1' || $address === '::ffff:127.0.0.1') {
            return true;
        }

        return str_starts_with($address, '127.') && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
