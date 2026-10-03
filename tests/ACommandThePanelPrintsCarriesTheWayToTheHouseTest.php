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

namespace Milpa\Admin\Tests;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Data\PluginsSource;
use Milpa\Admin\HouseCli;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Rendering\AdminHtmlRenderer;
use Milpa\Container\DIContainer;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 A COMMAND THE PANEL PRINTS CARRIES THE WAY TO THE HOUSE — and it asks the house, it does not guess.
 *
 * In the Desktop the house lives in a container: `php bin/coa …` runs only after the person prepends
 * `docker exec -it <container>`. The process that serves the house declares that way in
 * (`MILPA_CLI_PREFIX`) and `milpa/app-runtime` builds every command it hands a person from
 * `Capabilities::cli()` (greenhouse decisions/0559, E5). This package kept its own copies — the
 * *Plugins* command form, the House section's next move, the Identity note, the two refusals that name
 * the terminal — and they still said `php bin/coa` (greenhouse evidence/1093, «siguen sin prefijo»).
 *
 * The panel does not depend on app-runtime, and this suite runs without it. So this class holds the
 * half that needs no house: with nobody to ask, every byte is what it was; and no command to type is
 * left in `src/` that does not go through {@see HouseCli}. What the panel paints when the house does
 * answer is {@see ThePanelAsksTheHouseHowItIsReachedTest}, in processes of its own.
 */
#[CoversClass(HouseCli::class)]
final class ACommandThePanelPrintsCarriesTheWayToTheHouseTest extends TestCase
{
    /** The control: this suite has no app-runtime, so what follows is the panel with nobody to ask. */
    public function testThisSuiteRunsWithoutTheHouse(): void
    {
        self::assertFalse(class_exists(HouseCli::SOURCE), 'the panel names app-runtime by string and its suite runs without it');
    }

    /** With nobody to ask, the CLI is `php bin/coa ` and a text is returned as it was given — not one byte moved. */
    public function testWithNobodyToAskNothingChanges(): void
    {
        self::assertSame('php bin/coa ', HouseCli::cli());

        foreach (Catalog::locales() as $locale) {
            $catalog = new Catalog($locale);
            foreach (['house.next.found.command', 'identity.registration', 'capabilities.command_form'] as $key) {
                self::assertSame($catalog->tr($key), HouseCli::reached($catalog->tr($key)), $locale . ' · ' . $key);
            }
        }

        $html = self::plugins();
        self::assertStringContainsString('<kbd class="mui-kbd">php bin/coa capabilities:enable &lt;package&gt; --sign</kbd>', $html);
        self::assertStringNotContainsString('docker', $html);
    }

    /**
     * And a declared way in is not the PANEL's to read: only the house judges what a prefix may be.
     *
     * The filter — no line break, no `;`, no `$`, 200 characters — lives in app-runtime. A second
     * reader of the variable here would be a second judge, and the one that forgets a character is
     * the one a person copies from.
     */
    public function testThePanelDoesNotReadTheDeclarationItself(): void
    {
        putenv('MILPA_CLI_PREFIX=docker exec -it box');
        try {
            self::assertSame('php bin/coa ', HouseCli::cli());
            self::assertStringNotContainsString('docker', self::plugins());
        } finally {
            putenv('MILPA_CLI_PREFIX');
        }
    }

    /** Whoever answers `cli()` is believed; every command in a text takes the way in, prose around it untouched. */
    public function testEveryCommandInATextTakesTheWayInTheHouseSays(): void
    {
        $house = (new class () {
            public static function cli(): string
            {
                return 'docker exec -it box php bin/coa ';
            }
        })::class;

        self::assertSame('docker exec -it box php bin/coa ', HouseCli::cli($house));
        self::assertSame(
            'run `docker exec -it box php bin/coa serve` (`docker exec -it box php bin/coa identity:invite --sign` mints another)',
            HouseCli::reached('run `php bin/coa serve` (`php bin/coa identity:invite --sign` mints another)', $house),
        );
        self::assertSame('Nothing to type here.', HouseCli::reached('Nothing to type here.', $house));
    }

