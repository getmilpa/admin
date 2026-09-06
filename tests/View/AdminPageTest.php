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

namespace Milpa\Admin\Tests\View;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\View\AdminPage;
use Milpa\Admin\View\LiveSeeds;
use Milpa\Live\Http\LiveBoot;
use Milpa\Live\ValueObjects\ClientAssets;
use PHPUnit\Framework\TestCase;

final class AdminPageTest extends TestCase
{
    public function testWrapsTheShellWithAssetsRuntimeAndLocale(): void
    {
        $page = new AdminPage(new AdminSettings(route: '/panel', locale: 'es', title: 'Casa'), new Catalog('es'));

        $html = $page->render('<div id="shell"></div>', 'Rutas');

        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringContainsString('<html lang="es"', $html);
        self::assertStringContainsString('<title>Rutas · Casa</title>', $html);
        self::assertStringContainsString('href="/panel/assets/tokens.css"', $html);
        self::assertStringContainsString('href="/panel/assets/bundle.css"', $html);
        self::assertStringContainsString('<div id="shell"></div>', $html);
        // greenhouse decisions/0211: the page hand-writes no runtime tag. Without a boot there is no live
        // component on the document, so it carries no runtime at all — see the LiveBoot tests below.
        self::assertStringNotContainsString('milpa-live.js', $html);
        self::assertStringNotContainsString('alpine.min.js', $html);
        self::assertStringContainsString('ROOT="/panel"', $html, 'the script knows the panel\'s route, so it knows which links are the panel\'s');
        self::assertStringContainsString('<title>Casa</title>', $page->render('', ''));

        $hostile = (new AdminPage(new AdminSettings(route: '/p"><script>alert(1)</script>'), new Catalog()))->render('');
        self::assertStringContainsString('ROOT="/p\u0022\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E"', $hostile, 'the route reaches the script as a JSON string that cannot close the tag');
        self::assertStringNotContainsString('ROOT="/p"><script>', $hostile);
    }

    public function testCarriesThePreferenceScriptsAndAnswersInAnotherCatalog(): void
    {
        $page = new AdminPage(AdminSettings::fromConfig(null), new Catalog());

        $html = $page->render('<div id="shell"></div>');

        $body = strpos($html, '<body');
        $early = strpos($html, '<script data-admin-prefs="early">');
        $shell = strpos($html, '<div id="shell"></div>');
        $delegated = strpos($html, '<script data-admin-prefs="delegated">');
        self::assertNotFalse($body);
        self::assertNotFalse($early);
        self::assertNotFalse($shell);
        self::assertNotFalse($delegated);
        self::assertTrue($body < $early && $early < $shell, 'the theme script runs before the shell is parsed — no flash');
        self::assertTrue($shell < $delegated, 'the delegated handlers come after the shell');
        self::assertStringContainsString('localStorage.getItem("milpa.admin.prefs")', $html, 'the early script reads the key');
        self::assertStringContainsString('var KEY="milpa.admin.prefs"', $html, 'the delegated one reads the same key');
        self::assertStringContainsString('document.documentElement.setAttribute("data-theme",t)', $html);
        self::assertStringContainsString('prefers-color-scheme: light', $html, 'system resolves to dark or light — the tokens want one of the two');
        self::assertStringContainsString('document.addEventListener("change"', $html);
        self::assertStringContainsString('querySelector(".mui-shell")', $html);
        self::assertStringContainsString('setAttribute("data-density"', $html);
        self::assertStringContainsString('searchParams.set("lang",code)', $html);
        self::assertStringContainsString('ROOT="/milpa/admin"', $html);
        self::assertStringContainsString('function inPanel(h){return h===ROOT||h.indexOf(ROOT+"/")===0||h.indexOf(ROOT+"?")===0;}', $html, 'a panel link is one whose href starts with the panel\'s route');
        self::assertStringContainsString('document.querySelector(".mui-shell")||document.body,links=root.querySelectorAll("a[href]")', $html, 'every anchor under the shell root, not only the sidebar\'s');
        self::assertStringContainsString('querySelector(".mui-sidebar__brand");if(b&&b.getAttribute("href")==="#"){b.setAttribute("href",ROOT);}', $html, 'the brand the sidebar paints as # is pointed at the panel root, so it is a panel link too');
        self::assertStringNotContainsString('.mui-sidebar a[href', $html, 'no sidebar-only rule');
        self::assertStringContainsString('localStorage.setItem(KEY', $html);
        self::assertStringContainsString('catch(e){}', $html, 'storage may be unavailable');
        self::assertStringNotContainsString('checkbox', $html, 'no remembered-filters control: nothing consumes it');
        self::assertStringNotContainsString('x-data', $html);
        self::assertStringContainsString('<html lang="en" data-theme="dark">', $html, 'the server still stamps a theme: the script only overrides it');

        self::assertStringContainsString('<html lang="es"', $page->withCatalog(new Catalog('es'))->render(''));
        self::assertStringContainsString('<html lang="en"', $page->render(''), 'the original is untouched');
    }

