# Changelog

## [0.3.1](https://github.com/getmilpa/admin/compare/v0.3.0...v0.3.1) (2026-07-30)


### Bug Fixes

* require milpa/console ^0.2 so this package installs beside the family ([5811430](https://github.com/getmilpa/admin/commit/5811430ee630f7002be2e200c1c68a720aa8435f))

## [0.3.0](https://github.com/getmilpa/admin/compare/v0.2.2...v0.3.0) (2026-07-30)


### ⚠ BREAKING CHANGES

* the section and state abstraction, and the TUI screen, moved to `milpa/console`. `Milpa\Admin\Section\AdminSection` is now `Milpa\Console\Section\Section`, `AdminSectionProvider` is `SectionProvider`, `AdminTuiScreen` is `ConsoleScreen`, and the contract methods `adminSections()` / `adminSectionStates()` are `sections()` / `sectionStates()`. A plugin contributing admin sections must update its imports and those two method names.

### Features

* the shell moves to milpa/console; admin keeps the web panel ([2c80681](https://github.com/getmilpa/admin/commit/2c80681dc32547dcac18a8a7ddbdfc4228458ef7))

## [0.2.2](https://github.com/getmilpa/admin/compare/v0.2.1...v0.2.2) (2026-07-30)


### Bug Fixes

* catch up with the family's published versions ([d32fd96](https://github.com/getmilpa/admin/commit/d32fd96a08acf402559ebbbb85b4bf50991b675d))

## [0.2.1](https://github.com/getmilpa/admin/compare/v0.2.0...v0.2.1) (2026-07-29)


### Bug Fixes

* state without a menu is still inspectable ([de871b7](https://github.com/getmilpa/admin/commit/de871b729d69b5cf9dcda8d961420941bf2be47e))

## [0.2.0](https://github.com/getmilpa/admin/compare/v0.1.0...v0.2.0) (2026-07-29)


### ⚠ BREAKING CHANGES

* hosts using the Settings section must register a `Milpa\Admin\Contracts\StorageRootSource` in the container (or export `MILPA_ADMIN_SETTINGS_PATH`). Reading or writing settings without either now throws instead of resolving to a path the package invented.

### Features

* the host says where settings live, the package stops guessing ([9efa710](https://github.com/getmilpa/admin/commit/9efa7107191a43511c404f0b80631467eecccd3a))

## 0.1.0 (2026-07-28)


### Features

* milpa/admin initial public release ([1bc6921](https://github.com/getmilpa/admin/commit/1bc6921be571a0bd81d1c41d3fd444bfed6468af))


### Bug Fixes

* declare milpa/live, which this package was using without asking ([ca5c329](https://github.com/getmilpa/admin/commit/ca5c32965bc85875ccca8b0428d578da07ad56d3))


### Miscellaneous Chores

* release 0.1.0 ([5ced033](https://github.com/getmilpa/admin/commit/5ced033cf80d566aa6fd37dbcd5b19f35494526b))
