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
use Milpa\Live\Http\LiveBoot;
use Milpa\Live\Support\ClientRuntime;
use Milpa\Live\ValueObjects\ClientAssets;

/**
 * Wraps the shell into a full HTML document: the design tokens and bundle, the client runtime and Alpine
 * (served by the panel itself, no build step), the declared locale as `lang`.
 *
 * Owns no surface markup — that is the components' — only the document around them: the panel's own
 * stylesheet ({@see self::css()}: the document painted, a base layer for the raw HTML a section emits, the
 * panel's classes) and the two scripts that keep the viewer's panel preferences: one at the top of `<body>`
 * that applies the stored theme before anything paints, one at the end that stores what the Settings
 * section's `[data-pref]` controls say (`localStorage`, key {@see self::PREFS_KEY}) and applies it — theme
 * on `<html data-theme>`, density on `.mui-shell[data-density]`, the language override by navigating with
 * `?lang=` and keeping it on every in-panel link. Nothing of it is stored on the server; a delegated
 * listener, no per-instance state (greenhouse decisions/0204).
 *
 * **One runtime per page** (greenhouse decisions/0211). The page hand-writes no runtime `<script>` tag any
 * more: given the boot the controller issued and what the compile declared, `LiveBoot::html()` emits — in
 * `<head>`, after the panel's own stylesheets — every declared stylesheet, the boot payload,
 * `milpa-live.js`, `milpa-live-remote.js`, every declared plugin module in declared order, and Alpine
 * LAST, each `defer`, each URL once. The three seed tags (`#milpa-live-signals`, `#milpa-live-persist`,
 * `#milpa-live-computed`) are written here and only here, from the {@see LiveSeeds} the shell merged, so
 * the page never has two places that could disagree about what the store starts with. A document rendered
 * WITHOUT a boot — the 404 of an unknown section, the 500 of a conflict — carries no runtime at all: there
 * is no live component on it to serve.
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

    /**
     * The document around a rendered shell.
     *
     * @param LiveBoot|null     $boot   the page's live boot — the endpoint, its session and its CSRF token; null for a
     *                                  document with no live component on it (an error page), which then emits no runtime
     * @param ClientAssets|null $assets what the compile declared — the stylesheets and modules the components need
     * @param LiveSeeds|null    $seeds  the merged signal seeds; the three tags are emitted whenever there is a boot
     */
    public function render(string $shellHtml, string $title = '', ?LiveBoot $boot = null, ?ClientAssets $assets = null, ?LiveSeeds $seeds = null): string
    {
        $documentTitle = $title === '' ? $this->settings->title : $title . ' · ' . $this->settings->title;

        return '<!doctype html>' . "\n"
            . '<html lang="' . self::e($this->catalog->locale()) . '" data-theme="dark">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . self::e($documentTitle) . '</title>' . "\n"
            // The panel's stylesheet goes BEFORE the design bundle: its `admin.base` layer is declared first,
            // so it sits under every `milpa.*` layer the bundle declares — the bundle's pieces win over it.
            . '<style>' . "\n" . self::css() . '</style>' . "\n"
            . '<link rel="stylesheet" href="' . self::e($this->settings->assetUrl('tokens.css')) . '">' . "\n"
            . '<link rel="stylesheet" href="' . self::e($this->settings->assetUrl('bundle.css')) . '">' . "\n"
            // A guest's stylesheets come after the panel's, unlayered, so a component keeps the look it declared;
            // its modules are deferred, so their position in the head costs nothing (decisions/0211).
            . $this->runtime($boot, $assets, $seeds)
            . '</head>' . "\n"
            . '<body class="mui-body milpa-admin">' . "\n"
            . '<script data-admin-prefs="early">' . self::earlyThemeScript() . '</script>' . "\n"
            . $shellHtml . "\n"
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

    /**
     * Where the panel serves each runtime file — its own asset route, so no build step and no CDN
     * ({@see \Milpa\Admin\Controllers\AssetsController} reads them out of `milpa/live-web`).
     *
     * @return array<string, string>
     */
    public function runtimeUrls(): array
    {
        return [
            ClientRuntime::LOCAL => $this->settings->assetUrl(ClientRuntime::LOCAL),
            ClientRuntime::REMOTE => $this->settings->assetUrl(ClientRuntime::REMOTE),
            ClientRuntime::ALPINE => $this->settings->assetUrl(ClientRuntime::ALPINE),
        ];
    }

    /**
     * The three seed tags and everything {@see LiveBoot::html()} emits — nothing at all without a boot.
     * The seeds are DATA (`type="application/json"`): `</` is escaped so a value can never close the tag.
     */
    private function runtime(?LiveBoot $boot, ?ClientAssets $assets, ?LiveSeeds $seeds): string
    {
        if ($boot === null) {
            return '';
        }
        $seeds ??= LiveSeeds::empty();

        return self::seedTag('milpa-live-signals', $seeds->signals === [] ? new \stdClass() : $seeds->signals)
            . self::seedTag('milpa-live-persist', $seeds->persist)
            . self::seedTag('milpa-live-computed', $seeds->computed === [] ? new \stdClass() : $seeds->computed)
            . $boot->html($this->runtimeUrls(), $assets ?? ClientAssets::empty()) . "\n";
    }

    /** One seed tag, JSON-encoded and safe inside a `<script>` element. */
    private static function seedTag(string $id, mixed $value): string
    {
        $json = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return '<script id="' . $id . '" type="application/json">' . str_replace('</', '<\/', $json) . '</script>' . "\n";
    }

    /**
     * The panel's stylesheet, in cascade order. The design bundle keeps everything it publishes in
     * `@layer milpa.*` (its THEMING contract: unlayered CSS always wins over it; a layer declared before its
     * own loses to every piece of it), and the panel uses both sides of that contract:
     *
     *   1. `@layer admin.base` — declared first, so it sits UNDER the whole bundle: the base look of the raw
     *      HTML a section emits inside the shell (its `<p>`, `<dl>`, `<pre>`, `<code>`, `<a>`, headings, table
     *      cells, form controls), all in the shell's own tokens. A `mui-*` piece that says otherwise wins by
     *      layer — `.mui-btn` keeps its colour and no underline although it is an `<a>`; `.mui-card__title`
     *      keeps its size although it is an `<h3>`. Nothing of the bundle is restyled here, and nothing the
     *      bundle leaves unsaid on its pieces is filled in from here either: the heading scale tracks only
     *      `h1` and `h2` (the bundle's own prose scale), so a `.mui-card__title` keeps its tracking; only a
     *      classless `<a>` gets the rounded focus ring, so a `.mui-sidebar__brand` keeps its corners. Rhythm
     *      comes from the containers (every panel body and section is a grid with a gap), not from margins on
     *      the headings. A `<dl>` is a two-column grid in the table's rhythm: the label column pads on its end
     *      so the row rules meet, and rows pad like a `.mui-table` cell.
     *   2. The document — `html` and `body` painted with the page's tokens and no browser margin, so the
     *      canvas never shows through, and the colour scheme following `<html data-theme>`.
     *   3. The panel's own classes (`admin-*`) and the one layout decision over the shell: the panel is an
     *      app, not a document — the shell is the viewport, main is a column whose section header keeps its
     *      height and whose section body (`.admin-section__body`, the shell's wrapper around the active
     *      section) takes the rest and is what scrolls (a keyboard scroller, like the bundle's `.mui-table-wrap`:
     *      `tabindex="0"`, a named region, its focus ring drawn inside because main clips). A section that
     *      wants to fill the remaining height can (it is a flex item that shrinks to it; `height: 100%`
     *      resolves against it); a section taller than the viewport scrolls inside main, never the page.
     *      «Never the page» also needs the topbar to yield: a grid item's minimum is its content, and a chip
     *      carrying a passkey id is wider than a laptop — so the topbar's minimum is zero and it clips, and the
     *      principal chip truncates with an ellipsis (its full text in `title`), so no width of the shell
     *      widens the shell's column past the viewport.
     *
     * Colours and sizes come only from the tokens — no literal colour, no invented step.
     */
    private static function css(): string
    {
        return <<<'CSS'
            @layer admin.base;
            @layer admin.base{
            .milpa-admin{font-family:var(--font-body);font-size:var(--text-sm);line-height:var(--leading-normal);color:var(--text)}
            .milpa-admin :is(h1,h2,h3,h4,h5,h6){margin:0;font-family:var(--font-heading);font-weight:var(--weight-medium);line-height:var(--leading-snug);color:var(--text)}
            .milpa-admin h1{font-size:var(--text-2xl);letter-spacing:var(--tracking-tight)}
            .milpa-admin h2{font-size:var(--text-xl);letter-spacing:var(--tracking-tight)}
            .milpa-admin h3{font-size:var(--text-lg)}
            .milpa-admin :is(h4,h5,h6){font-size:var(--text-base)}
            .milpa-admin p{margin:0 0 var(--space-3)}
            .milpa-admin p:last-child{margin-block-end:0}
            .milpa-admin small{font-size:var(--text-xs);color:var(--text-muted)}
            .milpa-admin em{color:var(--text-muted)}
            .milpa-admin a{color:var(--accent-text);text-decoration:underline;text-decoration-color:var(--border-strong);text-decoration-thickness:1px;text-underline-offset:.15em;transition:text-decoration-color var(--dur-fast) var(--ease-standard)}
            .milpa-admin a:not([class]){border-radius:var(--radius-xs)}
            .milpa-admin a:visited{color:var(--accent-text)}
            .milpa-admin a:hover{text-decoration-color:currentColor}
            .milpa-admin a:focus-visible{outline:var(--focus-width) solid var(--focus);outline-offset:var(--focus-offset)}
            .milpa-admin :is(code,kbd,samp,pre){font-family:var(--font-mono);font-size:var(--text-xs)}
            .milpa-admin code{padding:.1em .35em;color:inherit;background:var(--surface-raised);border:var(--border-width) var(--border-style) var(--border-subtle);border-radius:var(--radius-xs);overflow-wrap:anywhere}
            .milpa-admin a:has(>code){text-decoration:none}
            .milpa-admin a>code{color:var(--accent-text);background:var(--accent-subtle);border-color:var(--accent-active)}
            .milpa-admin a:hover>code{border-color:currentColor}
            .milpa-admin pre{margin:0;padding:var(--space-4);overflow:auto;line-height:var(--leading-normal);color:var(--syntax-text);background:var(--syntax-bg);border:var(--border-width) var(--border-style) var(--border-subtle);border-radius:var(--radius-base);tab-size:2}
            .milpa-admin pre code{padding:0;font-size:inherit;color:inherit;background:transparent;border:0}
            .milpa-admin dl{display:grid;grid-template-columns:max-content minmax(0,1fr);align-items:baseline;margin:0}
            .milpa-admin :is(dt,dd){margin:0;padding-block:var(--space-3);border-bottom:var(--border-width) var(--border-style) var(--border-subtle)}
            .milpa-admin dt{padding-inline-end:var(--space-4);font-family:var(--font-mono);font-size:var(--text-2xs);text-transform:uppercase;letter-spacing:var(--tracking-wide);color:var(--text-muted)}
            .milpa-admin dd{min-width:0;color:var(--text-secondary)}
            .milpa-admin :is(dt,dd):last-of-type{border-bottom:0}
            .milpa-admin table{border-collapse:collapse}
            .milpa-admin :is(th,td){padding:var(--space-2) var(--space-3);text-align:start;vertical-align:top}
            .milpa-admin :is(ul,ol){margin:0;padding-inline-start:var(--space-5)}
            .milpa-admin hr{margin-block:var(--space-4);border:0;border-top:var(--border-width) var(--border-style) var(--border-subtle)}
            .milpa-admin :is(button,input,select,textarea){font-family:inherit;font-size:inherit;color:inherit}
            }
            html,body{margin:0;background:var(--bg);color:var(--text)}
            html{color-scheme:dark}
            html[data-theme="light"]{color-scheme:light}
            .milpa-admin{--admin-inset:clamp(var(--space-5),3vw,var(--space-8))}
            .milpa-admin .mui-shell{height:100dvh}
            .milpa-admin .mui-shell>.mui-topbar{min-width:0;overflow:hidden}
            .milpa-admin .mui-shell>.mui-shell__main{display:flex;flex-direction:column;min-height:0;overflow:hidden;padding:0}
            .milpa-admin .admin-section__header{flex:none;margin:0;padding:var(--space-6) var(--admin-inset) var(--space-4)}
            .milpa-admin .admin-section__header .mui-page-header__text{display:flex;flex-wrap:wrap;align-items:baseline;gap:var(--space-3)}
            .milpa-admin .admin-section__header .mui-page-header__title{margin:0}
            .milpa-admin .admin-section__declared{font-family:var(--font-mono);font-size:var(--text-xs);color:var(--text-muted)}
            .milpa-admin .admin-section__body{display:flex;flex-direction:column;flex:1 1 auto;min-height:0;overflow:auto;padding:0 var(--admin-inset) var(--admin-inset)}
            .milpa-admin .admin-section__body:focus-visible{outline:var(--focus-width) solid var(--focus);outline-offset:calc(-1 * var(--focus-width))}
            .milpa-admin .admin-section{display:grid;gap:var(--space-4);min-width:0;padding:0}
            .milpa-admin :is(h1,h2,h3,h4)>.mui-badge{vertical-align:middle;margin-inline-start:var(--space-1)}
            .milpa-admin .admin-notice{margin:0}
            .milpa-admin .admin-capabilities{display:grid;gap:var(--space-1);padding-inline-start:var(--space-5)}
            .milpa-admin .admin-panel__header{padding-block:var(--space-4);border-bottom:var(--border-width) var(--border-style) var(--border-subtle)}
            .milpa-admin .admin-panel__title{display:flex;flex-wrap:wrap;align-items:center;gap:var(--space-2);margin:0}
            .milpa-admin .admin-panel__body{display:grid;gap:var(--space-4);padding:var(--space-5)}
            .milpa-admin .admin-stack__actions,.milpa-admin .admin-stack__summary,.milpa-admin .admin-devtools__actions{margin:0}
            .milpa-admin .admin-stack__probe{font-family:var(--font-mono);font-size:var(--text-xs);font-weight:var(--weight-regular);color:var(--text-muted)}
            .milpa-admin .admin-stack__declared{margin:0;color:var(--text-muted)}
            .milpa-admin .admin-compose,.milpa-admin .admin-snippet,.milpa-admin .admin-log{margin:0;white-space:pre}
            .milpa-admin .admin-log{max-height:32rem}
            .milpa-admin .admin-settings__hint,.milpa-admin .admin-devtools__hint{margin:0;color:var(--text-muted)}
            .milpa-admin .admin-prefs{display:grid;gap:var(--space-3);grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));margin:0}
            .milpa-admin .admin-settings__secret{letter-spacing:.1em;margin-inline-end:var(--space-2)}
            .milpa-admin .admin-settings__declared{color:var(--text-muted)}
            .milpa-admin .admin-chip+.admin-chip{margin-inline-start:var(--space-2)}
            .milpa-admin .admin-chip--principal{display:inline-block;max-width:28ch;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
            .milpa-admin .admin-section__failure{display:block;margin:0}
            .milpa-admin .admin-section__failure-why{font-family:var(--font-mono);font-size:var(--text-xs);overflow-wrap:anywhere}
            .milpa-admin .milpa-admin-error{display:grid;gap:var(--space-4);max-width:60ch}

            CSS;
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