    /** A house from before it could say (no `cli()`), one that fails, one that answers nothing: the plain command. */
    public function testAHouseThatCannotSayIsNotAnError(): void
    {
        $older = (new class () {})::class;
        $failing = (new class () {
            public static function cli(): string
            {
                throw new \RuntimeException('the house does not boot');
            }
        })::class;
        $silent = (new class () {
            public static function cli(): string
            {
                return '';
            }
        })::class;
        $wrong = (new class () {
            /** @return list<string> */
            public static function cli(): array
            {
                return ['php bin/coa '];
            }
        })::class;

        foreach ([$older, $failing, $silent, $wrong, 'Milpa\\Nobody\\Home'] as $house) {
            self::assertSame('php bin/coa ', HouseCli::cli($house));
            self::assertSame('`php bin/coa list`', HouseCli::reached('`php bin/coa list`', $house));
        }
    }

    /**
     * 🚨 THE GUARD: no command to type is left in `src/` that does not go through {@see HouseCli}.
     *
     * Three shapes, each the way one of these copies was written: a literal in code, a constant two
     * surfaces share, and a catalog value. A literal is refused outright; the two constants are named
     * one by one and every read of them must be wrapped; and every catalog key whose value carries a
     * command must be read on a line that wraps it. A new command added the old way fails here.
     */
    public function testNoCommandToTypeIsBuiltWithoutAskingTheHouse(): void
    {
        $src = \dirname(__DIR__) . '/src';
        $catalog = (string) file_get_contents($src . '/I18n/Catalog.php');

        preg_match_all('/^\s*\'([a-z_.]+)\' => .*php bin\/coa /m', $catalog, $found);
        $keys = array_values(array_unique($found[1]));
        sort($keys);
        self::assertSame(['capabilities.command_form', 'house.next.found.command', 'identity.registration'], $keys, 'the catalog values that carry a command to type');

        $offenders = [];
        foreach (self::phpFiles($src) as $file) {
            $name = substr($file, \strlen($src) + 1);
            if ($name === 'HouseCli.php' || $name === 'I18n/Catalog.php') {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                $at = $name . ':' . ($n + 1) . '  ' . trim($line);
                $wrapped = str_contains($line, 'HouseCli::reached(');
                $declaresTheSentence = str_contains($line, 'public const string NO_JUDGE = ');

                if (str_contains($line, 'php bin/coa ') && !$declaresTheSentence) {
                    $offenders[] = 'a literal command: ' . $at;
                }
                if (str_contains($line, 'self::NO_JUDGE') && !$wrapped) {
                    $offenders[] = 'the refusal, read bare: ' . $at;
                }
                foreach ($keys as $key) {
                    if (str_contains($line, "'" . $key . "'") && !$wrapped) {
                        $offenders[] = 'a catalog command, read bare: ' . $at;
                    }
                }
            }
        }

        self::assertSame([], $offenders, "every command a person is told to type goes through HouseCli:\n" . implode("\n", $offenders));
    }

    private static function plugins(): string
    {
        $state = new StateSnapshot('s1', PluginsComponent::NAME, '1', [
            'registry' => true,
            'plugins' => [],
            'installable' => false,
            'capabilities' => ['available' => [], 'installed' => []],
        ], ['title' => 'Plugins']);
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('way-in-test-secret-0123'), null);
        $renderer = new AdminHtmlRenderer($codec, new Catalog('en'), AdminSettings::fromConfig(null));

        return $renderer->render(
            new PluginsComponent(new PluginsSource(new DIContainer())),
            new RenderRequest(context: new ComponentContext(componentId: $state->componentId), state: $state),
        )->output;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $root): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }
}