    /**
     * The panel's stylesheet: the document is painted (no browser canvas, no browser margin, the colour
     * scheme follows the theme); the raw HTML a section emits has a base look in the shell's tokens, laid in
     * a layer declared BEFORE the design bundle's so every mui-* piece wins over it; the shell's main is a
     * column whose body scrolls; and no colour is invented — tokens only.
     */
    public function testPaintsTheDocumentAndLaysABaseLayerUnderTheDesignBundle(): void
    {
        $html = (new AdminPage(AdminSettings::fromConfig(null), new Catalog()))->render('<div id="shell"></div>');
        $style = strpos($html, '<style>');
        $styleEnd = strpos($html, '</style>');
        $tokens = strpos($html, 'href="/milpa/admin/assets/tokens.css"');
        $bundle = strpos($html, 'href="/milpa/admin/assets/bundle.css"');
        self::assertNotFalse($style);
        self::assertNotFalse($styleEnd);
        self::assertNotFalse($tokens);
        self::assertNotFalse($bundle);
        self::assertTrue($styleEnd < $tokens && $tokens < $bundle, 'the panel\'s stylesheet comes before the design bundle: its layer is declared first, so the bundle\'s layers win over it');
        $css = substr($html, $style, $styleEnd - $style);

        self::assertStringContainsString('@layer admin.base;', $css, 'the base layer is declared on its own, first');
        self::assertMatchesRegularExpression('~@layer admin\.base;\s*@layer admin\.base\{~', $css, 'and filled right after');
        self::assertStringContainsString('html,body{margin:0;background:var(--bg);color:var(--text)}', $css, 'the canvas is painted with the page token, no browser margin');
        self::assertStringContainsString('html{color-scheme:dark}', $css);
        self::assertStringContainsString('html[data-theme="light"]{color-scheme:light}', $css, 'the colour scheme follows the theme the tokens key on');
        self::assertStringContainsString('.milpa-admin{font-family:var(--font-body);font-size:var(--text-sm);line-height:var(--leading-normal);color:var(--text)}', $css, 'the shell\'s voice is the base of everything inside');
        self::assertStringContainsString('.milpa-admin a{color:var(--accent-text);', $css, 'links speak in the accent token');
        self::assertStringContainsString('.milpa-admin a:visited{color:var(--accent-text)}', $css, 'visited like the link');
        self::assertStringContainsString('.milpa-admin :is(code,kbd,samp,pre){font-family:var(--font-mono);', $css);
        self::assertStringContainsString('.milpa-admin pre{margin:0;padding:var(--space-4);', $css, 'a code block is padded');
        self::assertStringContainsString('.milpa-admin dl{display:grid;grid-template-columns:max-content minmax(0,1fr);align-items:baseline;margin:0}', $css, 'a definition list is a two-column grid — no column gap: the row rules must meet');
        self::assertStringContainsString('.milpa-admin :is(dt,dd){margin:0;padding-block:var(--space-3);border-bottom:', $css, 'a row pads like a table cell');
        self::assertStringContainsString('.milpa-admin dt{padding-inline-end:var(--space-4);font-family:var(--font-mono);font-size:var(--text-2xs);text-transform:uppercase;', $css, 'in the voice of a table header, the gap carried by the label column');
        self::assertStringContainsString('.milpa-admin :is(h1,h2,h3,h4,h5,h6){margin:0;font-family:var(--font-heading);font-weight:var(--weight-medium);line-height:var(--leading-snug);color:var(--text)}', $css, 'the heading scale sets no tracking on every heading: a .mui-card__title keeps its own');
        self::assertStringContainsString('.milpa-admin h1{font-size:var(--text-2xl);letter-spacing:var(--tracking-tight)}', $css, 'only the two display sizes track tight, as the bundle\'s prose scale does');
        self::assertStringContainsString('.milpa-admin h2{font-size:var(--text-xl);letter-spacing:var(--tracking-tight)}', $css);
        self::assertStringContainsString('.milpa-admin h3{font-size:var(--text-lg)}', $css);
        self::assertStringNotContainsString('margin-block-start:var(--space-2)', $css, 'no spacer on headings: rhythm is the gap of the container they sit in');
        self::assertStringContainsString('.milpa-admin a{color:var(--accent-text);text-decoration:underline;', $css);
        self::assertStringNotContainsString('.milpa-admin a{color:var(--accent-text);text-decoration:underline;text-decoration-color:var(--border-strong);text-decoration-thickness:1px;text-underline-offset:.15em;border-radius', $css, 'the base link rule rounds nothing: a .mui-sidebar__brand keeps its corners');
        self::assertStringContainsString('.milpa-admin a:not([class]){border-radius:var(--radius-xs)}', $css, 'only a classless link gets the rounded focus ring');
        self::assertStringContainsString('.milpa-admin .admin-section__declared{font-family:var(--font-mono);font-size:var(--text-xs);color:var(--text-muted)}', $css, 'the attribution speaks mono, small and muted like the topbar chips');
        self::assertStringContainsString('.milpa-admin .mui-shell{height:100dvh}', $css, 'the panel is an app: the shell is the viewport');
        self::assertStringContainsString('.milpa-admin .mui-shell>.mui-topbar{min-width:0;overflow:hidden}', $css, 'the topbar yields: its content never widens the shell\'s column past the viewport');
        self::assertStringContainsString('.milpa-admin .admin-chip--principal{display:inline-block;max-width:28ch;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}', $css, 'a passkey id is wider than a laptop: the principal chip truncates (inline-block, because an ellipsis needs a block container, and the badge is inline-flex)');
        self::assertStringContainsString('.milpa-admin .mui-shell>.mui-shell__main{display:flex;flex-direction:column;min-height:0;overflow:hidden;padding:0}', $css, 'main is a column');
        self::assertStringContainsString('.milpa-admin .admin-section__header{flex:none;', $css, 'the header keeps its height');
        self::assertStringContainsString('.milpa-admin .admin-section__body{display:flex;flex-direction:column;flex:1 1 auto;min-height:0;overflow:auto;', $css, 'the body takes the rest and is what scrolls');
        self::assertStringContainsString('.milpa-admin .admin-section__body:focus-visible{outline:var(--focus-width) solid var(--focus);outline-offset:calc(-1 * var(--focus-width))}', $css, 'a keyboard scroller shows its focus — inside its box, because main clips');
        self::assertStringContainsString('.milpa-admin .admin-panel__header{padding-block:var(--space-4);border-bottom:', $css, 'a panel separates its header from its body');
        self::assertStringContainsString('.milpa-admin .admin-panel__body{display:grid;gap:var(--space-4);padding:var(--space-5)}', $css, 'and pads its body');
        self::assertDoesNotMatchRegularExpression('~#[0-9a-f]{3,8}\b~i', $css, 'no literal colour: every colour is a token');
        self::assertStringNotContainsString('opacity:.7', $css, 'muted is a token, not a transparency');
        self::assertStringNotContainsString('rgb', $css);
    }

