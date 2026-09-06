# Upgrading

## 0.12.0 — a section may declare a whole VIEW; the panel emits one runtime and gains its live wire

**Nothing in the existing contract breaks.** `AdminSection`, `AdminSectionProvider`, the four lifecycle events and
their payloads keep their shapes; a section that names one component, or brings one definition plus its renderer,
behaves exactly as before. What is new is a second, wider shape and the machinery a page needs to run it
(greenhouse `decisions/0211`, slice 3 — the slice that retires the `<iframe>` of `decisions/0210`).

Requires **`milpa/live` ≥ 0.18** and **`milpa/live-web` ≥ 0.14** (the declared-view contract:
`ClientAssets`, `DeclaresClientAssets`, `CompositeComponentRegistry`, `LiveBoot::html()` with declared assets,
`MilpaLive.register`).

### New: `DeclaredView`

```php
AdminSection::ofView(
    id: 'agent', title: 'Agent', order: 60, group: AdminSection::GROUP_AGENT, icon: '◈',
    view: new DeclaredView(
        markup: '<milpa:desktop-tabs id="agent-tabs"/><milpa:desktop-conversation id="agent-conversation"/>',
        definitions: ['desktop-tabs' => $tabs, 'desktop-conversation' => $conversation],
        renderers:   ['desktop-tabs' => $renderer, 'desktop-conversation' => $renderer],
        props:       ['desktop-conversation' => ['session' => $id]],
        signals:     ['desktop.tab' => 'chat'],
        persist:     ['desktop.tab'],
        computed:    ['desktop.summary' => ['template' => '{desktop.turns} turns']],
    ),
);
```

`AdminSection`'s constructor gained a trailing `?DeclaredView $view = null`, and `component` gained a default of
`''` so a view can be declared without naming one. A section declares a view **or** a component — never both, and a
view carries no section `props` (they are per component); both mistakes throw `InvalidArgumentException` naming the
section. `AdminSection::hasView()` tells the shapes apart, next to the existing `isCustom()`.

### What changed underneath (visible only if you looked)

- **`ComponentBook` is now a composite registry.** `registry()` returns a
  `CompositeComponentRegistry` over the panel's layer plus one layer per section that brought components. Two
  sections binding one name to **different** definitions now throw `milpa/live`'s `ComponentNameConflictException`
  (naming the component and both sections) where the registry used to overwrite in silence. Two sections sharing
  the same INSTANCE, or two instances of a class with no state, are still one definition and still fine. The book
  also exposes `renderers()` (a `ComponentRendererRegistry`) and `ComponentBook::forSections()`.
- **`ComponentContext::$meta` is filled.** Every node of every page now receives `gate` (the label the topbar chip
  shows), `section` (the active section's id) and `query` (the request's query params) — constants on `AdminShell`.
  It was empty before; nothing read it.
- **`AdminShell::compose()` / `composeEmpty()`** return a `ShellOutput` (HTML + `ClientAssets` + `LiveSeeds`).
  `render()` and `renderEmpty()` are unchanged and still return the HTML string.
- **`AdminPage::render()`** gained three optional trailing parameters — `?LiveBoot $boot`, `?ClientAssets $assets`,
  `?LiveSeeds $seeds`. **Behaviour change:** the page no longer hand-writes `<script src=…milpa-live.js>` and
  `<script src=…alpine.min.js>` at the end of `<body>`. With a boot it emits, in `<head>`, everything
  `LiveBoot::html()` emits (declared styles → boot → `milpa-live.js` → `milpa-live-remote.js` → plugin modules →
  Alpine last, each `defer`, each URL once) plus the three seed tags; **without** a boot — the 404 and 500 error
  documents — it emits no runtime at all. If you rendered pages through `AdminPage` yourself, pass a boot.
  `milpa-live-remote.js`, which the panel never loaded before, is now on every page.
- **`AdminController`'s constructor** gained `CsrfGuardInterface $csrf` and `AdminSettings $settings` (it issues one
  `LiveBoot` per page). It is registered by `AdminPlugin::boot()`; only a host that built it by hand is affected.
