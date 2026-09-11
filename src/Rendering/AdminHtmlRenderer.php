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

namespace Milpa\Admin\Rendering;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Components\DevToolsComponent;
use Milpa\Admin\Components\HouseComponent;
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Components\RoutesComponent;
use Milpa\Admin\Components\SettingsComponent;
use Milpa\Admin\Components\StackComponent;
use Milpa\Admin\Controllers\AdminController;
use Milpa\Admin\Data\DevToolsSource;
use Milpa\Runtime\Stack\StackReader;
use Milpa\Admin\I18n\Catalog;
use Milpa\Runtime\Stack\ResolvedEnv;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Paints the panel's own components — `admin-plugins`, `admin-routes`, `admin-settings`, `admin-stack` and
 * `admin-devtools` — as HTML on the `mui-*` design classes, and closes each with its signed state envelope
 * like every Milpa component.
 *
 * Every string a human reads comes from the {@see Catalog} — the one the request's {@see ComponentContext}
 * names by locale, else the one the renderer booted with; every value from the state is escaped; the
 * links it emits (the compose file, a session's timeline, the way back) are built from the declared
 * {@see AdminSettings}, and carry `?lang=` when the request overrode the locale, so a drill-down keeps
 * answering in the language it was opened in.
 *
 * The Dev tools envelope is the exception to «the state as mounted»: what travels is
 * {@see DevToolsComponent::envelope()} — `{view, session}` — and a request that carries that envelope
 * re-mounts through {@see DevToolsComponent::propsOf()} instead of painting an envelope that holds no
 * ledger.
 */
final class AdminHtmlRenderer implements ComponentRendererInterface
{
    /** The locale the request overrode the panel's with — carried as `?lang=` on every link this renderer emits; null when it did not. */
    private ?string $lang = null;

    public function __construct(
        private readonly StateTransferCodecInterface $codec,
        private Catalog $catalog,
        private readonly AdminSettings $settings,
    ) {
    }

    /** HTML only — the TUI projection is a later slice. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * Mounts (unless the request carries state) and paints the component the contract names. A Dev tools
     * request that carries state carries the travelling `{view, session}` and is re-mounted from it.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $state = match (true) {
            $request->state === null => $component->mount($request->props, $request->context),
            $component instanceof DevToolsComponent && DevToolsComponent::travels($request->state) => $component->mount(DevToolsComponent::propsOf($request->state), $request->context),
            default => $request->state,
        };
        $name = $component::contract()->name;
        $painter = $this->forLocale($request->context->locale);

        $body = match ($name) {
            HouseComponent::NAME => $painter->house($state),
            PluginsComponent::NAME => $painter->plugins($state),
            RoutesComponent::NAME => $painter->routes($state),
            SettingsComponent::NAME => $painter->settings($state),
            StackComponent::NAME => $painter->stack($state),
            DevToolsComponent::NAME => $painter->devtools($state),
            default => throw new \InvalidArgumentException(\sprintf(
                '%s renders %s, %s, %s, %s, %s and %s, not «%s».',
                self::class,
                HouseComponent::NAME,
                PluginsComponent::NAME,
                RoutesComponent::NAME,
                SettingsComponent::NAME,
                StackComponent::NAME,
                DevToolsComponent::NAME,
                $name,
            )),
        };

        $travels = $component instanceof DevToolsComponent ? DevToolsComponent::envelope($state) : $state;
        $html = \sprintf(
            '<section %s>%s</section>%s',
            Html::attrs([
                'class' => 'admin-section admin-section--' . $name,
                'id' => $state->componentId,
                'data-milpa-component-id' => $state->componentId,
            ]),
            $body,
            $this->envelope($travels),
        );

        return new RenderResult(output: $html, state: $travels, format: RenderTarget::HTML);
    }

    /**
     * This renderer answering in the context's locale when the catalog carries it — itself otherwise. A
     * locale that differs from the one the renderer booted with is the request's `?lang=` override, and
     * the clone remembers it so every link it emits carries it on.
     */
    private function forLocale(?string $locale): self
    {
        if ($locale === null || $locale === $this->catalog->locale() || !\in_array($locale, Catalog::locales(), true)) {
            return $this;
        }
        $painter = clone $this;
        $painter->catalog = new Catalog($locale);
        $painter->lang = $locale;

        return $painter;
    }

    /**
     * THE SCREEN THE PANEL OPENS ON — what this house is, what it can be asked for, what it cannot
     * do yet, and one next move derived from a measured absence.
     *
     * Every absence is reported as the fact it is, WITH ITS COST: an empty foundation is not «—», it
     * is a house with no subject to judge a request against. And when nothing is missing the screen
     * says so and stops — advice that is always available is advice worth nothing
     * (greenhouse decisions/0264).
     */
    private function house(StateSnapshot $state): string
    {
        $data = $state->data;
        $foundation = \is_array($data['foundation'] ?? null) ? $data['foundation'] : [];
        $capabilities = \is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [];
        $installed = \is_array($capabilities['installed'] ?? null) ? array_values(array_filter($capabilities['installed'], 'is_array')) : [];
        $available = \is_array($capabilities['available'] ?? null) ? array_values(array_filter($capabilities['available'], 'is_array')) : [];
        $principal = (string) ($state->meta['principal'] ?? '');
        $gate = (string) ($data['gate'] ?? '');
        $root = (string) ($data['root'] ?? '');

        $out = ['<h2 class="mui-h2">' . Html::escape($this->catalog->tr('house.heading')) . '</h2>'];
        $out[] = '<p class="admin-house__doctrine">' . Html::escape($this->catalog->tr('house.doctrine')) . '</p>';

        // ── WHO IS IN IT ────────────────────────────────────────────────────────────────────────
        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('house.who')) . '</h3>';
        $out[] = $principal === ''
            ? $this->notice($this->catalog->tr('house.who.nobody', $gate), 'warning')
            : '<p class="admin-house__line">' . Html::escape($this->catalog->tr('house.who.signed', $principal))
                . ' — ' . Html::escape($this->catalog->tr('house.who.gate', $gate)) . '</p>';

        // ── FOUNDED TO ──────────────────────────────────────────────────────────────────────────
        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('house.founded')) . '</h3>';
        $out[] = $this->houseFoundation($foundation);

        // ── WHAT THIS HOUSE HAS CHANGED SINCE IT WAS BORN ───────────────────────────────────────
        //
        // Right after «founded to», because it answers the same question one step later: not just which
        // framework this house came from, but how far it has walked from it. Two of the update's three
        // points; the third — what the NEW skeleton ships — is a verb, not something a render asks for
        // (greenhouse decisions/0293).
        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('house.divergence')) . '</h3>';
        $out[] = $this->houseDivergence(
            \is_array($data['divergence'] ?? null) ? $data['divergence'] : null,
            \is_array($data['divergenceRows'] ?? null) ? array_values(array_filter($data['divergenceRows'], '\\is_array')) : [],
        );

        // ── WHAT A NEWER SKELETON WOULD DO ──────────────────────────────────────────────────────
        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('house.update')) . '</h3>';
        $out[] = $this->houseUpdate(
            \is_array($data['reconciliation'] ?? null) ? $data['reconciliation'] : null,
            \is_array($data['divergence'] ?? null),
        );

        // ── WHAT IT CAN BE ASKED FOR ────────────────────────────────────────────────────────────
        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('house.can')) . '</h3>';
        if ($installed === []) {
            $out[] = $this->notice($this->catalog->tr('house.can.none'), 'danger');
        } else {
            $out[] = '<p class="admin-house__hint">' . Html::escape($this->catalog->tr('house.can.count', (string) \count($installed))) . '</p>';
            $out[] = '<ul class="admin-house__caps">' . implode('', array_map($this->houseCapability(...), $installed)) . '</ul>';
        }

        // ── WHAT IT CANNOT DO YET ───────────────────────────────────────────────────────────────
        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('house.cannot')) . '</h3>';
        if ($available === []) {
            $out[] = '<p class="admin-house__hint">' . Html::escape($this->catalog->tr('house.cannot.none')) . '</p>';
        } else {
            $out[] = '<p class="admin-house__hint">' . Html::escape($this->catalog->tr('house.cannot.hint')) . '</p>';
            $out[] = '<ul class="admin-house__caps">' . implode('', array_map($this->houseOffer(...), $available)) . '</ul>';
        }
        $source = (string) ($capabilities['source'] ?? '');
        if ($source !== '') {
            $out[] = '<p class="admin-house__aside">' . Html::escape($this->catalog->tr('house.cannot.index', $source)) . '</p>';
        }

        // ── THE NEXT MOVE ───────────────────────────────────────────────────────────────────────
        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('house.next')) . '</h3>';
        [$says, $command] = $this->houseNextMove($foundation, $installed, $source);
        $out[] = $this->notice($says, 'info');
        // The command on its own line, as a `<code>`, exactly as the capability rows do it. A next
        // move that is a governed operation says the operation — never «edit this file»
        // (greenhouse decisions/0266).
        if ($command !== '') {
            $out[] = '<p><code class="admin-house__command">' . Html::escape($command) . '</code></p>';
        }

        // ── STANDING ────────────────────────────────────────────────────────────────────────────
        // Last, and quietly: it is the only block a person does not need in order to act.
        $packages = \is_array($data['packages'] ?? null) ? $data['packages'] : [];
        $standing = [
            $this->catalog->tr('house.standing.route', (string) ($data['route'] ?? '')),
            $this->catalog->tr('house.standing.surface', (string) ($data['routes'] ?? 0), (string) ($data['plugins'] ?? 0)),
            $this->catalog->tr('house.standing.packages', (string) ($packages['count'] ?? 0)),
            $root === '' ? $this->catalog->tr('house.standing.rootless') : $this->catalog->tr('house.standing.root', $root),
        ];
        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('house.standing')) . '</h3>';
        $out[] = '<ul class="admin-house__standing"><li>'
            . implode('</li><li>', array_map(Html::escape(...), $standing))
            . '</li></ul>';

        // AND THE ROWS THEMSELVES, which this block used to read and throw away.
        //
        // The source returned every package with its resolved version and only the COUNT was painted,
        // so «17 packages» was the whole answer to «which ones, at which versions» — the question a
        // person actually asks before reporting a bug or reproducing one. The sidebar's footer names
        // the two that identify the app and links HERE for the rest, so this is where the rest has to
        // be (greenhouse decisions/0269).
        $rows = \is_array($packages['rows'] ?? null) ? $packages['rows'] : [];
        if ($rows !== []) {
            $items = '';
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $items .= '<li><code>' . Html::escape((string) ($row['name'] ?? '')) . '</code>'
                    . '<span class="admin-house__version">' . Html::escape((string) ($row['version'] ?? '')) . '</span></li>';
            }
            $out[] = '<ul class="admin-house__packages">' . $items . '</ul>';
        }

        return implode("\n", $out);
    }

    /**
     * The update: the verb when nobody has pressed it, the answer when somebody has.
     *
     * 🚨 «NOBODY HAS ASKED» IS NOT «YOU ARE UP TO DATE». An empty reconciliation rendered for a house
     * that never checked would print the second while meaning the first — the reassuring one, again.
     * So the button stands alone until a check has run, and the table only exists afterwards.
     *
     * A house with no birth record gets no button at all: there is nothing to compare against, and the
     * divergence notice above already says why (greenhouse decisions/0294).
     *
     * @param array{latest: string, at: string, summary: array<string, int>, rows: list<array{path: string, status: string}>}|null $result
     */
    private function houseUpdate(?array $result, bool $comparable): string
    {
        if (!$comparable) {
            return $this->notice($this->catalog->tr('house.update.incomparable'));
        }

        $button = '<p><button type="button" class="mui-btn mui-btn--sm admin-fwcheck">'
            . Html::escape($this->catalog->tr('house.update.check')) . '</button>'
            . '<span class="admin-fwcheck-said" hidden></span></p>' . $this->frameworkChecker();

        if ($result === null) {
            return '<p class="admin-house__hint">' . Html::escape($this->catalog->tr('house.update.unasked')) . '</p>' . $button;
        }

        $summary = $result['summary'];
        $out = '<p class="admin-house__hint">' . Html::escape($this->catalog->tr(
            'house.update.found',
            $result['latest'],
            (string) ($summary['actionable'] ?? 0),
        )) . '</p>' . $button;

        // Settled and kept are counted, never listed: a person is looking for what needs a decision,
        // and rows that need none are the padding this panel keeps removing.
        $rows = [];
        foreach ($result['rows'] as $row) {
            if (\in_array($row['status'], ['settled', 'kept'], true)) {
                continue;
            }
            $rows[] = '<tr><td><code class="admin-house__path">' . Html::escape($row['path']) . '</code></td>'
                . '<td><span class="mui-badge' . ($row['status'] === 'conflicted' ? ' mui-badge--warning' : '') . '">'
                . Html::escape($this->catalog->tr('house.update.' . $row['status'])) . '</span></td>'
                . '<td class="admin-house__what">' . Html::escape($this->catalog->tr('house.update.' . $row['status'] . '.what')) . '</td></tr>';
        }

        if ($rows === []) {
            return $out . '<p class="admin-house__hint">' . Html::escape($this->catalog->tr('house.update.nothing')) . '</p>';
        }

        // THE APPLY BUTTON IS OFFERED ONLY WHERE THERE IS SOMETHING SAFE TO TAKE. `offered` and `added`
        // are the only two the operation will write; with none of them the act would refuse, and a
        // button whose only outcome is a refusal is the shape this panel keeps removing
        // (greenhouse decisions/0289, 0297).
        $takeable = 0;
        foreach ($result['rows'] as $row) {
            if (\in_array($row['status'], ['offered', 'added'], true)) {
                ++$takeable;
            }
        }

        $apply = $takeable === 0
            ? '<p class="admin-house__hint">' . Html::escape($this->catalog->tr('house.update.nothing_safe')) . '</p>'
            : '<p>' . Html::escape($this->catalog->tr('house.update.take', (string) $takeable)) . '</p>'
                . '<p><button type="button" class="mui-btn mui-btn--sm mui-btn--primary admin-fwapply">'
                . Html::escape($this->catalog->tr('house.update.apply')) . '</button>'
                . '<span class="admin-fwapply-said" hidden></span></p>'
                . $this->frameworkApplier();

        return $out . $this->table(['col.file', 'col.state', 'col.what'], $rows) . $apply;
    }

    /**
     * The divergence: the counts first, then only the files that are not untouched.
     *
     * Untouched files are COUNTED and not listed. Fifteen rows saying «untouched» is the padding this
     * panel keeps removing; the two or three that diverged are what a person is looking for, and
     * burying them in identical rows is how a screen stops being read (greenhouse decisions/0250).
     *
     * @param array{born: string, at: string, untouched: int, customized: int, deleted: int}|null $summary
     * @param list<array<string, mixed>>                                                          $rows
     */
    private function houseDivergence(?array $summary, array $rows): string
    {
        if ($summary === null) {
            return $this->notice($this->catalog->tr('house.divergence.unknown'));
        }

        $out = '<p class="admin-house__hint">' . Html::escape($this->catalog->tr(
            'house.divergence.count',
            $summary['born'],
            (string) $summary['customized'],
            (string) $summary['deleted'],
            (string) $summary['untouched'],
        )) . '</p>';

        $diverged = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['status'] ?? '') !== 'untouched',
        ));
        if ($diverged === []) {
            return $out . '<p class="admin-house__hint">' . Html::escape($this->catalog->tr('house.divergence.none')) . '</p>';
        }

        // `table()` takes RENDERED rows, not cells — one `<tr>` per entry. Building an array of cells
        // here type-checked as a list of arrays and would have silently `implode`d «Array» into the
        // markup; phpstan named it at the argument.
        $rows = [];
        foreach ($diverged as $row) {
            $status = \is_string($row['status'] ?? null) ? $row['status'] : '';
            $rows[] = '<tr><td><code class="admin-house__path">'
                . Html::escape(\is_string($row['path'] ?? null) ? $row['path'] : '') . '</code></td>'
                . '<td><span class="mui-badge' . ($status === 'deleted' ? ' mui-badge--warning' : '') . '">'
                . Html::escape($this->catalog->tr('house.divergence.' . $status)) . '</span></td></tr>';
        }

        return $out . $this->table(['col.file', 'col.state'], $rows);
    }

    /**
     * What this house was founded to do — or which of the two absences it is in.
     *
     * A missing file and a file of nulls are different facts and get different sentences: one says
     * nobody founded this app, the other says nobody told it what it is for. Rendering «—» for both
     * teaches nothing about which one you are looking at.
     *
     * @param array<string, mixed> $foundation
     */
    private function houseFoundation(array $foundation): string
    {
        if (($foundation['declared'] ?? false) !== true) {
            return $this->notice($this->catalog->tr('house.founded.absent'), 'warning');
        }
        $domain = \is_string($foundation['domain'] ?? null) ? (string) $foundation['domain'] : '';
        if ($domain === '') {
            return $this->notice($this->catalog->tr('house.founded.blank'), 'warning');
        }

        $lines = ['<p class="admin-house__domain">' . Html::escape($domain) . '</p>'];
        if (\is_string($foundation['objective'] ?? null)) {
            $lines[] = '<p class="admin-house__line">' . Html::escape($this->catalog->tr('house.founded.objective', (string) $foundation['objective'])) . '</p>';
        }
        $asides = [];
        $boundaries = (int) ($foundation['boundaries'] ?? 0);
        $asides[] = $boundaries === 0
            ? $this->catalog->tr('house.founded.no_boundaries')
            : $this->catalog->tr('house.founded.boundaries', (string) $boundaries);
        if (\is_string($foundation['founded_at'] ?? null)) {
            $asides[] = $this->catalog->tr('house.founded.since', (string) $foundation['founded_at']);
        }
        foreach (\is_array($foundation['authorities'] ?? null) ? $foundation['authorities'] : [] as $what => $who) {
            if (\is_string($what) && \is_string($who)) {
                $asides[] = $this->catalog->tr('house.founded.authority', str_replace('_', ' ', $what), $who);
            }
        }
        $lines[] = '<p class="admin-house__aside">' . implode(' · ', array_map(Html::escape(...), $asides)) . '</p>';

        return implode("\n", $lines);
    }

    /** One installed capability, led by what it lets somebody ASK FOR rather than by its vendor name. */
    private function houseCapability(mixed $capability): string
    {
        $row = \is_array($capability) ? $capability : [];
        $unlocks = \is_array($row['unlocks'] ?? null) ? array_values(array_filter($row['unlocks'], 'is_string')) : [];

        return '<li class="admin-house__cap"><div class="admin-house__cap-title">'
            . Html::escape((string) ($row['title'] ?? ($row['id'] ?? '')))
            . '</div><div class="admin-house__aside">'
            . ($unlocks === []
                ? Html::escape($this->catalog->tr('house.can.silent'))
                : Html::escape($this->catalog->tr('house.can.unlocks')) . ' ' . $this->codes($unlocks))
            . '</div></li>';
    }

    /** One capability on offer, with the exact signed command that installs it — never a button. */
    private function houseOffer(mixed $capability): string
    {
        $row = \is_array($capability) ? $capability : [];
        $command = \is_string($row['command'] ?? null) ? (string) $row['command'] : '';

        return '<li class="admin-house__cap"><div class="admin-house__cap-title">'
            . Html::escape((string) ($row['title'] ?? ($row['id'] ?? '')))
            . '</div>'
            . ($command === '' ? '' : '<div><code class="admin-house__command">' . Html::escape($command) . '</code></div>')
            . '</li>';
    }

    /**
     * ONE next move, derived from a measured absence in the order the absences bite.
     *
     * Founding first: everything that judges a request reads the domain, so a house without one
     * cannot hold an agent to anything. Then the index, because an offline floor makes the offer
     * list a guess. Then the agent, which is what the panel exists to prepare for. And when none of
     * those is missing the screen says it has nothing to tell you — because it does not.
     *
     * @param array<string, mixed>       $foundation
     * @param list<array<string, mixed>> $installed
     *
     * @return array{0: string, 1: string} what it says, and the command that does it — '' when there is none
     */
    private function houseNextMove(array $foundation, array $installed, string $source): array
    {
        if (($foundation['declared'] ?? false) !== true || !\is_string($foundation['domain'] ?? null)) {
            return [$this->catalog->tr('house.next.found'), $this->catalog->tr('house.next.found.command')];
        }
        if (str_contains($source, 'offline floor')) {
            return [$this->catalog->tr('house.next.refresh'), 'php bin/coa capabilities:refresh'];
        }
        $ids = array_map(static fn (array $row): string => \is_string($row['id'] ?? null) ? $row['id'] : '', $installed);
        if (!\in_array('agent', $ids, true)) {
            return [$this->catalog->tr('house.next.agent'), 'php bin/coa capabilities:enable milpa/agent --sign'];
        }

        // AND NOTHING TO RUN. The equipped case carries no command on purpose: a screen that always
        // has one is a screen whose commands are noise.
        return [$this->catalog->tr('house.next.equipped'), ''];
    }

    /**
     * The Settings section: the viewer's panel preferences (browser-local, `[data-pref]` controls the page's
     * delegated script stores and applies), then the read-only configuration table — key, value, source —
     * with the empty state's snippet when the app declared nothing (worded «entirely on defaults» only when
     * every source IS a default; under the default line, the instruction and the same key whole with the
     * passkey gate as the alternative), the
     * notice that names the passkey gate when that is the gate in effect, and the danger notice when the
     * declared gate cannot be carried: one that names each defective entry, or one that names what was
     * received when it was not a list at all.
     */
    private function settings(StateSnapshot $state): string
    {
        $data = $state->data;
        $declared = ($data['declared'] ?? false) === true;
        $malformed = ($data['malformed'] ?? false) === true;
        $gate = (string) ($data['gate'] ?? '');
        $unresolved = \is_array($data['unresolved'] ?? null) ? array_values(array_filter($data['unresolved'], 'is_string')) : [];
        $rows = \is_array($data['rows'] ?? null) ? array_values(array_filter($data['rows'], 'is_array')) : [];

        $out = ['<h2 class="mui-h2">' . Html::escape($this->catalog->tr('settings.heading')) . '</h2>'];
        $out[] = $this->preferences((string) ($data['locale'] ?? AdminSettings::DEFAULT_LOCALE));

        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('settings.config')) . '</h3>';
        $out[] = '<p class="admin-settings__hint">' . Html::escape($this->catalog->tr('settings.config.hint')) . '</p>';
        if (!$declared) {
            $out[] = $this->notice($this->catalog->tr(self::allDefault($rows) ? 'settings.empty' : 'settings.empty.partial'));
            $out[] = '<pre class="admin-snippet"><code>' . Html::escape((string) ($data['snippet'] ?? '')) . $this->passkeySnippet($data) . '</code></pre>';
        }
        if ($gate === AdminSettings::GATE_PASSKEY) {
            $out[] = $this->notice($this->catalog->tr('gate.passkey'));
        }
        if ($unresolved !== []) {
            $out[] = $this->notice(
                $malformed
                    ? $this->catalog->tr('settings.malformed', $this->join($unresolved))
                    : $this->catalog->tr('settings.unresolved', $this->join(array_map(static fn (string $name): string => '«' . $name . '»', $unresolved))),
                'danger',
            );
        }

        $cells = [];
        foreach ($rows as $row) {
            $cells[] = $this->settingRow($row, $unresolved, $gate);
        }
        $out[] = $this->table(['col.key', 'col.value', 'col.source'], $cells);

        return implode("\n", $out);
    }

    /**
     * The snippet's alternative — the instruction, a comment in the catalog's language («replace the
     * middleware entry with»), then the code the source gives on its own line: the whole `admin` key with
     * the passkey gate, pasteable as-is, never a fragment inside a comment — or nothing when the state
     * carries no such line.
     *
     * @param array<string, mixed> $data
     */
    private function passkeySnippet(array $data): string
    {
        $line = $data['passkeySnippet'] ?? null;
        if (!\is_string($line) || $line === '') {
            return '';
        }

        return "\n// " . Html::escape($this->catalog->tr('settings.snippet.passkey')) . "\n" . Html::escape($line);
    }

    /**
     * True when every row's source is `default` — the wording «entirely on defaults» is earned, not assumed.
     *
     * @param list<array<mixed>> $rows
     */
    private static function allDefault(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['source'] ?? AdminSettings::SOURCE_DEFAULT) !== AdminSettings::SOURCE_DEFAULT) {
                return false;
            }
        }

        return true;
    }

    /**
     * One configuration row. The secret's value is only where it came from, behind the mask glyph. A
     * declared middleware list with a defective entry wears the danger badge on the value; the one that
     * is app-runtime's passkey gate wears the `passkey` badge — the panel names it, that is all; a key the
     * panel rejected shows the EFFECTIVE value, what the app declared next to it, and the danger badge
     * on the source — never `default` for something the app did write.
     *
     * @param array<mixed> $row
     * @param list<string> $unresolved
     * @param string       $gate       the gate as the panel names it — `passkey` badges the middleware row
     */
    private function settingRow(array $row, array $unresolved, string $gate): string
    {
        $key = (string) ($row['key'] ?? '');
        $value = (string) ($row['value'] ?? '');
        $source = match ($row['source'] ?? '') {
            AdminSettings::SOURCE_CONFIG => AdminSettings::SOURCE_CONFIG,
            AdminSettings::SOURCE_REJECTED => AdminSettings::SOURCE_REJECTED,
            default => AdminSettings::SOURCE_DEFAULT,
        };
        $rejected = $source === AdminSettings::SOURCE_REJECTED;
        $declaredAs = \is_string($row['declared'] ?? null) ? $row['declared'] : null;
        $broken = $key === 'middleware' && $unresolved !== [] && !$rejected;
        $passkey = $key === 'middleware' && $gate === AdminSettings::GATE_PASSKEY && !$broken && !$rejected;

        $valueHtml = match ($key) {
            'secret' => '<span class="admin-settings__secret" aria-hidden="true">' . Html::escape($this->catalog->tr('settings.secret.mask')) . '</span>'
                . Html::escape($this->catalog->tr(self::secretKey($value))),
            default => '<code>' . Html::escape($value) . '</code>'
                . ($broken ? ' <span class="mui-badge mui-badge--danger">' . Html::escape($this->catalog->tr('settings.unresolved.badge')) . '</span>' : '')
                . ($passkey ? ' <span class="mui-badge mui-badge--success">' . Html::escape($this->catalog->tr('gate.kind.passkey')) . '</span>' : '')
                . ($rejected && $declaredAs !== null ? ' <span class="admin-settings__declared">' . Html::escape($this->catalog->tr('settings.declared_as', $declaredAs)) . '</span>' : ''),
        };
        $badge = match ($source) {
            AdminSettings::SOURCE_CONFIG => ' mui-badge--accent',
            AdminSettings::SOURCE_REJECTED => ' mui-badge--danger',
            default => '',
        };
        $sourceHtml = '<span class="mui-badge' . $badge . '">' . Html::escape($this->catalog->tr('settings.source.' . $source)) . '</span>';
        $rowClass = match (true) {
            $broken => ' class="admin-settings__row--unresolved"',
            $rejected => ' class="admin-settings__row--rejected"',
            default => '',
        };

        return '<tr' . $rowClass . '>'
            . '<td><code>' . Html::escape($key) . '</code></td>'
            . '<td>' . $valueHtml . '</td>'
            . '<td>' . $sourceHtml . '</td>'
            . '</tr>';
    }

    /** The catalog key for a secret's provenance token — never the secret, which never reaches this renderer. */
    private static function secretKey(string $source): string
    {
        return match ($source) {
            AdminSettings::SECRET_ADMIN => 'settings.secret.admin',
            AdminSettings::SECRET_LIVE => 'settings.secret.live',
            default => 'settings.secret.derived',
        };
    }

    /**
     * The «Panel preferences» panel (a `mui-card` with its header and body, {@see self::panelHeader()}):
     * theme and density (this browser only, applied in place) and the language override (sent as `?lang=`
     * with each request, stored only in this browser) — plain controls tagged `data-pref`, no state of
     * their own; the page's delegated script owns them.
     */
    private function preferences(string $serverLocale): string
    {
        $languages = ['server' => $this->catalog->tr('settings.lang.server', $serverLocale)];
        foreach (Catalog::locales() as $code) {
            $languages[$code] = $code;
        }

        return '<article class="mui-card admin-panel admin-settings__prefs">'
            . self::panelHeader(Html::escape($this->catalog->tr('settings.prefs')))
            . '<div class="mui-card__body admin-panel__body">'
            . '<p class="admin-settings__hint">' . Html::escape($this->catalog->tr('settings.prefs.hint')) . '</p>'
            . '<form class="admin-prefs" data-prefs="">'
            . $this->select('theme', 'settings.pref.theme', [
                'dark' => $this->catalog->tr('settings.theme.dark'),
                'light' => $this->catalog->tr('settings.theme.light'),
                'system' => $this->catalog->tr('settings.theme.system'),
            ])
            . $this->select('density', 'settings.pref.density', [
                'comfortable' => $this->catalog->tr('settings.density.comfortable'),
                'compact' => $this->catalog->tr('settings.density.compact'),
            ])
            . $this->select('lang', 'settings.pref.lang', $languages, $this->catalog->tr('settings.pref.lang.hint'))
            . '</form>'
            . '</div>'
            . '</article>';
    }

    /**
     * One preference: a `mui-field` — label, the select, the hint when there is one.
     *
     * @param array<string, string> $options value → label
     */
    private function select(string $pref, string $labelKey, array $options, string $hint = ''): string
    {
        $html = '<label class="mui-field admin-prefs__field" for="admin-pref-' . $pref . '"><span class="mui-field__label">' . Html::escape($this->catalog->tr($labelKey)) . '</span>'
            . '<select class="mui-input mui-input--sm" data-pref="' . $pref . '" id="admin-pref-' . $pref . '">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . Html::escape($value) . '">' . Html::escape($label) . '</option>';
        }
        $html .= '</select>';
        if ($hint !== '') {
            $html .= '<small class="mui-field__hint">' . Html::escape($hint) . '</small>';
        }

        return $html . '</label>';
    }

    /**
     * The header of a panel — a `mui-card__header` carrying the title as the card's own `mui-card__title`
     * (`admin-panel__title` lets a badge or a probe sit beside it); the page's stylesheet separates it from
     * the `mui-card__body` that follows.
     *
     * @param string $titleHtml the title, already escaped — text, or text with its badge and note
     */
    private static function panelHeader(string $titleHtml): string
    {
        return '<header class="mui-card__header admin-panel__header"><h3 class="mui-card__title admin-panel__title">' . $titleHtml . '</h3></header>';
    }

    private function plugins(StateSnapshot $state): string
    {
        $data = $state->data;
        $rows = \is_array($data['plugins'] ?? null) ? $data['plugins'] : [];
        $out = ['<h2 class="mui-h2">' . Html::escape($this->catalog->tr('plugins.heading')) . '</h2>'];

        if (($data['registry'] ?? false) !== true) {
            $out[] = $this->notice($this->catalog->tr('plugins.no_registry'));
        }

        if ($rows === []) {
            $out[] = $this->notice($this->catalog->tr('plugins.empty'));
        } else {
            $cells = [];
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $enabled = ($row['enabled'] ?? false) === true;
                $cells[] = '<tr>'
                    . '<td>' . Html::escape((string) ($row['name'] ?? '')) . '</td>'
                    . '<td>' . Html::escape((string) ($row['version'] ?? '')) . '</td>'
                    . '<td>' . Html::escape((string) ($row['type'] ?? '')) . '</td>'
                    . '<td><span class="mui-badge ' . ($enabled ? 'mui-badge--success' : 'mui-badge--warning') . '">'
                        . Html::escape($this->catalog->tr($enabled ? 'on' : 'off')) . '</span></td>'
                    . '<td>' . Html::escape((string) ($row['source'] ?? '')) . '</td>'
                    . '<td><code>' . Html::escape((string) ($row['class'] ?? $this->catalog->tr('none'))) . '</code></td>'
                    . '</tr>';
            }
            $out[] = $this->table(['col.name', 'col.version', 'col.type', 'col.enabled', 'col.source', 'col.class'], $cells);
        }

        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('plugins.capabilities')) . '</h3>';
        $capabilities = $data['capabilities'] ?? null;
        if (!\is_array($capabilities)) {
            $out[] = $this->notice($this->catalog->tr('plugins.no_capabilities'));
        } else {
            $available = \is_array($capabilities['available'] ?? null) ? array_values(array_filter($capabilities['available'], '\\is_array')) : [];
            $installed = \is_array($capabilities['installed'] ?? null) ? array_values(array_filter($capabilities['installed'], '\\is_array')) : [];
            // WHAT INSTALLING DOES, SAID ONCE, AS A FILE. Twelve rows repeating a consequence is
            // noise; naming the line that changes in `git status` is the answer to the only question
            // somebody actually has before pressing a button (greenhouse decisions/0250).
            $out[] = $this->notice($this->catalog->tr('capabilities.consequence'));
            // THE COMMAND, ONCE, AS A FORM. Seven rows repeating 45 near-identical characters is
            // redundancy the table already carries — the package IS the first column.
            $out[] = '<p class="admin-capabilities__form"><kbd class="mui-kbd">' . Html::escape($this->catalog->tr('capabilities.command_form')) . '</kbd></p>';
            // NO BUTTON WHERE THE ACT CANNOT BE JUDGED, and the reason said instead of hidden. The
            // operation declares a scope; with no `OperationHttpPolicy` in the app nothing here can hold
            // it, and the button answered `internal_error` (greenhouse decisions/0289). The command form
            // above stays either way — a person with a terminal was never blocked.
            $installable = ($data['installable'] ?? null) === true;
            if (!$installable) {
                $out[] = $this->notice($this->catalog->tr('capabilities.needs_judge'));
            }
            $out[] = $this->capabilityTable($available, $installed, $installable);
            // The enabler's script is only worth shipping where there is a button for it to bind to.
            if ($installable) {
                $out[] = $this->capabilityEnabler();
            }
        }

        return implode("\n", $out);
    }

    /**
     * @param list<mixed> $items
     * @param bool        $offerToEnable whether each row gets the button that runs `capabilities:enable`
     * @param bool        $installed     whether the row is an already-installed capability rather than an available one
     */
    /**
     * Equipping the house: one table, the act in a fixed column, the command factored out.
     *
     * This was a bullet list with the CLI command wedged between the title and the button, so every
     * button landed on a different x — the commands are 45 characters and no two are the same length.
     * It read as a debug dump on the screen where a person decides what their house becomes.
     *
     * ── WHY A TABLE AND NOT CARDS ───────────────────────────────────────────────────────────────
     *
     * Because there is nothing to put on a card. Each capability has an id, a package, ONE sentence,
     * and sometimes a list of unlocks. No icon, no version, no author, no screenshot. A card grid of
     * one-sentence items is padding pretending to be design, and the panel already speaks table —
     * the plugins table sits directly above this one.
     *
     * ── THE WORDS ARE THE ONES A NEWCOMER ALREADY OWNS ──────────────────────────────────────────
     *
     * `Available` and `Installed`, not `held` or `standing` or `in this house`. Somebody twenty
     * minutes into this framework does not yet own the house vocabulary, and this is the screen where
     * they decide what to install — the worst possible place to spend their vocabulary budget.
     *
     * And the consequence is named as a FILE: installing rewrites `config/plugins.php`, which is what
     * they will see in `git status` tomorrow. Every abstract phrasing of "code will run inside your
     * app" is weaker than naming the line that changes.
     *
     * @param list<array<string, mixed>> $available
     * @param list<array<string, mixed>> $installed
     */
    private function capabilityTable(array $available, array $installed, bool $installable = true): string
    {
        $rows = [];
        // AVAILABLE FIRST: this screen is for deciding, and the group that carries a decision is the
        // subject. What is already installed is the receipt underneath it.
        $rows[] = $this->capabilityGroup('capabilities.available', \count($available), 'capabilities.available_note', 'available');

        foreach ($available as $item) {
            $rows[] = $this->capabilityRow($item, 'package', $installable);
        }

        $rows[] = $this->capabilityGroup('capabilities.installed', \count($installed), 'capabilities.installed_note', 'installed');

        foreach ($installed as $item) {
            $rows[] = $this->capabilityRow($item, 'id', false, installed: true);
        }

        return '<div class="mui-table-wrap"><table class="mui-table admin-capabilities">'
            . '<thead><tr>'
            . '<th scope="col">' . Html::escape($this->catalog->tr('capabilities.col.name')) . '</th>'
            . '<th scope="col">' . Html::escape($this->catalog->tr('capabilities.col.what')) . '</th>'
            . '<th scope="col" class="admin-capabilities__act">' . Html::escape($this->catalog->tr('capabilities.col.act')) . '</th>'
            . '</tr></thead>'
            . implode('', $rows)
            . '</table></div>';
    }

    /**
     * A group heading that is a real rowgroup, so it is announced once and not repeated per row.
     */
    private function capabilityGroup(string $labelKey, int $count, string $noteKey, string $state): string
    {
        return '<tbody data-state="' . $state . '"><tr class="admin-capabilities__group"><th scope="rowgroup" colspan="3">'
            . '<span>' . Html::escape($this->catalog->tr($labelKey)) . '</span>'
            . '<span class="admin-capabilities__count">' . $count . '</span>'
            . '<span class="admin-capabilities__note">' . Html::escape($this->catalog->tr($noteKey)) . '</span>'
            . '</th></tr>';
    }

    /**
     * One capability. The act column is never empty — an installed row says so rather than going blank,
     * because a blank cell reads as something that has not finished loading.
     *
     * @param array<string, mixed> $item
     */
    private function capabilityRow(array $item, string $keyField, bool $offerToEnable, bool $installed = false): string
    {
        $key = (string) ($item[$keyField] ?? $item['package'] ?? '');
        $title = (string) ($item['title'] ?? '');
        $command = (string) ($item['command'] ?? '');
        $unlocks = array_values(array_filter(
            \is_array($item['unlocks'] ?? null) ? $item['unlocks'] : [],
            static fn (mixed $u): bool => \is_string($u) && $u !== '',
        ));

        // THREE STATES, NOT TWO. This column used to be «button or Installed», and the moment a third
        // reason to withhold the button appeared — an app with nothing that can authorize the act — the
        // false branch put «Installed» next to a capability that is NOT installed. Caught in the browser
        // on the same day it was written: the AVAILABLE group read «Installed» down its whole column
        // (greenhouse decisions/0289).
        //
        // So the row says which of the three it is. A dash is not an option: the reason lives in the
        // notice above the table, and repeating it twelve times is the noise `decisions/0250` removed.
        $act = match (true) {
            $key === '' => '',
            // THE BUTTON NAMES WHAT IT INSTALLS. «Install» alone, seven times in a column, cannot be
            // misread only because of where it sits; with the package in it, it cannot be misread at all.
            $offerToEnable => '<button type="button" class="mui-btn mui-btn--sm admin-enable" data-capability="' . Html::escape($key) . '"'
                . ($command !== '' ? ' data-command="' . Html::escape($command) . '"' : '') . '>'
                . Html::escape(\sprintf($this->catalog->tr('capabilities.install'), $key)) . '</button>'
                . '<span class="admin-enable-said" hidden></span>',
            $installed => '<span class="admin-capabilities__done">' . Html::escape($this->catalog->tr('capabilities.done')) . '</span>',
            default => '<span class="admin-capabilities__from-terminal">' . Html::escape($this->catalog->tr('capabilities.from_terminal')) . '</span>',
        };

        return '<tr data-capability="' . Html::escape($key) . '">'
            . '<td><code class="admin-capabilities__name">' . Html::escape($key) . '</code></td>'
            . '<td class="admin-capabilities__what">' . ($title !== '' ? Html::escape($title) : '')
            . ($unlocks !== [] ? '<span class="admin-capabilities__unlocks">' . Html::escape($this->join($unlocks)) . '</span>' : '')
            . '</td>'
            . '<td class="admin-capabilities__act">' . $act . '</td>'
            . '</tr>';
    }

    /**
     * The apply button's script — emitted only where the button is.
     *
     * It runs the framework's two-step confirm, the same ceremony the capability enabler runs: the first
     * POST answers `428` with a token and the second carries it in a header. The token travels as a
     * HEADER because that is where the operation's ceremony reads it — sent in the body it looks like a
     * first call and answers 428 forever (greenhouse decisions/0289).
     */
    private function frameworkApplier(): string
    {
        return str_replace(
            ['{ENDPOINT}', '{WORKING}', '{REFUSED}', '{DONE}'],
            [
                Html::escape($this->settings->route . '/framework/apply'),
                Html::escape($this->catalog->tr('house.update.applying')),
                Html::escape($this->catalog->tr('house.update.refused')),
                Html::escape($this->catalog->tr('house.update.applied')),
            ],
            self::FRAMEWORK_APPLIER,
        );
    }

    /** Asks, confirms, then reloads — because what it did is server-rendered, like the check's answer. */
    private const string FRAMEWORK_APPLIER = <<<'HTML'
        <script>
        (() => {
          if (window.__milpaFrameworkApply) { return; }
          window.__milpaFrameworkApply = true;
          document.addEventListener('click', async (event) => {
            const button = event.target.closest('.admin-fwapply');
            if (!button) { return; }
            const said = button.parentElement.querySelector('.admin-fwapply-said');
            const say = (text) => { if (said) { said.textContent = ' ' + text; said.hidden = false; } };
            button.disabled = true;
            say('{WORKING}');
            const post = (token) => fetch('{ENDPOINT}', {
              method: 'POST',
              headers: token
                ? { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Confirm-Token': token }
                : { 'Content-Type': 'application/json', 'Accept': 'application/json' },
              body: '{}',
            });
            try {
              let response = await post(null);
              let answer = await response.json();
              if (answer && answer.confirm_token) {
                response = await post(answer.confirm_token);
                answer = await response.json();
              }
              if (answer && answer.applied && answer.applied.length) { say('{DONE}'); setTimeout(() => location.reload(), 900); return; }
              say((answer && (answer.refused || answer.error)) || '{REFUSED}');
            } catch (failure) {
              say('{REFUSED}');
            }
            button.disabled = false;
          });
        })();
        </script>
        HTML;

    /**
     * The one script this section ships: the button that presses the verb.
     *
     * It is emitted only when there is a button for it to bind to, for the same reason the capability
     * enabler is — a script whose hooks the server did not print is bytes on the wire that can never
     * run (greenhouse decisions/0289).
     */
    private function frameworkChecker(): string
    {
        return str_replace(
            ['{ENDPOINT}', '{WORKING}', '{FAILED}', '{UNREACHABLE}'],
            [
                Html::escape($this->settings->route . '/framework/check'),
                Html::escape($this->catalog->tr('house.update.working')),
                Html::escape($this->catalog->tr('house.update.failed')),
                Html::escape($this->catalog->tr('house.update.unreachable')),
            ],
            self::FRAMEWORK_CHECKER,
        );
    }

    /**
     * Asks, then reloads — because the ANSWER is server-rendered.
     *
     * It could paint the table itself from the JSON, and that would be a second renderer for one fact:
     * the reconciliation is computed in PHP from two caches, and a reload shows exactly what any later
     * visit would show. The only thing this script owns is «I am asking» and «I could not».
     */
    private const string FRAMEWORK_CHECKER = <<<'HTML'
        <script>
        (() => {
          if (window.__milpaFrameworkCheck) { return; }
          window.__milpaFrameworkCheck = true;
          document.addEventListener('click', async (event) => {
            const button = event.target.closest('.admin-fwcheck');
            if (!button) { return; }
            const said = button.parentElement.querySelector('.admin-fwcheck-said');
            const say = (text) => { if (said) { said.textContent = ' ' + text; said.hidden = false; } };
            button.disabled = true;
            say('{WORKING}');
            try {
              const response = await fetch('{ENDPOINT}', { method: 'POST', headers: { 'Accept': 'application/json' } });
              const answer = await response.json();
              if (answer && answer.ok) { location.reload(); return; }
              say(answer && answer.error === 'registry_unreachable' ? '{UNREACHABLE}' : '{FAILED}');
            } catch (failure) {
              say('{UNREACHABLE}');
            }
            button.disabled = false;
          });
        })();
        </script>
        HTML;

    /**
     * The client half of the panel's first MUTATING act, and it is small on purpose.
     *
     * It does not run the capability install — `capabilities:enable` does, over its own governed surface. What
     * this does is present the ceremony that operation already demands: the first POST answers `428` with a
     * confirmation token, and only a second POST carrying that token proceeds. A human sees what they are about
     * to authorise and says yes; the panel never decides on their behalf.
     *
     * Delegated from the document, so it survives a section re-render without rebinding. Inline and dependency-
     * free: this package ships no JavaScript bundle, and one button is not a reason to start.
     *
     * The endpoint is the PANEL'S, mounted beside the panel's page and carrying the panel's middleware. It used
     * to be the app's global operations surface, which a fresh app does not expose — so the button answered 404
     * in precisely the house it exists for (greenhouse decisions/0248). A 404 now means this app has no
     * `capabilities:enable` at all, and the honest thing to say is the command already printed beside the
     * button — not a retry.
     */
    private function capabilityEnabler(): string
    {
        // THE PANEL'S OWN ROUTE, not the app's global operations surface. Reaching
        // `capabilities:enable` there requires the app to name it in `config/http.php`, and a fresh
        // app names nothing — so this button answered 404 in exactly the house it was built for
        // (greenhouse decisions/0248). The panel mounts what its button needs, behind its own gate.
        return str_replace('{ENDPOINT}', Html::escape($this->settings->route . '/capabilities/enable'), self::CAPABILITY_ENABLER);
    }

    private const string CAPABILITY_ENABLER = <<<'HTML'
        <script>
        (() => {
          if (window.__milpaAdminEnable) { return; }
          window.__milpaAdminEnable = true;
          // THE TOKEN TRAVELS AS A HEADER, which is where the operation's ceremony reads it. It was
          // sent in the body here, so the second POST looked like a first one and answered 428 with a
          // fresh token — forever. Nobody could see it: the endpoint 404'd before this step was ever
          // reached (greenhouse decisions/0248).
          const post = (body, token) => fetch('{ENDPOINT}', {
            method: 'POST',
            headers: Object.assign(
              { 'Content-Type': 'application/json', 'Accept': 'application/json' },
              token ? { 'Confirm-Token': token } : {},
            ),
            credentials: 'same-origin',
            body: JSON.stringify(body),
          });
          document.addEventListener('click', async (event) => {
            const button = event.target.closest('.admin-enable');
            if (!button) { return; }
            const capability = button.dataset.capability;
            const said = button.nextElementSibling;
            const say = (text) => { if (said) { said.textContent = ' ' + text; said.hidden = false; } };
            button.disabled = true;
            try {
              const asked = await post({ capability });
              if (asked.status === 404) { say('this app has no installer — use the command shown'); button.disabled = false; return; }
              const answer = await asked.json();
              if (answer.requires_confirmation && answer.confirm_token) {
                if (!window.confirm('Install ' + capability + '? It downloads code that will run inside this app.')) {
                  say('cancelled'); button.disabled = false; return;
                }
                const done = await post({ capability }, answer.confirm_token);
                const result = await done.json();
                say(done.ok && (result.ok ?? true) ? 'installed — reload to see it' : (result.error || 'refused'));
                return;
              }
              say(asked.ok ? 'installed — reload to see it' : (answer.error || 'refused'));
            } catch (failure) {
              say('could not reach the operation');
              button.disabled = false;
            }
          });
        })();
        </script>
        HTML;

    private function routes(StateSnapshot $state): string
    {
        $data = $state->data;
        $rows = \is_array($data['routes'] ?? null) ? $data['routes'] : [];
        $out = ['<h2 class="mui-h2">' . Html::escape($this->catalog->tr('routes.heading')) . '</h2>'];

        if (($data['kernel'] ?? false) !== true) {
            $out[] = $this->notice($this->catalog->tr('routes.no_kernel'));
        }

        if ($rows === []) {
            $out[] = $this->notice($this->catalog->tr('routes.empty'));

            return implode("\n", $out);
        }

        $cells = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $middleware = \is_array($row['middleware'] ?? null) ? array_map('strval', $row['middleware']) : [];
            $cells[] = '<tr>'
                . '<td><code>' . Html::escape((string) ($row['method'] ?? '')) . '</code></td>'
                . '<td><code>' . Html::escape((string) ($row['path'] ?? '')) . '</code></td>'
                . '<td>' . Html::escape((string) ($row['name'] ?? '')) . '</td>'
                . '<td><code>' . Html::escape((string) ($row['handler'] ?? '')) . '</code></td>'
                . '<td>' . ($middleware === [] ? Html::escape($this->catalog->tr('none')) : '<code>' . Html::escape(implode(', ', $middleware)) . '</code>') . '</td>'
                . '<td>' . Html::escape((string) ($row['plugin'] ?? '')) . '</td>'
                . '</tr>';
        }
        $out[] = $this->table(['col.method', 'col.path', 'col.route', 'col.handler', 'col.middleware', 'col.plugin'], $cells);

        return implode("\n", $out);
    }

    private function stack(StateSnapshot $state): string
    {
        $data = $state->data;
        $rows = \is_array($data['services'] ?? null) ? $data['services'] : [];
        $out = ['<h2 class="mui-h2">' . Html::escape($this->catalog->tr('stack.heading')) . '</h2>'];
        $out[] = '<p class="admin-stack__actions"><a class="mui-btn mui-btn--ghost" href="' . Html::escape($this->settings->composeUrl()) . '">'
            . Html::escape($this->catalog->tr('stack.download')) . '</a></p>';

        if (($data['kernel'] ?? false) !== true) {
            $out[] = $this->notice($this->catalog->tr('stack.no_kernel'));
        }

        if ($rows === []) {
            $out[] = $this->notice($this->catalog->tr('stack.empty'));

            return implode("\n", $out);
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $out[] = $this->service($row);
        }

        return implode("\n", $out);
    }

    /**
     * One service panel: the header with the name, the state badge and the probe; the body with the
     * declaration, the env table, the compose fragment — and, when another plugin declared the same name,
     * a danger badge and a notice naming the others.
     *
     * @param array<mixed> $row
     */
    private function service(array $row): string
    {
        $state = (string) ($row['state'] ?? 'unknown');
        $badge = match ($state) {
            'up' => 'mui-badge mui-badge--success',
            'down' => 'mui-badge mui-badge--warning',
            StackReader::CONFLICT => 'mui-badge mui-badge--danger',
            default => 'mui-badge',
        };
        $stateKey = \in_array($state, ['up', 'down', StackReader::CONFLICT], true) ? 'stack.state.' . $state : 'stack.state.unknown';
        $probePort = $row['probePort'] ?? null;
        $probe = \is_int($probePort)
            ? $this->catalog->tr('stack.probe', (string) ($row['probeHost'] ?? ''), (string) $probePort)
            : $this->catalog->tr('stack.no_probe');
        $summary = (string) ($row['summary'] ?? '');
        $name = (string) ($row['name'] ?? '');

        $out = ['<article class="mui-card admin-panel admin-stack__service">'];
        $out[] = self::panelHeader(Html::escape($name)
            . ' <span class="' . $badge . '">' . Html::escape($this->catalog->tr($stateKey)) . '</span>'
            . ' <small class="admin-stack__probe">' . Html::escape($probe) . '</small>');
        $out[] = '<div class="mui-card__body admin-panel__body">';
        if ($state === StackReader::CONFLICT) {
            $others = \is_array($row['conflictsWith'] ?? null) ? array_values(array_filter($row['conflictsWith'], 'is_string')) : [];
            $out[] = $this->notice($this->catalog->tr('stack.conflict', $name, $this->join($others)), 'danger');
        }
        if ($summary !== '') {
            $out[] = '<p class="admin-stack__summary">' . Html::escape($summary) . '</p>';
        }
        // DOWN WITHOUT A VERB IS A DEAD END. The panel had the compose fragment and a download and no
        // instruction: somebody saw red and had nowhere to go. It says the command now — and does not
        // run it. Starting containers on a person's machine because they opened a page is authority
        // this panel does not have, and the boundary is the same one that makes installing a
        // capability ask first (greenhouse decisions/0252).
        if ($state === 'down') {
            $out[] = '<p class="admin-stack__bring-up">' . Html::escape($this->catalog->tr('stack.bring_up'))
                . ' <kbd class="mui-kbd">' . Html::escape(\sprintf('docker compose up -d %s', $name)) . '</kbd></p>';
        }
        $out[] = '<dl class="admin-stack__facts">'
            . '<dt>' . Html::escape($this->catalog->tr('col.image')) . '</dt><dd><code>' . Html::escape((string) ($row['image'] ?? '')) . '</code></dd>'
            . '<dt>' . Html::escape($this->catalog->tr('col.ports')) . '</dt><dd>' . $this->codes($row['ports'] ?? null) . '</dd>'
            . '<dt>' . Html::escape($this->catalog->tr('col.volumes')) . '</dt><dd>' . $this->codes($row['volumes'] ?? null) . '</dd>'
            . '<dt>' . Html::escape($this->catalog->tr('col.command')) . '</dt><dd>' . $this->codes($row['command'] ?? null) . '</dd>'
            . '</dl>';

        $out[] = '<h4 class="mui-h4">' . Html::escape($this->catalog->tr('col.env')) . '</h4>';
        $env = \is_array($row['env'] ?? null) ? $row['env'] : [];
        if ($env === []) {
            $out[] = $this->notice($this->catalog->tr('none'));
        } else {
            $cells = [];
            foreach ($env as $var) {
                if (!\is_array($var)) {
                    continue;
                }
                $cells[] = $this->envRow($var);
            }
            $out[] = $this->table(['col.name', 'col.source', 'col.value'], $cells);
        }

        $out[] = '<p class="admin-stack__declared">' . Html::escape($this->catalog->tr('stack.declared_by', (string) ($row['plugin'] ?? ''))) . '</p>';
        $out[] = '<h4 class="mui-h4">' . Html::escape($this->catalog->tr('stack.compose')) . '</h4>';
        $out[] = '<pre class="admin-compose"><code>' . Html::escape((string) ($row['compose'] ?? '')) . '</code></pre>';
        $out[] = '</div></article>';

        return implode("\n", $out);
    }

    /**
     * @param array<mixed> $var
     */
    private function envRow(array $var): string
    {
        $source = (string) ($var['source'] ?? ResolvedEnv::UNSET);
        $configKey = $var['configKey'] ?? null;
        $sourceKey = \in_array($source, [ResolvedEnv::LITERAL, ResolvedEnv::CONFIG, ResolvedEnv::SECRET], true)
            ? 'stack.source.' . $source
            : 'stack.source.unset';
        $sourceHtml = Html::escape($this->catalog->tr($sourceKey))
            . (\is_string($configKey) && $configKey !== '' ? ' <code>' . Html::escape($configKey) . '</code>' : '');
        $valueHtml = match ($source) {
            ResolvedEnv::SECRET => '<span class="admin-stack__secret">' . Html::escape($this->catalog->tr('stack.secret')) . '</span>',
            ResolvedEnv::UNSET => '<em>' . Html::escape($this->catalog->tr('stack.unset')) . '</em>',
            default => '<code>' . Html::escape((string) ($var['display'] ?? '')) . '</code>',
        };

        return '<tr>'
            . '<td><code>' . Html::escape((string) ($var['name'] ?? '')) . '</code></td>'
            . '<td>' . $sourceHtml . '</td>'
            . '<td>' . $valueHtml . '</td>'
            . '</tr>';
    }

    /**
     * The Dev tools section (greenhouse decisions/0205): the overview — the agent's sessions with their state
     * and real token cost, each id a link into its timeline; the debt signals by kind, the four real kinds
     * listed even at zero, each with its one-line gloss; the evidence ledger; the declared log's tail — or,
     * when the state carries a session, the drill-down: its header, the way back, and the timeline. Every
     * block paints its own empty and error states, so one ledger failing leaves the others readable, and
     * the page says which store or file the ledger was read from. Not one form, not one button: the
     * section reads and never acts.
     */
    private function devtools(StateSnapshot $state): string
    {
        $data = $state->data;

        return ($data['view'] ?? '') === DevToolsComponent::VIEW_SESSION
            ? $this->devtoolsSession($data)
            : $this->devtoolsOverview($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function devtoolsOverview(array $data): string
    {
        $out = ['<h2 class="mui-h2">' . Html::escape($this->catalog->tr('devtools.heading'))
            . ' <span class="mui-badge">' . Html::escape($this->catalog->tr('devtools.readonly')) . '</span></h2>'];
        $out[] = '<p class="admin-devtools__hint">' . Html::escape($this->catalog->tr('devtools.hint')) . '</p>';

        $unavailable = $this->devtoolsUnavailable($data);
        if ($unavailable !== null) {
            $out[] = $unavailable;
        } else {
            $out[] = $this->devtoolsSessions(\is_array($data['sessions'] ?? null) ? $data['sessions'] : [], $this->source($data));
            $out[] = $this->devtoolsDebt(\is_array($data['debt'] ?? null) ? $data['debt'] : []);
            $out[] = $this->devtoolsEvidence(\is_array($data['evidence'] ?? null) ? $data['evidence'] : []);
        }
        $out[] = $this->devtoolsLog(\is_array($data['log'] ?? null) ? $data['log'] : []);

        return implode("\n", $out);
    }

    /**
     * The notice when the agent ledger cannot be read — naming the package (and where the ledger would be
     * read from) when it is not installed, the missing store and kernel when the app registered neither —
     * or null when it can.
     *
     * @param array<string, mixed> $data
     */
    private function devtoolsUnavailable(array $data): ?string
    {
        if (($data['available'] ?? false) === true) {
            return null;
        }

        return $this->notice(($data['why'] ?? '') === DevToolsSource::WHY_KERNEL
            ? $this->catalog->tr('devtools.no_kernel')
            : $this->catalog->tr('devtools.no_agent', $this->source($data)));
    }

    /**
     * Where the ledger is read from — the registered store's class or the file's path — or the none glyph.
     *
     * @param array<string, mixed> $data
     */
    private function source(array $data): string
    {
        $source = $data['source'] ?? null;

        return \is_string($source) && $source !== '' ? $source : $this->catalog->tr('none');
    }

    /**
     * The sessions block: the table of the newest sessions, then the hint that says where the ledger was
     * read from and what the read left out — lines that could not be read, streams without a start, older
     * sessions past the cap.
     *
     * @param array<string, mixed> $block
     */
    private function devtoolsSessions(array $block, string $source): string
    {
        $out = ['<h3 class="mui-h3">' . Html::escape($this->catalog->tr('devtools.sessions')) . '</h3>'];
        $error = $block['error'] ?? null;
        if (\is_string($error)) {
            $out[] = $this->notice($this->catalog->tr('devtools.sessions.error', $error), 'danger');
            $out[] = $this->ledgerHint($source, $block);

            return implode("\n", $out);
        }
        $rows = \is_array($block['rows'] ?? null) ? array_values(array_filter($block['rows'], 'is_array')) : [];
        if ($rows === []) {
            $out[] = $this->notice($this->catalog->tr('devtools.sessions.empty'));
            $out[] = $this->ledgerHint($source, $block);

            return implode("\n", $out);
        }

        $cells = [];
        foreach ($rows as $row) {
            $cells[] = $this->sessionRow($row);
        }
        $out[] = $this->table(['col.session', 'col.state', 'col.goal', 'col.mode', 'col.tokens', 'col.pending'], $cells);
        $out[] = $this->ledgerHint($source, $block);

        return implode("\n", $out);
    }

    /**
     * «Read from X · N line(s) could not be read · N stream(s) without a start · N older not listed» —
     * only the parts that are true.
     *
     * @param array<string, mixed> $block
     */
    private function ledgerHint(string $source, array $block): string
    {
        $parts = [$this->catalog->tr('devtools.source', $source)];
        foreach (['unreadable' => 'devtools.ledger.unreadable', 'unstarted' => 'devtools.sessions.unstarted', 'more' => 'devtools.sessions.more'] as $field => $key) {
            $count = $block[$field] ?? 0;
            if (\is_int($count) && $count > 0) {
                $parts[] = $this->catalog->tr($key, (string) $count);
            }
        }

        return '<p class="admin-devtools__hint">' . Html::escape(implode(' · ', $parts)) . '</p>';
    }

    /**
     * One session row: the id linking into its timeline, the state badge, the goal cut short, the mode,
     * the provider's tokens in/out (or «not reported» — absent is not zero), and what it waits on — the
     * reason as a badge, the question itself inline beside it.
     *
     * @param array<mixed> $row
     */
    private function sessionRow(array $row): string
    {
        $id = (string) ($row['id'] ?? '');
        $pending = \is_array($row['pending'] ?? null) ? $row['pending'] : null;
        $pendingHtml = Html::escape($this->catalog->tr('none'));
        if ($pending !== null) {
            $reason = (string) ($pending['reason'] ?? '');
            $question = (string) ($pending['question'] ?? '');
            $pendingHtml = '<span class="mui-badge mui-badge--accent">'
                . Html::escape($reason !== '' ? $reason : $this->catalog->tr('devtools.pending')) . '</span>'
                . ($question !== '' ? ' <small>' . Html::escape(self::cut($question, 120)) . '</small>' : '');
        }

        return '<tr>'
            . '<td><a href="' . Html::escape($this->sessionUrl($id)) . '"><code>' . Html::escape($id) . '</code></a></td>'
            . '<td>' . $this->stateBadge((string) ($row['state'] ?? '')) . '</td>'
            . '<td>' . Html::escape(self::cut((string) ($row['goal'] ?? ''), 72)) . '</td>'
            . '<td><code>' . Html::escape((string) ($row['mode'] ?? '')) . '</code></td>'
            . '<td><code>' . Html::escape($this->tokens($row)) . '</code></td>'
            . '<td>' . $pendingHtml . '</td>'
            . '</tr>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function devtoolsDebt(array $block): string
    {
        $out = ['<h3 class="mui-h3">' . Html::escape($this->catalog->tr('devtools.debt')) . '</h3>'];
        $out[] = '<p class="admin-devtools__hint">' . Html::escape($this->catalog->tr('devtools.debt.hint')) . '</p>';
        $error = $block['error'] ?? null;
        if (\is_string($error)) {
            $out[] = $this->notice($this->catalog->tr('devtools.debt.error', $error), 'danger');

            return implode("\n", $out);
        }
        if (($block['total'] ?? 0) === 0) {
            $out[] = $this->notice($this->catalog->tr('devtools.debt.empty'));
        }

        $cells = [];
        foreach (\is_array($block['kinds'] ?? null) ? $block['kinds'] : [] as $kind) {
            if (!\is_array($kind)) {
                continue;
            }
            $sessions = \is_array($kind['sessions'] ?? null) ? array_values(array_filter($kind['sessions'], 'is_string')) : [];
            $links = [];
            foreach ($sessions as $session) {
                $links[] = '<a href="' . Html::escape($this->sessionUrl($session)) . '"><code>' . Html::escape($session) . '</code></a>';
            }
            $name = (string) ($kind['kind'] ?? '');
            $gloss = $this->catalog->has('devtools.debt.kind.' . $name) ? '<br><small>' . Html::escape($this->catalog->tr('devtools.debt.kind.' . $name)) . '</small>' : '';
            $cells[] = '<tr>'
                . '<td><code>' . Html::escape($name) . '</code>' . $gloss . '</td>'
                . '<td>' . (int) ($kind['count'] ?? 0) . '</td>'
                . '<td>' . ($links === [] ? Html::escape($this->catalog->tr('none')) : implode(' ', $links)) . '</td>'
                . '</tr>';
        }
        $out[] = $this->table(['col.kind', 'col.count', 'col.sessions'], $cells);

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function devtoolsEvidence(array $block): string
    {
        $out = ['<h3 class="mui-h3">' . Html::escape($this->catalog->tr('devtools.evidence')) . '</h3>'];
        $out[] = '<p class="admin-devtools__hint">' . Html::escape($this->catalog->tr('devtools.evidence.hint')) . '</p>';
        $error = $block['error'] ?? null;
        if (\is_string($error)) {
            $out[] = $this->notice($this->catalog->tr('devtools.evidence.error', $error), 'danger');

            return implode("\n", $out);
        }
        $items = \is_array($block['items'] ?? null) ? array_values(array_filter($block['items'], 'is_array')) : [];
        if ($items === []) {
            $out[] = $this->notice($this->catalog->tr('devtools.evidence.empty'));

            return implode("\n", $out);
        }

        $cells = [];
        foreach ($items as $item) {
            $todo = \is_string($item['todo'] ?? null) && $item['todo'] !== '' ? ' <small>' . Html::escape($this->catalog->tr('devtools.evidence.todo', $item['todo'])) . '</small>' : '';
            $detail = \is_string($item['detail'] ?? null) && $item['detail'] !== '' ? ' <small>' . Html::escape($item['detail']) . '</small>' : '';
            $session = (string) ($item['session'] ?? '');
            $cells[] = '<tr>'
                . '<td>' . $this->time($item['when'] ?? null) . '</td>'
                . '<td><a href="' . Html::escape($this->sessionUrl($session)) . '"><code>' . Html::escape($session) . '</code></a></td>'
                . '<td><code>' . Html::escape((string) ($item['kind'] ?? '')) . '</code></td>'
                . '<td><code>' . Html::escape((string) ($item['reference'] ?? '')) . '</code>' . $todo . $detail . '</td>'
                . '</tr>';
        }
        $out[] = $this->table(['col.time', 'col.session', 'col.kind', 'col.reference'], $cells);

        return implode("\n", $out);
    }

    /**
     * The log block: what `admin.log` declared, or that it declared nothing; a path outside the app root,
     * a missing or an unreadable file as a notice that names the path — and, when no root is known, the
     * notice that says so; an empty file said so; else the tail in a `<pre>`.
     *
     * @param array<string, mixed> $log
     */
    private function devtoolsLog(array $log): string
    {
        $out = ['<h3 class="mui-h3">' . Html::escape($this->catalog->tr('devtools.log')) . '</h3>'];
        if (($log['declared'] ?? false) !== true) {
            $out[] = $this->notice($this->catalog->tr('devtools.log.undeclared'));

            return implode("\n", $out);
        }
        $path = (string) ($log['path'] ?? '');
        $error = $log['error'] ?? null;
        if (\is_string($error)) {
            $key = match ($error) {
                'missing' => 'devtools.log.missing',
                DevToolsSource::LOG_OUTSIDE => 'devtools.log.outside',
                default => 'devtools.log.unreadable',
            };
            $out[] = $this->notice($this->catalog->tr($key, $path), 'danger');
            if (!\is_string($log['root'] ?? null)) {
                $out[] = $this->notice($this->catalog->tr('devtools.log.no_root'), 'warning');
            }

            return implode("\n", $out);
        }
        $lines = \is_array($log['lines'] ?? null) ? array_values(array_filter($log['lines'], 'is_string')) : [];
        if ($lines === []) {
            $out[] = $this->notice($this->catalog->tr('devtools.log.empty', $path));

            return implode("\n", $out);
        }

        $out[] = '<p class="admin-devtools__hint">' . Html::escape($this->catalog->tr('devtools.log.tail', (string) \count($lines), $path))
            . (($log['truncated'] ?? false) === true ? ' · ' . Html::escape($this->catalog->tr('devtools.log.truncated')) : '') . '</p>';
        $out[] = '<pre class="admin-log"><code>' . Html::escape(implode("\n", $lines)) . '</code></pre>';

        return implode("\n", $out);
    }

    /**
     * The drill-down of one session: the header with its state, goal, mode, tokens, debt count and
     * instants; the way back to the ledgers; the timeline — time, event, detail — as the projector paints
     * it, under one line saying where the stream was read from. A session nobody recorded is a notice,
     * not a blank page.
     *
     * @param array<string, mixed> $data
     */
    private function devtoolsSession(array $data): string
    {
        $session = \is_array($data['row'] ?? null) ? $data['row'] : null;
        $id = (string) ($data['id'] ?? ($session['id'] ?? ''));

        $out = ['<h2 class="mui-h2">' . Html::escape($this->catalog->tr('devtools.session', $id))
            . ($session !== null ? ' ' . $this->stateBadge((string) ($session['state'] ?? '')) : '') . '</h2>'];
        $out[] = '<p class="admin-devtools__actions"><a class="mui-btn mui-btn--ghost" href="' . Html::escape($this->withLang($this->settings->sectionUrl(DevToolsComponent::SECTION))) . '">'
            . Html::escape($this->catalog->tr('devtools.back')) . '</a></p>';

        $unavailable = $this->devtoolsUnavailable($data);
        $error = $data['error'] ?? null;
        if ($unavailable !== null) {
            $out[] = $unavailable;

            return implode("\n", $out);
        }
        if (\is_string($error)) {
            $out[] = $this->notice($this->catalog->tr('devtools.sessions.error', $error), 'danger');

            return implode("\n", $out);
        }
        if (($data['found'] ?? false) !== true || $session === null) {
            $out[] = $this->notice($this->catalog->tr('devtools.session.unknown', $id));
            $out[] = $this->ledgerHint($this->source($data), $data);

            return implode("\n", $out);
        }

        $out[] = $this->sessionFacts($session);
        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('devtools.timeline')) . '</h3>';
        $unreadable = $data['unreadable'] ?? 0;
        $out[] = '<p class="admin-devtools__hint">' . Html::escape($this->catalog->tr('devtools.timeline.hint', $this->source($data))
            . (\is_int($unreadable) && $unreadable > 0 ? ' · ' . $this->catalog->tr('devtools.ledger.unreadable', (string) $unreadable) : '')) . '</p>';
        $events = \is_array($data['events'] ?? null) ? array_values(array_filter($data['events'], 'is_array')) : [];
        if ($events === []) {
            $out[] = $this->notice($this->catalog->tr('devtools.timeline.empty'));

            return implode("\n", $out);
        }

        $cells = [];
        foreach ($events as $event) {
            $cells[] = '<tr>'
                . '<td>' . $this->time($event['when'] ?? null) . '</td>'
                . '<td>' . $this->eventLabel((string) ($event['kind'] ?? '')) . $this->flags($event['flags'] ?? null) . '</td>'
                . '<td>' . Html::escape((string) ($event['detail'] ?? '')) . '</td>'
                . '</tr>';
        }
        $out[] = $this->table(['col.time', 'col.event', 'col.detail'], $cells);

        return implode("\n", $out);
    }

    /**
     * The drill-down header as a fact list: goal, mode, tokens in and out, debt signals, events, the first
     * and last instants, why it ended when it did, and the closure verdict when the house derived one.
     *
     * @param array<mixed> $session
     */
    private function sessionFacts(array $session): string
    {
        $fact = static fn (string $label, string $valueHtml): string => '<dt>' . Html::escape($label) . '</dt><dd>' . $valueHtml . '</dd>';
        $tokens = fn (mixed $count): string => \is_int($count) ? number_format($count) : $this->catalog->tr('devtools.tokens.unreported');

        $facts = $fact($this->catalog->tr('col.goal'), Html::escape((string) ($session['goal'] ?? '')))
            . $fact($this->catalog->tr('col.mode'), '<code>' . Html::escape((string) ($session['mode'] ?? '')) . '</code>')
            . $fact($this->catalog->tr('devtools.tokens.in'), Html::escape($tokens($session['tokensIn'] ?? null)))
            . $fact($this->catalog->tr('devtools.tokens.out'), Html::escape($tokens($session['tokensOut'] ?? null)))
            . $fact($this->catalog->tr('devtools.debt'), (string) (int) ($session['debt'] ?? 0))
            . $fact($this->catalog->tr('devtools.events'), (string) (int) ($session['events'] ?? 0))
            . $fact($this->catalog->tr('devtools.started'), $this->time($session['startedAt'] ?? null))
            . $fact($this->catalog->tr('devtools.last'), $this->time($session['lastAt'] ?? null));
        if (\is_string($session['endedBecause'] ?? null)) {
            $facts .= $fact($this->catalog->tr('devtools.ended_because'), Html::escape($session['endedBecause']));
        }
        $closure = \is_array($session['closure'] ?? null) ? $session['closure'] : null;
        if ($closure !== null) {
            $verified = ($closure['verified'] ?? false) === true;
            $facts .= $fact(
                $this->catalog->tr('devtools.closure'),
                '<span class="mui-badge ' . ($verified ? 'mui-badge--success' : 'mui-badge--danger') . '">' . Html::escape($this->catalog->tr($verified ? 'devtools.flag.verified' : 'devtools.flag.unverified')) . '</span>'
                . ($verified ? '' : ' ' . Html::escape($this->catalog->tr('devtools.closure.reasons', (string) (int) ($closure['reasons'] ?? 0)))),
            );
        }

        return '<dl class="admin-devtools__facts">' . $facts . '</dl>';
    }

    /** The state badge of a session: `running` green, `waiting` accent, `interrupted` amber, `done` plain. */
    private function stateBadge(string $state): string
    {
        $class = match ($state) {
            DevToolsSource::STATE_RUNNING => 'mui-badge mui-badge--success',
            DevToolsSource::STATE_WAITING => 'mui-badge mui-badge--accent',
            DevToolsSource::STATE_INTERRUPTED => 'mui-badge mui-badge--warning',
            default => 'mui-badge',
        };
        $label = $this->catalog->has('devtools.state.' . $state) ? $this->catalog->tr('devtools.state.' . $state) : $state;

        return '<span class="' . $class . '" data-state="' . Html::escape($state) . '">' . Html::escape($label) . '</span>';
    }

    /** A timeline event's label — the catalog's when it knows the kind, the kind itself otherwise. */
    private function eventLabel(string $kind): string
    {
        $key = 'devtools.event.' . $kind;

        return Html::escape($this->catalog->has($key) ? $this->catalog->tr($key) : $kind);
    }

    /** The flags of a timeline event as badges: `failed` and `unverified` red, `mutating` amber, `verified` green, anything else plain. */
    private function flags(mixed $flags): string
    {
        $html = '';
        foreach (\is_array($flags) ? array_filter($flags, 'is_string') : [] as $flag) {
            $class = match ($flag) {
                'failed', 'unverified' => ' mui-badge--danger',
                'mutating' => ' mui-badge--warning',
                'verified' => ' mui-badge--success',
                default => '',
            };
            $key = 'devtools.flag.' . $flag;
            $html .= ' <span class="mui-badge' . $class . '">' . Html::escape($this->catalog->has($key) ? $this->catalog->tr($key) : $flag) . '</span>';
        }

        return $html;
    }

    /**
     * «in / out» from the provider's own numbers, or «not reported» when no call carried usage.
     *
     * @param array<mixed> $row
     */
    private function tokens(array $row): string
    {
        $in = $row['tokensIn'] ?? null;
        $out = $row['tokensOut'] ?? null;
        if (!\is_int($in) || !\is_int($out)) {
            return $this->catalog->tr('devtools.tokens.unreported');
        }

        return number_format($in) . ' / ' . number_format($out);
    }

    /** An instant as `<time>`, or the none glyph for a record that predates the field. */
    private function time(mixed $when): string
    {
        if (!\is_string($when) || $when === '') {
            return Html::escape($this->catalog->tr('none'));
        }

        return '<time datetime="' . Html::escape($when) . '">' . Html::escape($when) . '</time>';
    }

    /** The URL of one session's timeline inside the Dev tools section, carrying the request's `?lang=` when it had one. */
    private function sessionUrl(string $id): string
    {
        return $this->withLang($this->settings->sectionUrl(DevToolsComponent::SECTION), [DevToolsComponent::SESSION_PARAM => $id]);
    }

    /**
     * A panel URL with its query — plus `lang` when the request overrode the locale, so the page it opens
     * answers in the same language as the one it was opened from.
     *
     * @param array<string, string> $query
     */
    private function withLang(string $url, array $query = []): string
    {
        if ($this->lang !== null) {
            $query[AdminController::LANG_PARAM] = $this->lang;
        }

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /** The text cut to `$max` code points with an ellipsis — whole characters, no mbstring. */
    private static function cut(string $text, int $max): string
    {
        return preg_match('/^(.{' . ($max - 1) . '}).+/us', $text, $head) === 1 ? $head[1] . '…' : $text;
    }

    /** A list of strings as `<code>` chips, or the none glyph. */
    private function codes(mixed $items): string
    {
        $items = \is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        if ($items === []) {
            return Html::escape($this->catalog->tr('none'));
        }

        return implode(' ', array_map(static fn (string $item): string => '<code>' . Html::escape($item) . '</code>', $items));
    }

    /**
     * @param list<string> $columnKeys
     * @param list<string> $rowsHtml
     */
    private function table(array $columnKeys, array $rowsHtml): string
    {
        $head = '';
        foreach ($columnKeys as $key) {
            $head .= '<th scope="col">' . Html::escape($this->catalog->tr($key)) . '</th>';
        }

        return '<div class="mui-table-wrap"><table class="mui-table"><thead><tr>' . $head . '</tr></thead><tbody>'
            . implode('', $rowsHtml)
            . '</tbody></table></div>';
    }

    private function notice(string $text, string $tone = 'info'): string
    {
        return '<p class="mui-alert mui-alert--' . $tone . ' admin-notice">' . Html::escape($text) . '</p>';
    }

    /**
     * «A, B and C» in the catalog's language.
     *
     * @param list<string> $items
     */
    private function join(array $items): string
    {
        if (\count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' ' . $this->catalog->tr('list.and') . ' ' . $last;
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . Html::escape($state->componentId) . '">'
            . $this->codec->encodeState($state)
            . '</script>';
    }
}
