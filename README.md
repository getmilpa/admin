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

The panel opens with two sections of its own — **Plugins** (what the app boots, and the capabilities it can grow)
and **Routes** (every route the booted plugins declared, with handler and per-route middleware) — and one more per
plugin that declares one.

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
matter), lists each section in the sidebar in the declared order, routes it at `/milpa/admin/s/<id>`, and renders
it inside the shell. A duplicate id is a loud 500 naming both plugins — never a silent "last one wins".

Two lifecycle pairs let another plugin extend a section or the shell without touching either:
`admin.section.before_render` / `after_render` (props, then HTML — both mutable) and
`admin.shell.before_render` / `after_render` (the composition and sidebar items, then HTML).

## What the app declares

Everything under the `admin` key of the app's config; every knob has a safe default.

| key | default | what it does |
|---|---|---|
| `admin.route` | `/milpa/admin` | the mount point |
| `admin.locale` | `en` | the panel's own copy — `en` or `es` |
| `admin.middleware` | `[LoopbackOnlyMiddleware::class]` | PSR-15 classes attached to **every** panel route, outermost first. The default answers only to loopback; declare your passkey/scope gate here. `[]` opens the panel on purpose. |
| `admin.secret` | `live.secret`, else derived | the HMAC secret that signs component state |
| `admin.title` | `Milpa Admin` | the brand in the sidebar and the document title |

Assets (design tokens, bundle, the `milpa/live-web` client runtime and Alpine) are served by the panel itself under
`{route}/assets/` — no build step, nothing copied into `public/`.

## Measured, not assumed

Every slice of this panel is proven on a fresh `composer create-project milpa/framework` app in the framework's
house (greenhouse `decisions/0200`, `evidence/0514`): a plugin written in the app, unknown to the panel, gets its
sections listed and rendered; an unknown section is 404; a non-loopback origin is 403; `/desktop` keeps serving.

## Lineage

`milpa/admin` up to `v0.5.2` was the panel of the original TeamX host — Symfony-shaped controllers, a gate wired to
that host's login, three shells. It never dispatched on a fresh framework app (greenhouse `evidence/0513`). From
`0.6.0` the package is the framework's own panel, rebuilt on the same mould as the Desktop (`milpa/desktop-app`):
PSR-7 in and out, Milpa Components all the way down, declared not scanned. The old line stays citable at its tag.

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
