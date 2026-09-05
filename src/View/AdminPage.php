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
 * Owns no surface markup — that is the components' — only the document around them, and the two scripts
 * that keep the viewer's panel preferences: one at the top of `<body>` that applies the stored theme
 * before anything paints, one at the end that stores what the Settings section's `[data-pref]` controls
 * say (`localStorage`, key {@see self::PREFS_KEY}) and applies it — theme on `<html data-theme>`, density
 * on `.mui-shell[data-density]`, the language override by navigating with `?lang=` and keeping it on every
 * in-panel link. Nothing of it is stored on the server; a delegated listener, no per-instance state
 * (greenhouse decisions/0204).
 */
final class AdminPage
{
    public const PREFS_KEY = 'milpa.admin.prefs';

    public function __construct(
        private readonly AdminSettings $settings,
        private Catalog $catalog,
    ) {
    }

    /** The same document answering in another catalog — a request's `?lang=` — with everything else shared. */
    public function withCatalog(Catalog $catalog): self
    {
        $page = clone $this;
        $page->catalog = $catalog;

        return $page;
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
            . '<script data-admin-prefs="early">' . self::earlyThemeScript() . '</script>' . "\n"
            . $shellHtml . "\n"
            . '<script src="' . self::e($this->settings->assetUrl('milpa-live.js')) . '" defer></script>' . "\n"
            . '<script src="' . self::e($this->settings->assetUrl('alpine.min.js')) . '" defer></script>' . "\n"
            . '<script data-admin-prefs="delegated">' . $this->prefsScript() . '</script>' . "\n"
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
            . '.milpa-admin .admin-section__header{padding:var(--space-4,1rem) var(--space-4,1rem) 0}'
            . '.milpa-admin .admin-section__header .mui-page-header__text{display:flex;flex-wrap:wrap;align-items:baseline;gap:var(--space-3,.75rem)}'
            . '.milpa-admin .admin-section__header .mui-page-header__title{margin:0}'
            . '.milpa-admin .admin-section__declared{opacity:.7;font-size:.875rem}'
            . '.milpa-admin .admin-notice{margin:0}'
            . '.milpa-admin .admin-capabilities{display:grid;gap:.25rem;padding-left:1.25rem}'
            . '.milpa-admin .mui-table td,.milpa-admin .mui-table th{vertical-align:top}'
            . '.milpa-admin .admin-stack__actions{margin:0}'
            . '.milpa-admin .admin-stack__service{display:grid;gap:var(--space-3,.75rem)}'
            . '.milpa-admin .admin-stack__probe{font-weight:normal;opacity:.7}'
            . '.milpa-admin .admin-stack__facts{display:grid;grid-template-columns:max-content 1fr;gap:.25rem 1rem;margin:0}'
            . '.milpa-admin .admin-stack__facts dd,.milpa-admin .admin-stack__summary,.milpa-admin .admin-stack__declared{margin:0}'
            . '.milpa-admin .admin-compose,.milpa-admin .admin-snippet{margin:0;overflow-x:auto;white-space:pre}'
            . '.milpa-admin .admin-settings__prefs{display:grid;gap:var(--space-3,.75rem)}'
            . '.milpa-admin .admin-settings__hint{margin:0;opacity:.7}'
            . '.milpa-admin .admin-prefs{display:grid;gap:var(--space-3,.75rem);grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));margin:0}'
            . '.milpa-admin .admin-prefs__field{display:grid;gap:.25rem}'
            . '.milpa-admin .admin-settings__secret{letter-spacing:.1em;margin-right:.5rem}'
            . '.milpa-admin .admin-settings__declared{opacity:.7}'
            . '.milpa-admin .admin-chip+.admin-chip{margin-left:.5rem}'
            . '.milpa-admin .admin-devtools__hint,.milpa-admin .admin-devtools__actions{margin:0}'
            . '.milpa-admin .admin-devtools__hint{opacity:.7}'
            . '.milpa-admin .admin-devtools__facts{display:grid;grid-template-columns:max-content 1fr;gap:.25rem 1rem;margin:0}'
            . '.milpa-admin .admin-devtools__facts dd{margin:0}'
            . '.milpa-admin .admin-log{margin:0;overflow:auto;white-space:pre;max-height:32rem}'
            . '.milpa-admin-error{padding:var(--space-6,2rem);display:grid;gap:1rem;max-width:60ch}';
    }

