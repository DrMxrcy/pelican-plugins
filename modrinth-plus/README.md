# Minecraft Modrinth Plus

Fork of the official Pelican [`minecraft-modrinth`](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth) plugin by Boy132 & H1ghSyst3m (GPL-3.0).
Goal: only show mods/plugins that actually work on **your server** — right Minecraft version, right loader, and server-side compatible (no client-only mods).

Upstream baseline: `pelican-dev/plugins@194c1a39b8158b55c92cb1638e75cff51bdd4236`.

## Setup

Add `modrinth_mods` and/or `modrinth_plugins` to the _features_ of your egg.
The egg also needs the `minecraft` _tag_ and a tag matching a Modrinth loader name (e.g. `fabric`, `paper`, `neoforge`).

For datapacks, add `modrinth_datapacks` to the _features_ (the egg needs the `minecraft` _tag_, no loader tag).
They are installed into the main world's `datapacks/` folder, taken from `level-name` in `server.properties` (default `world` when the file or the key is missing). A `level-name` that is not a folder inside the server (absolute path, `.` or `..`, backslash, surrounding spaces) is refused: the page shows a warning and nothing is installed or removed until it is fixed.
If `server.properties` exists but can't be read (daemon error), installs, updates and uninstalls of datapacks are refused instead of guessing the world.
"Scan folder" and "Not on Modrinth" rows are not offered on the Datapacks page.

This plugin uses the same page slugs and the same `.modrinth-metadata.json` file as the official plugin, so it is a drop-in replacement:
**disable/uninstall the official `minecraft-modrinth` plugin first** — running both causes route collisions.

## Modpacks

Mods installed by a modpack through Modpacks Plus are listed as installed here, with a **From modpack &lt;name&gt;** badge, and can be updated one by one. Updating one, or installing another version of it from its versions list, warns first that it may break the pack; so does "Update all", which lists them. Modpacks Plus marks them with an extra `installed_as_modpack` key in `.modrinth-metadata.json`; this plugin keeps that key on install, update, pin and uninstall and never needs Modpacks Plus installed.

## Install

Pelican → Admin → Plugins → Import from URL:

```
https://github.com/DrMxrcy/pelican-plugins/releases/download/modrinth-plus-latest/modrinth-plus.zip
```

Updates are detected automatically via `update_url`.

## Updating

Use the **Update** button on Admin → Plugins. It appears when a release with a higher version than the installed one is published.

If you import a zip over an installed copy instead (Import from File / Import from URL), the panel can mark the plugin **Crashed**. A new release may add classes or enum values the old copy didn't have. The panel loads the new files in the same request that still has the old ones in memory, so they don't fit together. The new files are already in place, so open **Actions → Enable** on the plugin and it loads normally.

## Credits

"Update all" (bulk update), the Datapacks page and the "Scan folder" import of hand-added jars (Modrinth lookup by file hash, "Not on Modrinth" rows) are based on [YONN2222/pelican-minecraft-modrinth](https://github.com/YONN2222/pelican-minecraft-modrinth) (GPL-3.0).
