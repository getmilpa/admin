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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * A guest component that THROWS while mounting — the falsifier for «contained errors» (greenhouse
 * decisions/0211): the panel must paint its failure inside its own node and keep serving the page, never
 * turn a guest's bug into a 500 for the whole panel.
 */
final class BrokenComponent implements ComponentDefinitionInterface
{
    public const NAME = 'lab-broken';

    /** What it throws — asserted verbatim in the failure region. */
    public const BOOM = 'the guest blew up while mounting';

    public static function contract(): ComponentContract
    {
        return new ComponentContract(name: self::NAME, contractVersion: '1');
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        throw new \RuntimeException(self::BOOM);
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult($request->state);
    }
}
