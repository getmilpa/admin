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

namespace Milpa\Admin\View;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\I18n\Catalog;

/**
 * Wraps the shell into a full HTML document: the design tokens and bundle, the client runtime and Alpine
 * (served by the panel itself, no build step), the declared locale as `lang`.
 *
 * Owns no surface markup — that is the components' — only the document around them.
 */
final class AdminPage
{
    public function __construct(
        private readonly AdminSettings $settings,
        private readonly Catalog $catalog,
    ) {
    }

    /** The document around a rendered shell. */
    public function render(string $shellHtml, string $title = ''): string
    {
        $documentTitle = $title === '' ? $this->settings->title : $title . ' · ' . $this->settings->title;

        return '<!doctype html>' . "\n"
            . '<html lang="' . self::e($this->catalog->locale()) . '" data-theme="dark">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . self::e($documentTitle) . '</title>' . "\n"
            . '<link rel="stylesheet" href="' . self::e($this->settings->assetUrl('tokens.css')) . '">' . "\n"
            . '<link rel="stylesheet" href="' . self::e($this->settings->assetUrl('bundle.css')) . '">' . "\n"
            . '<style>' . self::css() . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body class="mui-body milpa-admin">' . "\n"
            . $shellHtml . "\n"
            . '<script src="' . self::e($this->settings->assetUrl('milpa-live.js')) . '" defer></script>' . "\n"
            . '<script src="' . self::e($this->settings->assetUrl('alpine.min.js')) . '" defer></script>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /** A plain error document in the same skin — the 404 of an unknown section, the 500 of a conflict. */
    public function error(int $status, string $message): string
    {
        $shell = '<main class="mui-shell__main milpa-admin-error"><h1 class="mui-h1">' . $status . '</h1><p class="mui-alert mui-alert--warning">' . self::e($message) . '</p>'
            . '<p><a class="mui-btn mui-btn--ghost" href="' . self::e($this->settings->route) . '">' . self::e($this->settings->title) . '</a></p></main>';

        return $this->render($shell, (string) $status);
    }

    private static function css(): string
    {
        return '.milpa-admin .admin-section{display:grid;gap:var(--space-4,1rem);padding:var(--space-4,1rem)}'
            . '.milpa-admin .admin-notice{margin:0}'
            . '.milpa-admin .admin-capabilities{display:grid;gap:.25rem;padding-left:1.25rem}'
            . '.milpa-admin .mui-table td,.milpa-admin .mui-table th{vertical-align:top}'
            . '.milpa-admin-error{padding:var(--space-6,2rem);display:grid;gap:1rem;max-width:60ch}';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