    /**
     * greenhouse decisions/0211: `LiveBoot::html()` is the ONE emitter — the panel writes no runtime
     * `<script>` tag. What a guest DECLARED (its stylesheet, its module) is emitted once, in the one order
     * that works: styles, boot, local runtime, remote runtime, the guest's modules, Alpine LAST. The three
     * seed tags are written here and only here.
     */
    public function testTheRuntimeIsEmittedOnceByLiveBootWithWhatTheCompileDeclared(): void
    {
        $page = new AdminPage(new AdminSettings(route: '/panel'), new Catalog());
        $boot = new LiveBoot('/panel/live', 'live-abc', 'tok-1');
        $assets = new ClientAssets(
            scripts: ['/desktop/assets/c/conversation.js', '/desktop/assets/c/composer.js', '/desktop/assets/c/conversation.js'],
            styles: ['/desktop/assets/c/conversation.css'],
        );
        $seeds = LiveSeeds::of('the panel', ['admin.section' => 'agent'])
            ->merge(LiveSeeds::of('section «agent»', ['desktop.tab' => 'chat'], ['desktop.tab'], ['x' => ['template' => '{a}']]));

        $html = $page->render('<div id="shell"></div>', 'Agent', $boot, $assets, $seeds);

        self::assertSame(1, substr_count($html, 'src="/panel/assets/milpa-live.js" defer'), 'one local runtime');
        self::assertSame(1, substr_count($html, 'src="/panel/assets/milpa-live-remote.js" defer'), 'the remote runtime the panel used to omit');
        self::assertSame(1, substr_count($html, 'src="/panel/assets/alpine.min.js" defer'), 'one Alpine — it cannot be guarded, only emitted once');
        self::assertSame(1, substr_count($html, '/desktop/assets/c/conversation.js'), 'a module declared twice is emitted once');
        self::assertStringContainsString('<link rel="stylesheet" href="/desktop/assets/c/conversation.css">', $html);
        self::assertStringContainsString('<script id="milpa-live-boot" type="application/json">{"endpoint":"/panel/live","sessionId":"live-abc","csrfToken":"tok-1"}</script>', $html);

        $order = static function (string $needle) use ($html): int {
            $at = strpos($html, $needle);
            self::assertNotFalse($at, $needle);

            return $at;
        };
        self::assertTrue(
            $order('/desktop/assets/c/conversation.css') < $order('milpa-live-boot')
            && $order('milpa-live-boot') < $order('assets/milpa-live.js')
            && $order('assets/milpa-live.js') < $order('assets/milpa-live-remote.js')
            && $order('assets/milpa-live-remote.js') < $order('/desktop/assets/c/conversation.js')
            && $order('/desktop/assets/c/conversation.js') < $order('assets/alpine.min.js'),
            'styles → boot → local → remote → plugin modules → Alpine last',
        );
        self::assertTrue($order('assets/bundle.css') < $order('/desktop/assets/c/conversation.css'), 'a guest\'s stylesheet comes after the panel\'s own, unlayered, so the component keeps the look it declared');
        self::assertTrue($order('assets/alpine.min.js') < $order('<body'), 'the whole runtime lives in the head — every script deferred');

        self::assertStringContainsString('<script id="milpa-live-signals" type="application/json">{"admin.section":"agent","desktop.tab":"chat"}</script>', $html, 'the host\'s seeds and the section\'s, merged, in one tag');
        self::assertStringContainsString('<script id="milpa-live-persist" type="application/json">["desktop.tab"]</script>', $html);
        self::assertStringContainsString('<script id="milpa-live-computed" type="application/json">{"x":{"template":"{a}"}}</script>', $html);
        foreach (['milpa-live-signals', 'milpa-live-persist', 'milpa-live-computed', 'milpa-live-boot'] as $id) {
            self::assertSame(1, substr_count($html, 'id="' . $id . '"'), $id . ' is emitted once');
        }
    }

