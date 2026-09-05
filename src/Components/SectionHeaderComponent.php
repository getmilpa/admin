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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The header the host puts above every section — its title and who declared it (wireframe 1a of greenhouse
 * decisions/0203: «Título · declarada por <Plugin>»; decided for every section, the panel's own included,
 * in decisions/0210).
 *
 * The attribution is the catalogue's fact, not the section's: `SectionCatalogue::declaredBy()` names the
 * plugin class whose `adminSections()` returned it, and the shell hands that name here as `declaredBy`. A
 * section never says who declared it — the host does, so a guest cannot borrow another plugin's name; and
 * it cannot repaint this header either, because the {@see ComponentBook} refuses a section that brings its
 * own definition under `admin-section-header` (or any other name the panel registers itself).
 *
 * No actions: a header shows; the section below acts.
 */
final class SectionHeaderComponent implements ComponentDefinitionInterface
{
    public const NAME = 'admin-section-header';
    public const VERSION = '1';

    /** The contract: the section's title as the shell resolved it, and the declaring plugin's class (`''` when unknown). */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: self::VERSION,
            summary: 'The header the admin panel puts above a section: its title, and the plugin that declared it.',
            propsSchema: [
                'title' => ['type' => 'string', 'default' => ''],
                'declaredBy' => ['type' => 'string', 'default' => ''],
            ],
            stateSchema: [
                'title' => ['type' => 'string'],
                'declaredBy' => ['type' => 'string'],
            ],
        );
    }

    /** Mount: the title and the declaring class, as strings — anything else is empty, never invented. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            self::NAME,
            self::VERSION,
            [
                'title' => \is_string($props['title'] ?? null) ? $props['title'] : '',
                'declaredBy' => \is_string($props['declaredBy'] ?? null) ? $props['declaredBy'] : '',
            ],
        );
    }

    /** No action is declared: the header shows, the section below acts. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => \sprintf('«%s» declares no actions: the header shows, the section below acts.', self::NAME)],
        );
    }
}
