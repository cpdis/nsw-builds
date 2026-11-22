# NSW Builds

A map of what's actually getting built in NSW: every project followed from DA to construction certificate to occupation certificate, with cost, storeys, units and (company) builder names. Fed only by the NSW Planning Portal's keyless open data API.

Data © NSW Department of Planning, Housing and Infrastructure, [CC BY 4.0](https://data.nsw.gov.au/data/dataset/online-da-data-api).

## Stack

Laravel 12 Livewire starter kit (Livewire 4, Fortify auth, Flux + Flux Pro, Vite), Postgres 16 + PostGIS 3, MapLibre GL.

The map is a JS island: `<x-map>` (`resources/views/components/map.blade.php`) wraps MapLibre in `wire:ignore` and the Alpine component `nswMap` (`resources/js/app.js`). Livewire pushes data into it with `$this->dispatch('map-features', features: $geojson)`.

## Layout

| Path | What |
| --- | --- |
| `app/Sources/Nsw/` | Everything NSW-specific: API client, feed enum, record mapper, normalisers, builder-name classifier, upserter. A WA source can sit alongside later. |
| `app/Console/Commands/NswSync.php` | `php artisan nsw:sync`, scheduled 03:00 Sydney time in `routes/console.php`. |
| `app/Jobs/SyncNswFeedPage.php` | One page per queued job (stays under Laravel Cloud's 90 s cap); each job queues the next. |
| `database/migrations/2026_11_22_*` | PostGIS, `builders`, `applications`, `projects`, `project_events`, `sync_runs`. |
| `docs/data-spike.md` | Milestone 1 findings: field lists, volumes, throttling, DA link rate. |
| `docs/hosting.md` | PostGIS on Laravel Cloud, and the fallback. |

## Data model

- **applications**: one row per PAN per feed (`da`, `cdc`, `cc`, `oc`), raw JSON kept. `lot_keys text[]` (GIN) and `geom Point/4326` (GiST) feed the linker.
- **projects**: one per site, point geometry, derived stage (`lodged`, `exhibition`, `approved`, `construction`, `complete`). Never deleted.
- **project_events**: links an application to a project with `link_method` (`seed`, `explicit`, `lot_plan`, `address`, `proximity`) and `confidence` (1.0, 0.9, 0.7, 0.5). Re-linking moves the event.
- **builders**: deduped on a normalised legal name. Only names that are clearly companies (Pty Ltd, Ltd, ...) are publishable; everything else is `suppressed` everywhere, free and Pro.

## Local setup

```bash
# Postgres with PostGIS, e.g. on macOS:
brew install postgresql@16 postgis && brew services start postgresql@16
createuser -s nsw && createdb -O nsw nsw_builds && createdb -O nsw nsw_builds_test

# Flux Pro is a private package. Store your licence once (writes the gitignored auth.json):
composer config http-basic.composer.fluxui.dev you@example.com YOUR-FLUX-KEY

composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate
composer run dev
```

## Syncing

```bash
php artisan nsw:sync                                # all feeds, updated since last good run minus 2 days
php artisan nsw:sync cc --since=2026-11-01
php artisan nsw:sync da --from=2023-01-01 --to=2023-12-31 --council="CITY OF PARRAMATTA" --now
```

`--now` runs pages inline instead of queueing, for backfills from a Mac or GitHub Actions. Otherwise run `php artisan queue:work`.

The API is header-driven: `PageSize`, `PageNumber` and a JSON `filters` header, no key. Defaults are 1,000 rows a page with a 2 s pause (`config/nsw.php`).

## Tests

`php artisan test` runs against Postgres (`nsw_builds_test`), since the migrations need PostGIS. CI uses the `postgis/postgis` image.
