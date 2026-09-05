# Upgrading

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
