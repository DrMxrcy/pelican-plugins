# pelican-plugins

Plugins for [Pelican Panel](https://pelican.dev). One folder per plugin; folder name = plugin `id`.

| Plugin | What |
|---|---|
| [`modrinth-plus`](modrinth-plus) | Fork of the official Modrinth plugin that only shows server-compatible mods/plugins |

## Layout

```
<plugin-id>/            plugin source (plugin.json, src/, lang/, config/)
scripts/package.sh      build dist/<id>.zip (+ update.json)
scripts/validate-plugins.php
scripts/check.sh        local quality gate (Docker): validate + pint + phpstan + plugin tests
scripts/run-plugin-tests.sh    run every plugin's tests/ with the panel's Pest
.github/workflows/ci.yml       validate + pint + phpstan + plugin tests + zip artifacts on every push/PR
.github/workflows/release.yml  tag <id>-v<version> → GitHub release with zip + update.json
```

## Build locally

```sh
bash scripts/package.sh modrinth-plus          # → dist/modrinth-plus.zip
php scripts/validate-plugins.php
```

## Checks

```sh
bash scripts/check.sh            # everything: validate, pint, phpstan, test
bash scripts/check.sh test       # one stage: validate | pint | phpstan | test
PELICAN_REFRESH=1 bash scripts/check.sh   # re-clone the panel first
```

Needs only Docker: runs in a PHP 8.4 image built from `scripts/docker/Dockerfile`. The
`phpstan` and `test` stages clone Pelican Panel into the git-ignored `./pelican` (kept
between runs; `PELICAN_REFRESH=1` re-clones), `composer install` it, and stage every plugin
into `pelican/plugins/<id>/`, the same way CI does. Exit code 0 = green.

## Plugin tests

Each plugin may have a `tests/` folder (never shipped: `package.sh` excludes it).
`scripts/run-plugin-tests.sh <panel-dir>` runs it with the panel's Pest, using a generated
`pelican/phpunit.plugins.xml` (panel test env: sqlite `database/testing.sqlite`, array
cache/session, fixed `APP_KEY`) and a bootstrap that autoloads `<Namespace>\` → `src/` and
`<Namespace>\Tests\` → `tests/` from each `plugin.json`. The panel itself does not load
plugins under test, so:

- **Pure unit tests** (no Laravel): extend `PHPUnit\Framework\TestCase`.
- **Laravel-booted tests**: extend the plugin's base `tests/TestCase.php` (e.g.
  `DrMxrcy\ModrinthPlus\Tests\TestCase`). It extends the panel's `App\Tests\TestCase` and
  registers the plugin config (`config('<id>.…')`) and translations (`trans('<id>::…')`),
  so `Http::fake()`, panel models and factories work. Add
  `use Illuminate\Foundation\Testing\RefreshDatabase;` for tests that touch the database.

Write class-based tests (`test_*` methods) so PHPStan, which also analyses `tests/`, stays
clean. A new plugin copies `tests/TestCase.php` and changes the namespace/`PLUGIN_ID`.

## Release

1. Bump `version` in `<id>/plugin.json`, commit.
2. `git tag <id>-v<version> && git push origin <id>-v<version>`

CI publishes a versioned release and refreshes the rolling `<id>-latest` release.
Install URL (stable): `https://github.com/<owner>/pelican-plugins/releases/download/<id>-latest/<id>.zip`
Each plugin's `update_url` points at `.../<id>-latest/update.json` (includes sha256), so the panel shows updates.

## License

GPL-3.0 — required, since plugins here are derived from GPL-3.0 upstream code.
