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

namespace Milpa\Admin\Stack;

/**
 * The real probe: one TCP connect to `127.0.0.1:<port>` with a 250 ms timeout.
 *
 * A refused or timed-out connection is `false`, never an exception — a service that is down is a
 * state the section shows, not an error the panel raises. It says nothing about WHAT answered:
 * a port that accepts is "up" even if something else took it, which is exactly what the operator
 * needs to know first.
 */
final class TcpProbe implements ReachabilityProbe
{
    public const HOST = '127.0.0.1';
    public const TIMEOUT_SECONDS = 0.25;

    /** True when the port accepts a TCP connection on loopback; the connection is closed at once. */
    public function reachable(int $port): bool
    {
        $errno = 0;
        $error = '';
        $socket = @fsockopen(self::HOST, $port, $errno, $error, self::TIMEOUT_SECONDS);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
