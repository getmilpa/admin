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

use Milpa\Runtime\Stack\StackProviderInterface;

/**
 * A bare stack provider that returns whatever it was given — including, on purpose, entries that are not
 * declarations, so the source's verification of the foreign promise can be exercised.
 */
final class DeclaringProvider implements StackProviderInterface
{
    /** @param list<mixed> $services */
    public function __construct(private readonly array $services)
    {
    }

    public function services(): array
    {
        return $this->services;
    }
}
