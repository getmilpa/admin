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

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Admin\Section\DeclaredView;
use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;

/**
 * A guest that declares a whole VIEW instead of one component (greenhouse decisions/0211): a tree of two
 * live counters — one node repeated, so a shared module proves it is emitted once — plus, when asked, a
 * third root that throws while mounting, to see the panel contain it.
 *
 * It signs with the SAME key the panel does, because the wire is the panel's: `admin.secret`, else
 * `live.secret`, else the derived one ({@see AdminSettings::signingSecret()}). A guest that minted its own
 * key would emit envelopes the host's endpoint could not verify.
 */
#[PluginMetadata(version: '0.1.0', author: 'Test', site: 'https://example.test', name: 'View', type: 'Web')]
final class ViewPlugin implements PluginInterface, AdminSectionProvider
{
    public const SECTION = 'lab-view';

    /** What the view seeds, and what the panel's own seeds must merge with. */
    public const SIGNAL = 'lab.counter';

    private readonly StateTransferCodecInterface $codec;

    public function __construct(private readonly DIContainerInterface $container, private readonly bool $broken = false, ?StateTransferCodecInterface $codec = null)
    {
        $this->codec = $codec ?? new SignedXhtmlStateTransferCodec(
            new XhtmlStateTransferCodec(),
            new HmacStateSigner(AdminSettings::fromConfig(null)->signingSecret()),
            null,
        );
    }

    public function boot(): void
    {
    }

    public function adminSections(): array
    {
        $renderer = new CounterRenderer($this->codec);
        $definitions = [CounterComponent::NAME => new CounterComponent()];
        $renderers = [CounterComponent::NAME => $renderer];
        $markup = '<milpa:' . CounterComponent::NAME . ' id="lab-a"/><milpa:' . CounterComponent::NAME . ' id="lab-b" count="7"/>';

        if ($this->broken) {
            $definitions[BrokenComponent::NAME] = new BrokenComponent();
            $renderers[BrokenComponent::NAME] = new BrokenRenderer();
            $markup .= '<milpa:' . BrokenComponent::NAME . ' id="lab-broken"/>';
        }

        return [
            AdminSection::ofView(
                id: self::SECTION,
                title: 'Lab view',
                view: new DeclaredView(
                    markup: $markup,
                    definitions: $definitions,
                    renderers: $renderers,
                    props: [CounterComponent::NAME => ['count' => 1]],
                    signals: [self::SIGNAL => 0],
                    persist: [self::SIGNAL],
                    computed: ['lab.label' => ['template' => '{lab.counter}']],
                ),
                order: 80,
                group: AdminSection::GROUP_AGENT,
                icon: '◈',
            ),
        ];
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }
}