- **A component that throws is contained.** Before, a section whose component threw while mounting took the whole
  request with it. Now the panel catches per **root node** and paints
  `<div class="mui-alert mui-alert--warning admin-section__failure" data-failed-component="…">` in its place, and
  the rest of the page stands. Declaration errors — an unknown component, a redefined one, a name conflict, a seed
  conflict — are still a 500 document that says which section: they are not a runtime failure, they are a contract
  the panel cannot honour.

### New route: `POST {route}/live`

`AdminPlugin::routes()` now returns **five** routes; the new one is the panel's live wire, third in the list
(`milpa_admin_live`), carrying the **same** effective middleware stack as every other panel route. Two consequences:

- A test that pinned four routes now sees five.
- If your app declared `admin.middleware`, the wire is behind it automatically. **Do not** exempt it: a wire outside
  the gate lets an unauthenticated caller act on any mounted component of any section.

It is served by the new `Milpa\Admin\Controllers\LiveController`, registered by `boot()`, over the same registry
the page compiled with. The page session comes from the request body (`sessionId`, echoed from the boot), never
from a cookie.

**One caveat, measured.** The wire verifies with the PANEL's secret (`admin.secret` → `live.secret` → derived from
this install), and a guest's renderer signs with its OWN package's (its key → `live.secret` → derived from *its*
install). An app that declares neither therefore gives host and guest two different derived keys, and a guest's
envelope comes back `400 invalid_signature` — loudly, per call, never silently. Declare one house key and the wire
serves the guest exactly as it serves the host:

```php
// config/app.php
'live' => ['secret' => getenv('MILPA_LIVE_SECRET') ?: ''],
```

The panel's own components are unaffected either way: they already sign with the panel's key.

### A second collision the panel now names

`Milpa\Admin\Components\RendererConflictException` (new) is thrown when two sections bind one component name to
different **renderers**. `ComponentNameConflictException` catches two sections disagreeing about the DEFINITION and
deliberately lets a shared definition through; each section could still bring its own renderer, and
`ComponentRendererRegistry::registerFor()` prepends, so the last section adopted would have repainted the first's
surface without a word. Now the panel refuses, naming the component and both sections, and the adoption is rolled
back. The rule is `milpa/live`'s, said for renderers: the same instance, or two instances of a stateless class, are
one renderer. Nothing to do unless two of your sections shared a name and disagreed about who paints it — which
never worked, it only failed quietly.

### New catalog keys

`view.failed`, `view.failed.why` (en/es) — the copy of the contained-failure region.

## 0.11.0 — the host for guests: the principal reaches the section, every section is attributed, the sidebar groups

