# Changelog (internal)

_Full work log — every item, including internal/dev work. The curated public changelog is CHANGELOG.md._

## v0.6.0 — (in progress)

### ✨ New
- (pending) Modpack (.mrpack) import

### ⚡ Improved
- (pending) Upstream the server-side filter as a PR to pelican-dev/plugins

## v0.5.0 — (in progress)

### ✨ New
- (pending) Persistent page banner when installed mods are incompatible with the current Minecraft version
- (pending) Changelog preview in the update dialog
- (pending) Pin a mod to its current version so no updates are offered

## v0.4.0 — 2026-11-08

### ✨ New
- Update every installed mod with one click.
- Mods you added by hand are recognized and can be updated from the panel.
- Browse, install, and update datapacks from the panel.

### 🐛 Fixed
- Installing a mod never overwrites a file you added yourself with the same name.
- Documented how to update the plugin and recover if the panel marks it Crashed.

## v0.3.0 — 2026-11-08

### ✨ New
- Installing a mod now also installs the libraries it needs.
- Browse mods by category and sort by downloads, follows, or recent updates.
- One click shows which installed mods have no download for your current Minecraft version.

### 🐛 Fixed
- Mods installed as dependencies now show their author.

## v0.2.0 — 2026-11-08

### ✨ New
- Mods that only work on the player's game, like Sodium or Iris, no longer show up in the server's mod list.
- Mods that players also need on their own game are now clearly marked.
- Already-installed mods that do nothing on a server are now flagged as safe to remove.
- The mod list only offers projects that have a download for your exact Minecraft version and loader.

### 🐛 Fixed
- Subusers can only install, update or remove mods when they have the matching file permissions.
- Your installed mods list stays intact even if the server briefly can't be reached during an install.
- Confirmed the Installed list refreshes after installing another version.
- The page header now follows your panel language.

### ⚡ Improved
- Requests to Modrinth now identify the plugin, as Modrinth asks.

## v0.1.0 — 2026-11-08

### ⚡ Improved
- First installable release of Minecraft Modrinth Plus, a drop-in replacement for the official Modrinth plugin.
