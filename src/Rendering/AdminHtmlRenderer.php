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
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Components\RoutesComponent;
use Milpa\Admin\Components\SettingsComponent;
use Milpa\Admin\Components\StackComponent;
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
 * Paints the panel's own components — `admin-plugins`, `admin-routes`, `admin-settings` and `admin-stack`
 * — as HTML on the `mui-*` design classes, and closes each with its signed state envelope like every
 * Milpa component.
 *
 * Every string a human reads comes from the {@see Catalog} — the one the request's {@see ComponentContext}
 * names by locale, else the one the renderer booted with; every value from the state is escaped; the
 * one link it emits (the compose file) is built from the declared {@see AdminSettings}.
 */
final class AdminHtmlRenderer implements ComponentRendererInterface
{
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

    /** Mounts (unless the request carries state) and paints the component the contract names. */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $state = $request->state ?? $component->mount($request->props, $request->context);
        $name = $component::contract()->name;
        $painter = $this->forLocale($request->context->locale);

        $body = match ($name) {
            PluginsComponent::NAME => $painter->plugins($state),
            RoutesComponent::NAME => $painter->routes($state),
            SettingsComponent::NAME => $painter->settings($state),
            StackComponent::NAME => $painter->stack($state),
            default => throw new \InvalidArgumentException(\sprintf(
                '%s renders %s, %s, %s and %s, not «%s».',
                self::class,
                PluginsComponent::NAME,
                RoutesComponent::NAME,
                SettingsComponent::NAME,
                StackComponent::NAME,
                $name,
            )),
        };

        $html = \sprintf(
            '<section %s>%s</section>%s',
            Html::attrs([
                'class' => 'admin-section admin-section--' . $name,
                'id' => $state->componentId,
                'data-milpa-component-id' => $state->componentId,
            ]),
            $body,
            $this->envelope($state),
        );

        return new RenderResult(output: $html, state: $state, format: RenderTarget::HTML);
    }

    /** This renderer answering in the context's locale when the catalog carries it — itself otherwise. */
    private function forLocale(?string $locale): self
    {
        if ($locale === null || $locale === $this->catalog->locale() || !\in_array($locale, Catalog::locales(), true)) {
            return $this;
        }
        $painter = clone $this;
        $painter->catalog = new Catalog($locale);

        return $painter;
    }

    /**
     * The Settings section: the viewer's panel preferences (browser-local, `[data-pref]` controls the page's
     * delegated script stores and applies), then the read-only configuration table — key, value, source —
     * with the empty state's snippet when the app declared nothing and the fallback notice when a declared
     * middleware class does not exist.
     */
    private function settings(StateSnapshot $state): string
    {
        $data = $state->data;
        $declared = ($data['declared'] ?? false) === true;
        $unresolved = \is_array($data['unresolved'] ?? null) ? array_values(array_filter($data['unresolved'], 'is_string')) : [];
        $rows = \is_array($data['rows'] ?? null) ? $data['rows'] : [];

        $out = ['<h2 class="mui-h2">' . Html::escape($this->catalog->tr('settings.heading')) . '</h2>'];
        $out[] = $this->preferences((string) ($data['locale'] ?? AdminSettings::DEFAULT_LOCALE));

        $out[] = '<h3 class="mui-h3">' . Html::escape($this->catalog->tr('settings.config')) . '</h3>';
        $out[] = '<p class="admin-settings__hint">' . Html::escape($this->catalog->tr('settings.config.hint')) . '</p>';
        if (!$declared) {
            $out[] = $this->notice($this->catalog->tr('settings.empty'));
            $out[] = '<pre class="admin-snippet"><code>' . Html::escape((string) ($data['snippet'] ?? '')) . '</code></pre>';
        }
        if ($unresolved !== []) {
            $out[] = $this->notice($this->catalog->tr('settings.unresolved', $this->join($unresolved)), 'danger');
        }

        $cells = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $cells[] = $this->settingRow($row, $unresolved);
        }
        $out[] = $this->table(['col.key', 'col.value', 'col.source'], $cells);

        return implode("\n", $out);
    }

    /**
     * One configuration row. The secret's value is only where it came from, behind the mask glyph; the
     * middleware row wears the danger badge when a declared class does not exist.
     *
     * @param array<mixed> $row
     * @param list<string> $unresolved
     */
    private function settingRow(array $row, array $unresolved): string
    {
        $key = (string) ($row['key'] ?? '');
        $value = (string) ($row['value'] ?? '');
        $source = ($row['source'] ?? '') === AdminSettings::SOURCE_CONFIG ? AdminSettings::SOURCE_CONFIG : AdminSettings::SOURCE_DEFAULT;
        $broken = $key === 'middleware' && $unresolved !== [];

        $valueHtml = match ($key) {
            'secret' => '<span class="admin-settings__secret" aria-hidden="true">' . Html::escape($this->catalog->tr('settings.secret.mask')) . '</span>'
                . Html::escape($this->catalog->tr(self::secretKey($value))),
            default => '<code>' . Html::escape($value) . '</code>'
                . ($broken ? ' <span class="mui-badge mui-badge--danger">' . Html::escape($this->catalog->tr('settings.unresolved.badge')) . '</span>' : ''),
        };
        $sourceHtml = '<span class="mui-badge' . ($source === AdminSettings::SOURCE_CONFIG ? ' mui-badge--accent' : '') . '">'
            . Html::escape($this->catalog->tr('settings.source.' . $source)) . '</span>';

        return '<tr' . ($broken ? ' class="admin-settings__row--unresolved"' : '') . '>'
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
     * The «Panel preferences» card: theme, density, language override and remembered filters — plain
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
            . '<label class="admin-prefs__check"><input type="checkbox" data-pref="filters" id="admin-pref-filters"> '
            . Html::escape($this->catalog->tr('settings.pref.filters')) . '</label>'
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
