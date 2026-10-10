# Changelog

All notable changes to Embedr are documented here.  
Format: [SemVer](https://semver.org) — newest first.

---

## [0.4.1] — 2026-10-09

### Changed
- Custom PHP templates now receive an empty `PageArray` when a selector has no
  matches, allowing site-owned renderers to provide an accessible empty state.
- The built-in visual renderer keeps its existing empty-result HTML comment.

## [0.4.0] — 2026-07-28

### Added
- Added `Embedr.module.php` as the singular core API and configuration module.
- Added `Embedr::renderField()` for cached, template-driven rendering of
  FieldtypeTable, Repeater, PageTable, Page Reference, and custom iterable
  fields.
- Added the public `render()`, `getEmbed()`, `embeds()`, and `types()` API.

### Changed
- `ProcessEmbedr` is now an admin-only Process module and reads configuration
  from the core `Embedr` module.
- Renamed the single embed value object from `Embedr` to `EmbedrItem` to avoid
  colliding with the core module class.
- `TextformatterEmbedr` now reads shortcode settings and collections from
  `Embedr`.
- Existing database tables and stored embeds are reused without schema changes.

### Migration
- Installing `Embedr` copies compatible configuration values from legacy
  `ProcessEmbedr` installations.

## [0.3.1] — 2026-07-23

### Fixed
- Restored the `.module` filenames used by existing ProcessWire installations so module refreshes keep `ProcessEmbedr` and `TextformatterEmbedr` registered during upgrades.

## [0.3.0] — 2026-05-25

### Security
- **XSS** — `$embed->title` is no longer interpolated directly into `onclick=confirm(...)`. Moved to a `data-confirm` attribute read by a JS handler
- **XSS** — Shortcode copy button now reads value from `input.value` (DOM), not from an inline JS string literal
- **XSS** — `data-shortcode` attribute and `<code>` display are now escaped with `htmlspecialchars(ENT_QUOTES)`
- **XSS** — All output fields in `EmbedrRenderer` (`title`, `url`, `price`, `meta`, image URL) are now escaped with `htmlspecialchars()`
- **CSRF** — Delete links (`./delete/`, `../type-delete/`) now include a CSRF token; handlers call `$session->CSRF->validate()` before proceeding
- **Path leakage** — Exception messages (which may contain server paths) are no longer exposed in public HTML comments; full details are written only to the `embedr-errors` log

### Fixed
- **Template field** in the type form is now `required = false`, matching the documented behaviour that allows using the visual renderer without a template file
- **`TextformatterEmbedr::render()`** — fixed PHP 8.1 deprecation notice about implicit nullable type hints (`?Page`, `?Field`)

### Improved
- **Performance** — Debug mode is now cached in a `$debugMode` property (lazy init via `getDebugMode()` / `loadConfig()`); `getModuleConfigData()` is no longer called on every `set()` call or render
- **Config sync** — `openTag` / `closeTag` in `TextformatterEmbedr` are now automatically synced from `ProcessEmbedr` settings (single source of truth); duplicate config fields removed from the Textformatter config form
- **Selector sanitisation** — uses `textarea()` instead of `text()`, removing the 255-character truncation limit
- **CSS consistency** — list layout in `EmbedrRenderer` migrated from Tailwind utility classes to UIKit (consistent CSS framework across all layouts)

### Removed
- Dead method `EmbedrRenderer::getColumnClass()` (was always empty, never called)

---

## [0.2.13] — 2026-02-23

- README update
- Install fix

## [0.2.0] — 2026-01-03

- Initial public release
- Embed types, visual card renderer, custom PHP templates
- Shortcode system `((name))`
- `TextformatterEmbedr` for automatic tag replacement in text fields
- Debug mode with separate logs: `embedr-debug` / `embedr-errors`