    /** With a boot but nothing seeded, the three tags are still there and empty — one truth, never a missing tag. */
    public function testTheSeedTagsAreAlwaysThreeAndTheDataCannotCloseItsScript(): void
    {
        $page = new AdminPage(AdminSettings::fromConfig(null), new Catalog());
        $seeds = LiveSeeds::of('section «x»', ['evil' => '</script><script>alert(1)</script>']);

        $html = $page->render('', '', new LiveBoot('/milpa/admin/live', 's', 't'));
        self::assertStringContainsString('<script id="milpa-live-signals" type="application/json">{}</script>', $html);
        self::assertStringContainsString('<script id="milpa-live-persist" type="application/json">[]</script>', $html);
        self::assertStringContainsString('<script id="milpa-live-computed" type="application/json">{}</script>', $html);

        $hostile = $page->render('', '', new LiveBoot('/milpa/admin/live', 's', 't'), null, $seeds);
        self::assertStringContainsString('<\/script>', $hostile, 'a seed value can never close its own tag');
        self::assertStringNotContainsString('</script><script>alert(1)', $hostile);
    }

    public function testErrorDocumentEscapesAndLinksHome(): void
    {
        $page = new AdminPage(AdminSettings::fromConfig(null), new Catalog());

        $html = $page->error(404, 'No section is named «<x>».');

        self::assertStringContainsString('<h1 class="mui-h1">404</h1>', $html);
        self::assertStringContainsString('&lt;x&gt;', $html);
        self::assertStringContainsString('href="/milpa/admin"', $html);
        self::assertStringContainsString('<title>404 · Milpa Admin</title>', $html);
    }
}
