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
 * Answers whether something on this host accepts a connection on a port — the one measurement the
 * Stack section makes.
 *
 * A declared service is data; whether it is RUNNING is a fact the panel can only observe. The probe is
 * the seam between the two, so a test can fake the answer and the real one ({@see TcpProbe}) stays a
 * loopback connect with a short timeout — no Docker, no orchestration (greenhouse decisions/0201).
 */
interface ReachabilityProbe
{
    /** True when a TCP connection to the port on loopback succeeds within the probe's timeout. */
    public function reachable(int $port): bool;
}
