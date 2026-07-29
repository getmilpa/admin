# Changelog

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
