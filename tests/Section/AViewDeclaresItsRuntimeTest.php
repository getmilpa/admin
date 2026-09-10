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

namespace Milpa\Admin\Tests\Section;

use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\DeclaredView;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use Milpa\Admin\AdminSettings;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Admin\View\AdminShell;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ClientAssets;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 A VIEW CAN DECLARE FILES NO SINGLE COMPONENT OF IT OWNS, and a shipped screen proved the gap.
 *
 * The contract collected a view's assets by walking the components it RENDERS — every file a surface
 * owns, and nothing else. A guest whose surfaces hang off a SHARED RUNTIME MODULE had nowhere to
 * declare it: not the component, because the module has no surface to paint; not the host, because it
 * cannot know what a guest needs.
 *
 * Measured, not theorised: `milpa/agent-workspace`'s Settings screen shipped as a panel section and
 * rendered perfectly with a DEAD Save button. The click fired no request at all and the console said
 * its guard module was not loaded — the standalone page had emitted that module by hand in its own
 * template (greenhouse decisions/0211, gap found in decisions/0272).
 */
final class AViewDeclaresItsRuntimeTest extends TestCase
{
    /** What the view declares reaches the host's emission, alongside what its components declare. */
    public function testTheHostEmitsWhatTheViewDeclaredAndWhatItsComponentsDid(): void
    {
        $out = $this->render(new ClientAssets(scripts: ['/guest/runtime.js'], styles: ['/guest/runtime.css']));

        self::assertContains('/guest/runtime.js', $out->assets->scripts, 'the view\'s own module');
        self::assertContains('/guest/runtime.css', $out->assets->styles);
    }

    /** A view that declares nothing behaves exactly as before — the field is additive. */
    public function testAViewThatDeclaresNothingIsUnchanged(): void
    {
        $before = $this->render(null);

        self::assertNotContains('/guest/runtime.js', $before->assets->scripts);
    }

    /**
     * THE CONTROL FOR «EMITTED ONCE»: a module the view names AND a component implies appears once.
     *
     * A runtime is idempotent but a double `<script>` is a double execution, and the whole reason a
     * guest may declare a module its components also imply is that saying it twice must be free.
     */
    public function testAModuleNamedTwiceIsEmittedOnce(): void
    {
        $out = $this->render(new ClientAssets(scripts: ['/guest/runtime.js', '/guest/runtime.js']));

        self::assertSame(
            1,
            \count(array_filter($out->assets->scripts, static fn (string $u): bool => $u === '/guest/runtime.js')),
            'the host emits each URL once',
        );
    }

    private function render(?ClientAssets $assets): \Milpa\Admin\View\ShellOutput
    {
        $view = new DeclaredView(
            markup: '<milpa:' . EchoComponent::NAME . ' id="guest-root"/>',
            definitions: [EchoComponent::NAME => new EchoComponent()],
            renderers: [EchoComponent::NAME => new EchoRenderer()],
            assets: $assets,
        );
        $section = AdminSection::ofView(id: 'guest', title: 'Guest', view: $view);
        $catalogue = SectionCatalogue::discover([self::provider([$section])]);

        $shell = new AdminShell(
            AdminSettings::fromConfig(null),
            new Catalog(),
            new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null),
        );

        return $shell->compose($catalogue, $section);
    }

    /** @param list<AdminSection> $sections */
    private static function provider(array $sections): AdminSectionProvider
    {
        return new class ($sections) implements AdminSectionProvider {
            /** @param list<AdminSection> $sections */
            public function __construct(private readonly array $sections)
            {
            }

            /** @return list<AdminSection> */
            public function adminSections(): array
            {
                return $this->sections;
            }
        };
    }
}
