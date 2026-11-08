# Minecraft Modrinth Plus

Fork of the official Pelican [`minecraft-modrinth`](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth) plugin by Boy132 & H1ghSyst3m (GPL-3.0).
Goal: only show mods/plugins that actually work on **your server** — right Minecraft version, right loader, and server-side compatible (no client-only mods).

Upstream baseline: `pelican-dev/plugins@194c1a39b8158b55c92cb1638e75cff51bdd4236`.

## Setup

Add `modrinth_mods` and/or `modrinth_plugins` to the _features_ of your egg.
The egg also needs the `minecraft` _tag_ and a tag matching a Modrinth loader name (e.g. `fabric`, `paper`, `neoforge`).

This plugin uses the same page slugs and the same `.modrinth-metadata.json` file as the official plugin, so it is a drop-in replacement:
**disable/uninstall the official `minecraft-modrinth` plugin first** — running both causes route collisions.

## Install

Pelican → Admin → Plugins → Import from URL:

```
https://github.com/DrMxrcy/pelican-plugins/releases/download/modrinth-plus-latest/modrinth-plus.zip
```

Updates are detected automatically via `update_url`.

## Credits

"Update all" (bulk update) and the "Scan folder" import of hand-added jars (Modrinth lookup by file hash, "Not on Modrinth" rows) are based on [YONN2222/pelican-minecraft-modrinth](https://github.com/YONN2222/pelican-minecraft-modrinth) (GPL-3.0).
