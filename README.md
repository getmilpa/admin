<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Admin

> The **administration panel** of the Milpa PHP framework — the surface where a human leaves the house ready for the agent. Composed of Milpa Components, event-driven, and extended by declaration: every installed plugin adds its own section without the panel knowing its name.

[![CI](https://github.com/getmilpa/admin/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/admin/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/admin)](https://packagist.org/packages/milpa/admin)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

## What it is

Milpa is a framework a human administers and an **agent operates**. Everything the agent can do is a governed
operation; everything the human sees is a projection of the same operations. The admin panel is the human's
projection: install capabilities, read the routes and middleware every plugin declared, stand up the services the
app needs, adjust configuration, read the ledgers of what the agent did.

Add one plugin to `config/plugins.php` and `/milpa/admin` exists:

```php
return [
    Milpa\Admin\AdminPlugin::class,
    // …your plugins
];
```

The panel opens with five sections of its own — **Plugins** (what the app boots, and the capabilities it can grow),
**Routes** (every route the booted plugins declared, with handler and per-route middleware), **Settings** (what the
app declared about the panel itself, key by key, with its source), **Stack** (every backing service the booted
plugins declared they need — image, ports, environment with secrets masked, the declaring plugin — and whether its
port answers on loopback) and **Dev tools** (the ledgers the agent writes, read-only) — and one more per plugin
that declares one.

## The one idea: a section is a component a plugin declares

```php
use Milpa\Admin\Section\{AdminSection, AdminSectionProvider};

final class InventoryPlugin implements PluginInterface, AdminSectionProvider
{
    public function adminSections(): array
    {
        return [
            // a dashboard primitive the panel already knows, with props
            new AdminSection(id: 'stock', title: 'Stock', component: 'metric-card',
                props: ['title' => 'Units in stock', 'value' => '1,204'], order: 30),

            // or a component you bring yourself — definition + renderer, registered under `component`
            new AdminSection(id: 'inventory', title: 'Inventory', component: 'inventory-table',
                definition: new InventoryComponent($repo), renderer: new InventoryRenderer(), order: 31),
        ];
    }
}
```

The panel discovers implementers by `instanceof` over the booted plugins, **at request time** (boot order does not
matter), lists each section in the sidebar under its group, routes it at `/milpa/admin/s/<id>`, and renders it
inside the shell under a header that says who declared it. A duplicate id is a loud 500 naming both plugins — never
a silent "last one wins". One prop name is **reserved**: `query` — the shell hands every active section the request's
query params under `props['query']`, so a section can read its own (`?session=<id>`, a filter) without the shell
interpreting it; an `AdminSection` that declares `props['query']` itself is refused at construction
(`InvalidArgumentException`) rather than silently overwritten on every request. The component **names** the panel
registers itself are reserved the same way — the dashboard primitives, `admin-sidebar`, `admin-section-header`: a
section may *name* one (`component: 'metric-card'`), but a section that brings its own `definition` under one is
refused (`ReservedComponentException`, a 500 that names the section and the name it tried to take) rather than
silently repainting every section that names it, the host's header included.

Two lifecycle pairs let another plugin extend a section or the shell without touching either:
`admin.section.before_render` / `after_render` (props, then HTML — both mutable) and
`admin.shell.before_render` / `after_render` (the composition and sidebar items, then HTML). A sidebar item a
subscriber adds names its `group` like a section does (`app` when it names none).

## Hosting a guest: what a section receives

A section is a guest of the panel, and the panel is the host — it tells the guest what it knows and paints what
the guest cannot know about itself (greenhouse `decisions/0210`, sharpened by the first real guest, the Desktop's
**Agent** section).

**The context.** Every section's component mounts with a `ComponentContext` the shell fills — the same one every
component of the page gets, under that component's own id:

| field | value |
|---|---|
| `componentId` | `milpa-admin-section-<id>` |
| `principal` | the actor the gate authenticated — the `id` of the `AuthContext` a gate left under the request attribute `milpa.auth` (`passkey:<credential>` behind app-runtime's passkey gate) — or **`null` when nobody is signed in**. It is exactly what the topbar's `signed in as …` chip shows, so a guest that decides its state by the principal (the Desktop's *signed-out* vs *live*) always agrees with the topbar |
| `locale` | the language the page answers in — the app's `admin.locale`, or the request's `?lang=` when the catalog carries it |
| `route` | the panel's mount point (`admin.route`, default `/milpa/admin`) — for a link back into the panel |

The props are the section's own `props` plus `query` (the request's query params). A guest reads the context and
its props; it never reads the request, and it never reads a cookie — the panel does not either.

**The header.** Above every section — the panel's own included — the host paints the section header: the title
(a catalog key translated, or the literal) and the attribution, read from the catalogue's record of which plugin's
`adminSections()` returned it, never from the section:

```html
<header class="mui-page-header admin-section__header" id="milpa-admin-header" …>
  <div class="mui-page-header__text">
    <h1 class="mui-page-header__title">Agent</h1>
    <span class="admin-section__declared" data-declared-by="Milpa\DesktopApp\DesktopAppPlugin">declared by DesktopAppPlugin</span>
  </div>
</header>
```

The short class name is shown (`declared by DesktopAppPlugin` · `declarada por DesktopAppPlugin`), the full class
travels in `data-declared-by`. A guest brings the region; the host brings the header, the sidebar and the topbar.

**The sidebar.** Sections list under their `group`, one heading per distinct value, in the house order — **`admin`**
(the panel's own: Plugins, Routes, Settings, Stack, Dev tools) → **`app`** (the default — a plugin's sections) →
**`agent`** (the agent's own surfaces: the Desktop's Agent section) → any other group name, alphabetically —
case-insensitively and in its own alphabet (`año` sorts among the a's, `Zeta` after `beta`). The headings come from
the catalog (`ADMIN / APP / AGENT` · `ADMIN / APP / AGENTE`); a group the catalog does not know is headed by its own
name uppercased in its own alphabet (`año` → `AÑO`). The glyph a section declares as `icon` is painted before its
label.

**The order.** Within a group, sections sort by `order` (lower first; ties break by `id`, alphabetically). The panel
**opens** on the first section in that same (`order`, `id`) order across every group, so the convention matters: the
panel's own take **10..40** (Plugins 10, Routes 20, Settings 25, Stack 30, Dev tools 40); a guest picks an order
**after those** — greenhouse `decisions/0210` names 60 for the Desktop's Agent section — unless it means to be the
page the panel opens on. A guest at order 10 named `agent` would tie with Plugins, win the tie by id, and become the
front page.

```php
new AdminSection(
    id: 'agent', title: 'Agent', component: 'desktop-agent',
    definition: new AgentGuestComponent(), renderer: new AgentGuestRenderer(),
    props: ['embed' => '/desktop?embed=1', 'open' => '/desktop', 'gate' => $gateLabel],
    order: 60, group: AdminSection::GROUP_AGENT, icon: '◈',
);
```

## Stack: a plugin declares the services it needs

A plugin that needs a container — a message hub, a database, a cache — says so as data, through
`Milpa\Runtime\Stack\StackProviderInterface` (`milpa/runtime`), instead of leaving it to a README:

```php
use Milpa\Runtime\Stack\{StackProviderInterface, ServiceDeclaration, PortMapping, EnvVar};

final class HubPlugin implements PluginInterface, StackProviderInterface
{
    public function services(): array
    {
        return [new ServiceDeclaration(
            name: 'mercure', image: 'dunglas/mercure',
            ports: [new PortMapping(container: 80, host: 3000)],
            env: [
                new EnvVar('SERVER_NAME', value: ':80'),
                new EnvVar('MERCURE_PUBLISHER_JWT_KEY', configKey: 'desktop.mercure.publisher_key', secret: true),
            ],
            summary: 'Pushes shell changes to the browser.',
        )];
    }
}
```

The **Stack** section lists one card per declared service with its state — `up` when `127.0.0.1:<host port>`
accepts a TCP connection, `down` when it refuses, `unknown` when no port is published — and
`GET {route}/stack/compose.yml` serves a compose file of every declared service (`text/yaml`, also linked from the
section). A secret is never shown and never inlined: the card masks it and the file projects `${NAME}` for the
operator to supply; a `configKey` the app's config holds is inlined, one it lacks becomes `${NAME}` too. The panel
starts nothing — declaring is the plugin's, running is the operator's (greenhouse `decisions/0201`).

## What the app declares

Everything under the `admin` key of the app's config; every knob has a safe default.

| key | default | what it does |
|---|---|---|
| `admin.route` | `/milpa/admin` | the mount point |
| `admin.locale` | `en` | the panel's own copy — `en` or `es` |
| `admin.middleware` | `[LoopbackOnlyMiddleware::class]` | PSR-15 classes attached to **every** panel route, outermost first. The default answers only to loopback; declare your passkey/scope gate here. Only a literally empty list `[]` opens the panel. |
| `admin.secret` | `live.secret`, else derived | the HMAC secret that signs component state |
| `admin.title` | `Milpa Admin` | the brand in the sidebar and the document title |

Assets (design tokens, bundle, the `milpa/live-web` client runtime and Alpine) are served by the panel itself under
`{route}/assets/` — no build step, nothing copied into `public/`.

## Settings: what was declared, with its source

The **Settings** section reads the `admin` key back to you — `route · locale · middleware · secret · title`, each
with a `default`, `config` or `rejected` badge — and never writes it: changing configuration is a governed
operation, not a form. A value the app declared and the panel refused (a locale the catalog lacks, a route or title
of the wrong type, a gate that is not a list) is `rejected`, with the effective value in the row and what was
declared next to it — never painted `default`. The secret shows only where it came from (`declared (admin.secret)`,
`declared (live.secret)`, `derived`), never the value. A fresh app with no `admin` key sees five defaults and the
exact snippet to paste. Above the table, **Panel preferences** — theme (`dark · light · system`) and density, this
browser only, applied instantly and never sent to the server; and a language override, sent as `?lang=` with each
request and never stored on the server — live in `localStorage` under `milpa.admin.prefs`.

The one rule the panel enforces rather than copies: **only a literally empty list opens the panel; any misdeclaration
falls back to loopback-only and Settings says so.** A non-string entry, an associative map, a value that is not a
list, an empty string, a class that does not exist, a class that is not a PSR-15 middleware — each makes every panel
route carry `[LoopbackOnlyMiddleware::class]`, the strict gate, never an open one and never the half that loads, and
the panel names what it received (a danger badge on the row, a notice in Settings, `gate: fallback` in the topbar).
The topbar always shows the gate in effect (`loopback · custom · passkey · open · fallback`) and the locale. Any
panel page accepts `?lang=en|es` to render in another catalog language for that request (greenhouse `decisions/0204`).

## Behind a passkey

The panel does not authenticate anyone — it **names its gate**. The gate is `PasskeyGateMiddleware`, which
`milpa/app-runtime`'s `PasskeyPlugin` registers under its own class name; it **requires `milpa/app-runtime >= 0.117`**,
and the ceremony it fronts — registration, sign-in, the session it mints, the store it checks — is that package's:
its README section [`## Passkey gate`](https://github.com/getmilpa/app-runtime#passkey-gate) is the reference. The
operator sequence, from a fresh app to a panel that opens only for your key:

1. **Declare the plugin and the relying party** — `Milpa\AppRuntime\Web\PasskeyPlugin::class` in `config/plugins.php`,
   and `'passkey' => ['rpId' => 'localhost']` in `config/app.php` (the host the browser is on; WebAuthn needs
   `https://` or `localhost`). Without `rpId` the plugin mounts nothing.
2. **Register the key** — open `GET /webauthn/enroll`, press *Register with passkey*, touch the key; the page prints
   the **credential id**. Registering grants nothing yet.
3. **Root it in `config/identity.php`** — the file the running app reads and never writes. On a first run, a house
   that opted in with `['bootstrap' => true]` and holds no `rooted` list yet may root your signing key once, with the
   scope `identity:enroll` needs over `http`/`mcp` (sealed after the first; on the CLI `--sign` alone authorizes):
   ```bash
   php bin/coa identity:bootstrap --scopes=identity:enroll --sign
   ```
   Then declare the credential id as rooted — `identity:enroll` refuses a fingerprint this list does not hold:
   ```php
   <?php return ['rooted' => ['<credential id>']];
   ```
4. **Enroll the credential with the panel's scope** — a governed, signed operation:
   ```bash
   php bin/coa identity:enroll --fingerprint=<credential id> --scopes=milpa.admin --sign
   ```
5. **Name the gate** in `config/app.php`:
   ```php
   'admin' => ['middleware' => [\Milpa\AppRuntime\Web\PasskeyGateMiddleware::class]],
   ```
6. **Sign in** — `GET /milpa/admin` answers `302` to `/webauthn/signin?next=/milpa/admin`; *Continue with a passkey*,
   touch, and the page posts to `/webauthn/authenticate`, which mints the session, sets the cookie and sends the
   browser back to the panel, `200`.

From step 5 on, **identity replaces loopback-only**: a request from the LAN is no longer refused for its address — it
gets `302` to sign-in (a JSON client `401`) until it carries a live session, `403` when the session lacks the scope,
and the panel when it has it; loopback gets the same treatment. The gate reads the session cookie against its own
store and leaves the authenticated `AuthContext` on the request under the attribute `milpa.auth`. The panel reads
only that attribute — no cookie, no session id — and the topbar says `signed in as <actor id>` on every panel page,
the index and each section alike; when that class is the whole stack, Settings and the gate chip name it `passkey`
instead of `custom`, and the empty state's snippet offers the same `admin` key with the passkey gate as the
alternative to loopback-only. `milpa/admin` takes no dependency on `milpa/auth` or `milpa/app-runtime` for any of it
(greenhouse `decisions/0206`).

## Dev tools: the ledgers the house already writes, read — nothing runs

The **Dev tools** section reads what the agent already wrote and adds nothing of its own. The **agent ledger** is
resolved the way the `agent` operation resolves it: an `EventStoreInterface` registered in the container first,
then a registered `SessionStore`, then the file `var/agent-sessions.jsonl` under the app root — and the page says
which one it is reading (the class name or the path) under the sessions table and in the not-available notice.
The ledger is read **once** per page: `replayAll()` when a store gives it, the section's own tolerant line reader
when it is the file — the same one-JSON-object-per-line format `FileEventStore` writes, except that a line that
does not decode is counted («N line(s) could not be read») and skipped, never a failure that blanks every block.
From that one pass: the **sessions** — every stream that opened with `session.started` (a stream without one is
counted, not listed), reduced with `SessionReducer`, newest first, the table capped at the newest 50 with «N
older not listed» — each with its state derived from the stream (`running · waiting · done`, and `interrupted`
when the end fact says so or follows a closed answer window), its goal and mode, the provider's own token count
in/out (a call counts only when its usage carries an integer `prompt_tokens` or `completion_tokens`; `not
reported` when none did — absent is not zero) and what it waits on, the pending question inline; the **debt
signals** (`session.debt_signaled`) grouped by their four real kinds, each glossed in one line and listed even at
zero; the **evidence** (`session.evidence_recorded`); and a **log** — the file the app declares under
`admin.log`, absolute or relative to the app root and **confined to it** (`..` and symlinks resolved; outside is
a notice, not a read), tailed to its last 200 lines within its last 1 MiB. With no declaration the section says
so and invents no path; without a kernel no root is known, so a relative path is never resolved against the
working directory and nothing is read; a missing or unreadable file is a notice that names it, and never blanks
the other blocks. A session's id opens its **timeline** inside the section (`{route}/s/devtools?session=<id>`):
`SessionProjector` goes first and paints what it paints — turns, tool calls, todos, questions and answers, the goal
changing, the end — and only what it maps to null AND is in the section's bounded audit list is painted locally:
the opening, each debt signal with its context, the closure verdict, trial runs / promotions / discards, executed
operations (operation · executed_by/authorized_by · arguments digest) and paused/resumed sequences. The signed
state envelope of the section carries only `{view, session}`: the ledgers are a projection re-read on every
mount, never signed and sent to the browser. Every link inside the section carries `?lang=` when the request
overrode the locale.

The coupling to `milpa/agent` is soft: without the package the section degrades to a notice naming it, and the
log block still reads. There is no form, no button and no command box anywhere in it — every mutation of the
house is a governed operation, and this section only reads (greenhouse `decisions/0205`). One rule it
introduced for every section: the request's query params reach the active section as `props['query']`, which
is why that prop name is reserved.

## Measured, not assumed

Every slice of this panel is proven on a fresh `composer create-project milpa/framework` app in the framework's
house (greenhouse `decisions/0200`, `evidence/0514`): a plugin written in the app, unknown to the panel, gets its
sections listed and rendered; an unknown section is 404; a non-loopback origin is 403; `/desktop` keeps serving.

## Lineage

`milpa/admin` up to `v0.5.2` was the panel of the original TeamX host — Symfony-shaped controllers, a gate wired to
that host's login, three shells. It never dispatched on a fresh framework app (greenhouse `evidence/0513`). From
`0.6.0` the package is the framework's own panel, rebuilt on the same mould as the Desktop (`milpa/desktop-app`):
PSR-7 in and out, Milpa Components all the way down, declared not scanned. The old line stays citable at its tag.

## Upgrading

See [UPGRADING.md](UPGRADING.md) — every change is additive; the notes say what a guest and a shell subscriber gain,
and what to check if you extended the shell.

## Develop

```bash
composer install
vendor/bin/phpunit --testsuite Admin
vendor/bin/phpstan analyse src
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## License

Apache-2.0 — (c) Rodrigo Vicente - TeamX Agency. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

---

Milpa is designed, built, and maintained by [Rodrigo Vicente - TeamX Agency](https://teamx.agency).
