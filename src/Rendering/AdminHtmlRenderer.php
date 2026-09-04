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
use Milpa\Admin\Components\StackComponent;
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
 * Paints the panel's own components — `admin-plugins`, `admin-routes` and `admin-stack` — as HTML on the
 * `mui-*` design classes, and closes each with its signed state envelope like every Milpa component.
 *
 * Every string a human reads comes from the {@see Catalog}; every value from the state is escaped; the
 * one link it emits (the compose file) is built from the declared {@see AdminSettings}.
 */
final class AdminHtmlRenderer implements ComponentRendererInterface
{
    public function __construct(
        private readonly StateTransferCodecInterface $codec,
        private readonly Catalog $catalog,
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

        $body = match ($name) {
            PluginsComponent::NAME => $this->plugins($state),
            RoutesComponent::NAME => $this->routes($state),
            StackComponent::NAME => $this->stack($state),
            default => throw new \InvalidArgumentException(\sprintf(
                '%s renders %s, %s and %s, not «%s».',
                self::class,
                PluginsComponent::NAME,
                RoutesComponent::NAME,
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
     * One service card: heading with the state badge, the declaration, the env table, the compose fragment.
     *
     * @param array<mixed> $row
     */
    private function service(array $row): string
    {
        $state = (string) ($row['state'] ?? 'unknown');
        $badge = match ($state) {
            'up' => 'mui-badge mui-badge--success',
            'down' => 'mui-badge mui-badge--warning',
            default => 'mui-badge',
        };
        $stateKey = \in_array($state, ['up', 'down'], true) ? 'stack.state.' . $state : 'stack.state.unknown';
        $probePort = $row['probePort'] ?? null;
        $probe = \is_int($probePort)
            ? $this->catalog->tr('stack.probe', (string) $probePort)
            : $this->catalog->tr('stack.no_probe');
        $summary = (string) ($row['summary'] ?? '');

        $out = ['<article class="mui-card admin-stack__service">'];
        $out[] = '<h3 class="mui-h3">' . Html::escape((string) ($row['name'] ?? ''))
            . ' <span class="' . $badge . '">' . Html::escape($this->catalog->tr($stateKey)) . '</span>'
            . ' <small class="admin-stack__probe">' . Html::escape($probe) . '</small></h3>';
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

    private function notice(string $text): string
    {
        return '<p class="mui-alert mui-alert--info admin-notice">' . Html::escape($text) . '</p>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . Html::escape($state->componentId) . '">'
            . $this->codec->encodeState($state)
            . '</script>';
    }
}