    /**
     * Runs before the shell is parsed: the stored theme lands on `<html>` so the first paint is already
     * the viewer's. `system` resolves through `prefers-color-scheme`, because the tokens require `<html>`
     * to always carry `dark` or `light`.
     */
    private static function earlyThemeScript(): string
    {
        return '(function(){try{var p=JSON.parse(localStorage.getItem("' . self::PREFS_KEY . '")||"{}"),t=p&&p.theme;'
            . 'if(t==="system"){t=window.matchMedia&&window.matchMedia("(prefers-color-scheme: light)").matches?"light":"dark";}'
            . 'if(t==="dark"||t==="light"){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();';
    }

    /**
     * One delegated `change` listener over `[data-pref]` controls: store, then apply. Theme and density
     * apply in place; the language override navigates with `?lang=` (the server renders the locale) and
     * stays sticky — every anchor under the shell root whose `href` starts with the panel's route carries
     * it (section links, the brand, the compose file), and a page that arrived without it is sent back
     * with it. A brand anchor painted as `#` (the `dashboard-sidebar` primitive's, should a subscriber compose
     * with it — the panel's own sidebar links the brand to the route) is pointed at the panel's root first, so it
     * is a panel link like the others.
     */
    private function prefsScript(): string
    {
        $root = json_encode($this->settings->route, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return '(function(){var KEY="' . self::PREFS_KEY . '",ROOT=' . $root . ';'
            . 'function read(){try{var v=JSON.parse(localStorage.getItem(KEY)||"{}");return v&&typeof v==="object"?v:{};}catch(e){return {};}}'
            . 'function write(p){try{localStorage.setItem(KEY,JSON.stringify(p));}catch(e){}}'
            . 'function theme(t){if(t==="system"){t=window.matchMedia&&window.matchMedia("(prefers-color-scheme: light)").matches?"light":"dark";}'
            . 'document.documentElement.setAttribute("data-theme",t==="light"?"light":"dark");}'
            . 'function density(d){var s=document.querySelector(".mui-shell");if(s){s.setAttribute("data-density",d==="compact"?"compact":"comfortable");}}'
            . 'function withLang(href,code){var u=new URL(href,location.href);if(code&&code!=="server"){u.searchParams.set("lang",code);}else{u.searchParams.delete("lang");}return u.pathname+u.search+u.hash;}'
            . 'function inPanel(h){return h===ROOT||h.indexOf(ROOT+"/")===0||h.indexOf(ROOT+"?")===0;}'
            . 'function apply(p){theme(p.theme||"dark");density(p.density||"comfortable");'
            . 'var cs=document.querySelectorAll("[data-pref]");for(var i=0;i<cs.length;i++){var c=cs[i],k=c.getAttribute("data-pref");if(typeof p[k]==="string"){c.value=p[k];}}}'
            . 'function home(){var b=document.querySelector(".mui-sidebar__brand");if(b&&b.getAttribute("href")==="#"){b.setAttribute("href",ROOT);}}'
            . 'function sticky(p){var l=p.lang;if(!l||l==="server"){return;}'
            . 'var root=document.querySelector(".mui-shell")||document.body,links=root.querySelectorAll("a[href]");'
            . 'for(var i=0;i<links.length;i++){var h=links[i].getAttribute("href");if(inPanel(h)){links[i].setAttribute("href",withLang(h,l));}}'
            . 'if(document.documentElement.lang!==l&&!new URL(location.href).searchParams.has("lang")){location.replace(withLang(location.href,l));}}'
            . 'document.addEventListener("change",function(e){var c=e.target;if(!c||!c.getAttribute){return;}var k=c.getAttribute("data-pref");if(!k){return;}'
            . 'var p=read();p[k]=c.value;write(p);'
            . 'if(k==="theme"){theme(p.theme);}else if(k==="density"){density(p.density);}else if(k==="lang"){location.href=withLang(location.href,p.lang);}});'
            . 'document.addEventListener("submit",function(e){if(e.target&&e.target.hasAttribute&&e.target.hasAttribute("data-prefs")){e.preventDefault();}});'
            . 'try{window.matchMedia("(prefers-color-scheme: light)").addEventListener("change",function(){if(read().theme==="system"){theme("system");}});}catch(e){}'
            . 'function boot(){var p=read();apply(p);home();sticky(p);}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot);}else{boot();}'
            . '})();';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
