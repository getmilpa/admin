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

namespace Milpa\Admin\Components;

use Milpa\Admin\Data\HouseSource;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * THE SCREEN THE PANEL OPENS ON: what this house is, what it can be asked for, and what it cannot
 * do yet.
 *
 * It exists because the panel used to open on Plugins, and nobody chose that — `order: 10` won a
 * flat sort. A human who had just installed the framework met a table of PHP class names
 * (greenhouse decisions/0264). The class table is not wrong; it is just not the first thing anybody
 * needs, and Plugins is exactly where it belongs.
 *
 * IT SAYS WHAT IS MISSING, and that is the part worth defending. A capability nobody installed, a
 * foundation nobody filled in, an index read from an offline floor — each is reported as the fact it
 * is, with what it costs. The alternative is a screen that renders «—» and teaches nothing about
 * which absence it is looking at.
 *
 * AND IT ADMITS WHEN IT HAS NOTHING TO SAY. An equipped house gets no invented next step: the panel
 * says so and gets out of the way. A screen that always has advice is a screen whose advice is worth
 * nothing.
 */
final class HouseComponent implements ComponentDefinitionInterface
{
    public const NAME = 'admin-house';

    public const SECTION = 'house';

    public function __construct(private readonly HouseSource $source)
    {
    }

    /** What this screen holds: the house's standing, its founding, its capabilities — and no actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '1',
            summary: 'What this house is, what it can be asked for, and what it cannot do yet — with the cost of each absence.',
            stateSchema: [
                'title' => ['type' => 'string'],
                'route' => ['type' => 'string'],
                'gate' => ['type' => 'string'],
                'root' => ['type' => 'string'],
                'foundation' => ['type' => 'array'],
                'packages' => ['type' => 'array'],
                'capabilities' => ['type' => 'array'],
                'plugins' => ['type' => 'int'],
                'routes' => ['type' => 'int'],
            ],
        );
    }

    /**
     * The house as it stands, plus WHO the gate authenticated.
     *
     * The principal reaches every section through its context (greenhouse decisions/0210) and this
     * screen says who it is, because «who is in the house» is one of the three questions it exists
     * to answer — and nobody is an answer too, not a blank.
     */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            componentId: $context->componentId,
            componentName: self::NAME,
            version: '1',
            data: $this->source->snapshot(),
            meta: ['principal' => $context->principal ?? '', 'title' => (string) ($props['title'] ?? '')],
        );
    }

    /**
     * Reports an action rather than running one — this section reads.
     *
     * Every act it NAMES is a governed operation somewhere else: a signed command, a ceremony in a
     * browser. A button here would run it under whatever authority the panel happens to have, which
     * is the one thing a panel must never lend.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => 'this section reads; every act it names is a governed operation elsewhere'],
        );
    }
}