**Nothing breaks.** The contract is additive: `AdminSection`'s constructor, `AdminSectionProvider`, the lifecycle
events and their payloads keep their signatures. What changed is what a section RECEIVES and what the host PAINTS
around it (greenhouse `decisions/0210`, sharpened by the first real guest — the Desktop's Agent section).

### What a guest receives now

- **`ComponentContext::$principal`** is filled. Before 0.11.0 the shell built the context every section mounts with
  from `componentId`, `locale` and `route` only, and the principal fed the topbar chip alone — so a guest that
  decides its state by the context was told nobody was signed in while the topbar said who. Now the context carries
  the actor the gate authenticated (the same string `signed in as …` shows, e.g. `passkey:<credential>`), or `null`
  when nobody is. Nothing to do if your component ignored the field; if it read it, it now works.
- `locale` and `route` are unchanged: the language the page answers in, and the panel's mount point.
- The props are unchanged: your declared `props` plus the reserved `query`.

### The header the host paints

Every section — the panel's own included — now renders under a header inside `main`, before the section's own
HTML: `<header class="mui-page-header admin-section__header">` with the title (`<h1 class="mui-page-header__title">`)
and the attribution `<span class="admin-section__declared" data-declared-by="<FQCN>">declared by <ShortName></span>`
(`declarada por …` in Spanish). The attribution is read from the catalogue (`SectionCatalogue::declaredBy()`),
never from the section. If your section painted its own «declared by» line, it is now said twice — drop yours.
The header is a component of its own (`admin-section-header`) with its signed envelope; it is NOT part of the
`admin.section.after_render` subject (`SectionRender::$html`), which stays the section's own HTML.

### The sidebar groups

- The sidebar is now the panel's own component, **`admin-sidebar`**, in place of `milpa/live`'s `dashboard-sidebar`
  in the shell composition. It paints one `.mui-sidebar__section` per distinct `AdminSection::$group`, in the order
  `admin` → `app` → `agent` → any other name alphabetically (case-insensitively, in its own alphabet: `año` among the
  a's, `Zeta` after `beta`), each headed by the catalog's label (`ADMIN / APP / AGENT`, `ADMIN / APP / AGENTE`; an
  unknown group is its own name uppercased in its own alphabet, `año` → `AÑO`). The literal `cultivo` heading the
  primitive painted is gone — it was the primitive's template, not a group.
- Each group is `<div class="mui-sidebar__section" role="group" aria-labelledby="<id>" data-group="<name>">` headed
  by `<span class="mui-sidebar__section-label" id="<id>">`. The heading id is **positional** — `milpa-admin-sidebar-group-0`,
  `-1`, … — never derived from the group's name, so two names that would sanitize alike (`my lab`, `my-lab`) never
  share an id. Match a group by `data-group`, not by the heading id.
- The panel now requires **`ext-mbstring`** (declared in `composer.json`): the sidebar uppercases and sorts a
  group's name in its own alphabet. Every PHP build the framework targets ships it; the suite's polyfill was dev-only.
- The item markup is the primitive's, unchanged: `<a class="mui-sidebar__item" href="…"( aria-current="page")?>` with
  the `mui-sidebar__item-icon` and `mui-sidebar__item-label` spans. Anything that matched an item keeps matching.
- **Icons now paint.** `AdminSection::$icon` reached the shell before but never the page: `milpa/live`'s sidebar
  primitive normalizes each item to `{key, label, href}` at mount, and its renderer reads the mounted meta. The
  panel's own sidebar paints the glyph before the label.
- **The brand links to the panel's route** directly (the primitive painted `#`, and the page's script re-pointed it).
- **`admin.shell.before_render`:** the `ShellRender::$items` you receive carry one more key per item, `group` (the
  section's). An item you add may name its `group` the same way; one that does not lists under `app`. The items stay
  flat — the sidebar groups them when it mounts, so you never rebuild the groups.
- If a subscriber replaced the composition (`ShellRender::$markup`) with the primitive `<milpa:dashboard-sidebar …/>`,
  it still works: the items are handed to both `admin-sidebar` and `dashboard-sidebar` as defaults.

### The order convention

`AdminSection::$order` sorts within a group; ties break by `id`. The panel OPENS on the first section by
(`order`, `id`) across every group, so a guest picks an order after the panel's own **10..40** (Plugins 10,
Routes 20, Settings 25, Stack 30, Dev tools 40) — greenhouse `decisions/0210` names 60 for the Desktop's Agent
section — unless it means to be the front page. Documented in the `AdminSection` docblock; no code changed.

### The component names the panel registers are its own

A section may **name** any component the panel registers — the eleven dashboard primitives, `admin-sidebar`,
`admin-section-header` — but a section that brings its own `definition` + `renderer` **under one of those names** is
now refused: `ComponentBook::adopt()` throws `ReservedComponentException`, and the panel answers 500 naming the
section and the name it tried to take (`section.conflict`, in the page's language). Before 0.11.0 the registry
overwrote silently, so such a section repainted every section naming that component — and, under
`admin-section-header`, replaced the host's attribution line on every page with its own markup. A custom component
under a name of your own (`inventory-table`, `desktop-agent`) is unaffected; so are two sections of one plugin that
share one custom name.

### New constants and catalog keys

`AdminSection::GROUP_ADMIN` / `GROUP_APP` / `GROUP_AGENT` (`'admin'`, `'app'`, `'agent'`; the default stays
`'app'`). Catalog: `nav.label`, `nav.group.admin`, `nav.group.app`, `nav.group.agent`, `section.declared_by`.
