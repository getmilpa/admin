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

namespace Milpa\Admin\Components;

use Milpa\Admin\Data\SettingsSource;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Settings section as a Milpa Component: its state is what the app declared about its panel —
 * the `admin` key of `config/app.php`, key by key, with the source of each value and the gate in effect.
 *
 * Read-only on purpose: writing configuration is a governed operation (`config:set`) and enters through
 * another slice. The browser preferences the renderer paints next to this state (theme, density, a local
 * language override) never reach the server — they are the viewer's, in `localStorage`, and are not state.
 */
final class SettingsComponent implements ComponentDefinitionInterface
{
    public const NAME = 'admin-settings';

    public function __construct(private readonly SettingsSource $source)
    {
    }

    /** The contract: no props, a read-only state, no actions. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '1',
            summary: 'What the app declared about its admin panel, with the source of every value and the gate in effect.',
            stateSchema: [
                'declared' => ['type' => 'boolean'],
                'gate' => ['type' => 'string'],
                'locale' => ['type' => 'string'],
                'rows' => ['type' => 'array'],
                'unresolved' => ['type' => 'array'],
                'snippet' => ['type' => 'string'],
            ],
        );
    }

    /** Mounts with the settings the panel booted with. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            componentId: $context->componentId,
            componentName: self::NAME,
            version: '1',
            data: $this->source->snapshot(),
            meta: ['title' => (string) ($props['title'] ?? '')],
        );
    }

    /** Refuses every action: the section is read-only. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => \sprintf('«%s» is read-only: it declares no actions.', self::NAME)],
        );
    }
}
