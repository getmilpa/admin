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
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Components\RoutesComponent;
use Milpa\Admin\Components\SettingsComponent;
use Milpa\Admin\Components\StackComponent;
use Milpa\Admin\Controllers\AdminController;
use Milpa\Admin\Data\DevToolsSource;
use Milpa\Admin\Data\StackSource;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Stack\ResolvedEnv;
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
            PluginsComponent::NAME => $painter->plugins($state),
            RoutesComponent::NAME => $painter->routes($state),
            SettingsComponent::NAME => $painter->settings($state),
            StackComponent::NAME => $painter->stack($state),
            DevToolsComponent::NAME => $painter->devtools($state),
            default => throw new \InvalidArgumentException(\sprintf(
                '%s renders %s, %s, %s, %s and %s, not «%s».',
                self::class,
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
     * The Settings section: the viewer's panel preferences (browser-local, `[data-pref]` controls the page's
     * delegated script stores and applies), then the read-only configuration table — key, value, source —
     * with the empty state's snippet when the app declared nothing (worded «entirely on defaults» only when
     * every source IS a default) and the danger notice when the declared gate cannot be carried: one that
     * names each defective entry, or one that names what was received when it was not a list at all.
     */
    private function settings(StateSnapshot $state): string
    {
        $data = $state->data;
        $declared = ($data['declared'] ?? false) === true;
        $malformed = ($data['malformed'] ?? false) === true;
        $unresolved = \is_array($data['unresolved'] ?? null) ? array_values(array_filter($data['unresolved'], 'is_string')) : [];
        $rows = \is_array($data['rows'] ?? null) ? array_values(array_filter($data['rows'], 'is_array')) : [];

        $out = ['<h2 class="mui-h2">' . Html::escape($this->catalog->tr('settings.heading')) . '</h2>'];
        $out[] = $this->preferences((string) ($data['locale'] ?? AdminSettings::DEFAULT_LOCALE));

        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('settings.config')) . '</h3>';
        $out[] = '<p class="admin-settings__hint">' . Html::escape($this->catalog->tr('settings.config.hint')) . '</p>';
        if (!$declared) {
            $out[] = $this->notice($this->catalog->tr(self::allDefault($rows) ? 'settings.empty' : 'settings.empty.partial'));
            $out[] = '<pre class="admin-snippet"><code>' . Html::escape((string) ($data['snippet'] ?? '')) . '</code></pre>';
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
            $cells[] = $this->settingRow($row, $unresolved);
        }
        $out[] = $this->table(['col.key', 'col.value', 'col.source'], $cells);

        return implode("\n", $out);
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
     * declared middleware list with a defective entry wears the danger badge on the value; a key the
     * panel rejected shows the EFFECTIVE value, what the app declared next to it, and the danger badge
     * on the source — never `default` for something the app did write.
     *
     * @param array<mixed> $row
     * @param list<string> $unresolved
     */
    private function settingRow(array $row, array $unresolved): string
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

        $valueHtml = match ($key) {
            'secret' => '<span class="admin-settings__secret" aria-hidden="true">' . Html::escape($this->catalog->tr('settings.secret.mask')) . '</span>'
                . Html::escape($this->catalog->tr(self::secretKey($value))),
            default => '<code>' . Html::escape($value) . '</code>'
                . ($broken ? ' <span class="mui-badge mui-badge--danger">' . Html::escape($this->catalog->tr('settings.unresolved.badge')) . '</span>' : '')
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
     * The «Panel preferences» card: theme and density (this browser only, applied in place) and the
     * language override (sent as `?lang=` with each request, stored only in this browser) — plain
     * controls tagged `data-pref`, no state of their own; the page's delegated script owns them.
     */
    private function preferences(string $serverLocale): string
    {
        $languages = ['server' => $this->catalog->tr('settings.lang.server', $serverLocale)];
        foreach (Catalog::locales() as $code) {
            $languages[$code] = $code;
        }

        return '<article class="mui-card admin-settings__prefs">'
            . '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('settings.prefs')) . '</h3>'
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
            . '</article>';
    }

    /**
     * @param array<string, string> $options value → label
     */
    private function select(string $pref, string $labelKey, array $options, string $hint = ''): string
    {
        $html = '<label class="admin-prefs__field" for="admin-pref-' . $pref . '"><span>' . Html::escape($this->catalog->tr($labelKey)) . '</span>'
            . '<select class="mui-input mui-input--sm" data-pref="' . $pref . '" id="admin-pref-' . $pref . '">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . Html::escape($value) . '">' . Html::escape($label) . '</option>';
        }
        $html .= '</select>';
        if ($hint !== '') {
            $html .= '<small class="admin-settings__hint">' . Html::escape($hint) . '</small>';
        }

        return $html . '</label>';
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
            $out[] = $this->capabilityList('plugins.installed', \is_array($capabilities['installed'] ?? null) ? $capabilities['installed'] : [], 'id');
            $out[] = $this->capabilityList('plugins.available', \is_array($capabilities['available'] ?? null) ? $capabilities['available'] : [], 'package');
        }

        return implode("\n", $out);
    }

    /**
     * @param list<mixed> $items
     */
    private function capabilityList(string $headingKey, array $items, string $keyField): string
    {
        $out = ['<h4 class="mui-h4">' . Html::escape($this->catalog->tr($headingKey)) . '</h4>'];
        if ($items === []) {
            $out[] = $this->notice($this->catalog->tr('none'));

            return implode("\n", $out);
        }
        $out[] = '<ul class="mui-list admin-capabilities">';
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $key = (string) ($item[$keyField] ?? $item['package'] ?? '');
            $title = (string) ($item['title'] ?? '');
            $command = (string) ($item['command'] ?? '');
            $out[] = '<li><code>' . Html::escape($key) . '</code>'
                . ($title !== '' ? ' — ' . Html::escape($title) : '')
                . ($command !== '' ? ' <kbd class="mui-kbd">' . Html::escape($command) . '</kbd>' : '')
                . '</li>';
        }
        $out[] = '</ul>';

        return implode("\n", $out);
    }

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
     * One service card: heading with the state badge, the declaration, the env table, the compose fragment
     * — and, when another plugin declared the same name, a danger badge and a notice naming the others.
     *
     * @param array<mixed> $row
     */
    private function service(array $row): string
    {
        $state = (string) ($row['state'] ?? 'unknown');
        $badge = match ($state) {
            'up' => 'mui-badge mui-badge--success',
            'down' => 'mui-badge mui-badge--warning',
            StackSource::CONFLICT => 'mui-badge mui-badge--danger',
            default => 'mui-badge',
        };
        $stateKey = \in_array($state, ['up', 'down', StackSource::CONFLICT], true) ? 'stack.state.' . $state : 'stack.state.unknown';
        $probePort = $row['probePort'] ?? null;
        $probe = \is_int($probePort)
            ? $this->catalog->tr('stack.probe', (string) ($row['probeHost'] ?? ''), (string) $probePort)
            : $this->catalog->tr('stack.no_probe');
        $summary = (string) ($row['summary'] ?? '');
        $name = (string) ($row['name'] ?? '');

        $out = ['<article class="mui-card admin-stack__service">'];
        $out[] = '<h3 class="mui-h3">' . Html::escape($name)
            . ' <span class="' . $badge . '">' . Html::escape($this->catalog->tr($stateKey)) . '</span>'
            . ' <small class="admin-stack__probe">' . Html::escape($probe) . '</small></h3>';
        if ($state === StackSource::CONFLICT) {
            $others = \is_array($row['conflictsWith'] ?? null) ? array_values(array_filter($row['conflictsWith'], 'is_string')) : [];
            $out[] = $this->notice($this->catalog->tr('stack.conflict', $name, $this->join($others)), 'danger');
        }
        if ($summary !== '') {
            $out[] = '<p class="admin-stack__summary">' . Html::escape($summary) . '</p>';
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
        $out[] = '</article>';

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
