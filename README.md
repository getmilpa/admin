<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Admin

> The **administration panel** of the Milpa PHP framework — a section shell that discovers what your app can actually do, server-rendered, no JavaScript required.

[![CI](https://github.com/getmilpa/admin/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/admin/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/admin.svg)](https://packagist.org/packages/milpa/admin)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

Add one plugin to your app and `/milpa/admin` exists: a navigation, a settings form, a plugin
manager, and a route inspector — behind your own auth, rendered on the server.

## Install

```bash
composer require milpa/admin
```

```php
// config/plugins.php
return [
    Milpa\Admin\AdminPlugin::class,
    // ...
];
```

## The one idea

**The panel knows no section by name.** It asks every booted plugin for its sections and renders
what it gets back:

```php
final class BillingPlugin implements PluginInterface, AdminSectionProvider
{
    public function adminSections(): array
    {
        return [new AdminSection('billing', 'Facturación', '/milpa/admin/billing', 40)];
    }
}
```

That is the whole extension point. Your section appears in the navigation, in order, with no change
to this package — and the same list drives the terminal shell (`coa:admin`, `coa:tui`), so a
section you add is a section you can also inspect without a browser.

## What it ships with

| Section | What it does |
|---------|--------------|
| **Settings** | The site configuration, as a form generated from the schema of a governed tool — with CSRF, validation, and redisplay of what you typed when it is rejected. |
| **Plugins** | What your app has, what boots, and a button per row. It drives `milpa/plugin`'s operations, so the panel and `coa plugins.list` cannot disagree. |
| **Sistema** | The route table, read-only. |

## What a host has to provide

Nothing is assumed and nothing is faked. A capability you did not wire simply does not appear —
there are no dead controls (that is a rule, not a habit: see ADR-0005, *surface honesty*).

| You register | You get |
|--------------|---------|
| `SessionStore` + `milpa/auth`'s scope middleware | The panel at all — every section is behind `milpa.admin`. |
| `PluginRegistryInterface` | The **Plugins** section: list, enable, disable. |
| `PluginInstallerInterface` | Install, update and remove on top of it. Without it those three operations do not exist, so no surface renders a button that fails when pressed. |
| `RouteTableSource` | The **Sistema** section. Every host builds its route table differently; this port is how yours gets in. |
| `StorageRootSource` | Where the panel keeps its settings. **Required** if you use the Settings section — see below. |

### Where settings are stored

The panel writes one file, `<your storage root>/milpa-admin/settings.json`, and it will not guess
where that root is:

```php
final class MyStorageRoot implements Milpa\Admin\Contracts\StorageRootSource
{
    public function storageRoot(): string
    {
        return __DIR__ . '/../storage';   // wherever YOUR app keeps mutable state
    }
}
```

Register it in the container before the panel boots, and `AdminPlugin::boot()` picks it up. If
nothing is registered, reading or writing settings throws with the name of the port it needs —
`MILPA_ADMIN_SETTINGS_PATH` also overrides the whole path if you want to point at one exact file.

Until `0.2.0` the path was computed by counting directories up from the package's own source file.
That worked while the code lived inside a host and broke the moment it did not: installed through
Composer it resolved to somewhere **inside `vendor/`**, a directory the next `composer install` can
delete. A package cannot know the root of whoever installs it — so now it asks.

## No JavaScript required

Every control is a real `<form>` with a real submit. The panel enhances with JS when it is there and
works identically when it is not — which matters most at the exact moment you need it: turning off
the plugin that broke the page is not the time to depend on that page's JavaScript.

Two more decisions worth knowing:

- **Installing is not consenting to run.** A freshly installed plugin arrives **disabled**.
- **A plugin declared in your code cannot be removed from the panel** — it would delete files your
  own source still names. The panel says so, and offers to disable it instead.

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.6** · [`milpa/auth`](https://packagist.org/packages/milpa/auth) **^0.3** · [`milpa/command`](https://packagist.org/packages/milpa/command) **^0.3** · [`milpa/data`](https://packagist.org/packages/milpa/data) **^0.2**
- [`milpa/http`](https://packagist.org/packages/milpa/http) **^0.1.5** · [`milpa/http-symfony`](https://packagist.org/packages/milpa/http-symfony) **^0.1** · [`milpa/runtime`](https://packagist.org/packages/milpa/runtime) **^0.7**
- [`milpa/plugin`](https://packagist.org/packages/milpa/plugin) **^0.5** — the operations the Plugins section drives
- [`milpa/live-web`](https://packagist.org/packages/milpa/live-web) **^0.2** · [`milpa/live-tui`](https://packagist.org/packages/milpa/live-tui) **^0.3** · [`milpa/tool-runtime`](https://packagist.org/packages/milpa/tool-runtime) **^0.9**

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues
via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © Rodrigo Vicente - TeamX Agency.

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=admin)**.
