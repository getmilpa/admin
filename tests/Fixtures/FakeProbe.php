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

use Milpa\Admin\Stack\ReachabilityProbe;

/**
 * A probe that answers from a list of open ports and records what it was asked.
 */
final class FakeProbe implements ReachabilityProbe
{
    /** @var list<int> */
    public array $probed = [];

    /** @param list<int> $open */
    public function __construct(private readonly array $open = [])
    {
    }

    public function reachable(int $port): bool
    {
        $this->probed[] = $port;

        return \in_array($port, $this->open, true);
    }
}
