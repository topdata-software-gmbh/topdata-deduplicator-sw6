# AGENTS.md — Topdata Deduplicator SW6

## Project structure

- **Plugin class:** `src/TopdataDeduplicatorSW6.php` — namespace `Topdata\TopdataDeduplicatorSW6`, PSR-4 mapped from `src/`
- **Requires:** `shopware/core: 6.7.*`, PHP 8.2+
- **Depends on:** `topdata/topdata-foundation-sw6` (external, provides `AbstractTopdataCommand`, `CliStyle`, `CliLogger`)

## Key commands

| Command | Description |
|---|---|
| `bin/console topdata:deduplicator:brands` | Merge duplicate manufacturer brands |
| `bin/console topdata:deduplicator:brands --dry-run` | Preview only, no DB writes |

## DI & routing

- Services wired via `src/Resources/config/services.xml`
- Route attributes auto-imported via `src/Resources/config/routes.xml` (covers `src/Controller/**/*Controller*.php`)
- Commands tagged with `console.command` in services.xml

## Conventions

- Commands extend `AbstractTopdataCommand` from `topdata-foundation-sw6` (not `TopdataFoundationSW6` which is a Plugin class)
- Console output uses `CliLogger` with `CliStyle` — call `CliLogger::setCliStyle()` before any output

## State

- **No tests exist** — `tests/` is empty (`.gitkeep` only)
- Build artifacts (`src/Resources/public/`, `src/Resources/app/storefront/dist/`) are gitignored
- Plugin config: `src/Resources/config/config.xml`
- `_ai/` contains plans, reports, and technical decisions (managed by OpenCode)

## Controllers (boilerplate only)

- `AdminApiExampleController` — `GET /api/_action/topdata-deduplicator-sw6/example`
- `StorefrontExampleController` — `GET /deduplicatorsw6/example`
