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

use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Components\RoutesComponent;
use Milpa\Admin\I18n\Catalog;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Paints the panel's own components — `admin-plugins` and `admin-routes` — as HTML on the `mui-*` design
 * classes, and closes each with its signed state envelope like every Milpa component.
 *
 * Every string a human reads comes from the {@see Catalog}; every value from the state is escaped.
 */
final class AdminHtmlRenderer implements ComponentRendererInterface
{
    public function __construct(
        private readonly StateTransferCodecInterface $codec,
        private readonly Catalog $catalog,
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
            default => throw new \InvalidArgumentException(\sprintf(
                '%s renders %s and %s, not «%s».',
                self::class,
                PluginsComponent::NAME,
                RoutesComponent::NAME,
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
