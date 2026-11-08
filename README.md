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
.github/workflows/ci.yml       validate + pint + phpstan + zip artifacts on every push/PR
.github/workflows/release.yml  tag <id>-v<version> → GitHub release with zip + update.json
```

## Build locally

```sh
bash scripts/package.sh modrinth-plus          # → dist/modrinth-plus.zip
php scripts/validate-plugins.php
```

## Release

1. Bump `version` in `<id>/plugin.json`, commit.
2. `git tag <id>-v<version> && git push origin <id>-v<version>`

CI publishes a versioned release and refreshes the rolling `<id>-latest` release.
Install URL (stable): `https://github.com/<owner>/pelican-plugins/releases/download/<id>-latest/<id>.zip`
Each plugin's `update_url` points at `.../<id>-latest/update.json` (includes sha256), so the panel shows updates.

## License

GPL-3.0 — required, since plugins here are derived from GPL-3.0 upstream code.
