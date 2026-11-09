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
- Fresh install overwrites an untracked file with the same name

## v0.3.0 — (in progress)

### ✨ New
- Installing a mod now also installs the libraries it needs.
- (pending) Browse mods by category and sort by downloads, follows, or recent updates.
- One click shows which installed mods have no download for your current Minecraft version.

## v0.2.0 — (in progress)

### ✨ New
- (pending) Mods that only work on the player's game, like Sodium or Iris, no longer show up in the server's mod list.
- Mods that players also need on their own game are now clearly marked.
- Already-installed mods that do nothing on a server are now flagged as safe to remove.
- The mod list only offers projects that have a download for your exact Minecraft version and loader.

### 🐛 Fixed
- Enforce subuser file permissions on the Modrinth pages and actions
- saveModMetadata/removeModMetadata overwrite metadata when reading it fails

### ⚡ Improved
- Requests to Modrinth now identify the plugin, as Modrinth asks.

## v0.1.0 — 2026-11-08

### ⚡ Improved
- First installable release of Minecraft Modrinth Plus, a drop-in replacement for the official Modrinth plugin.

## v0.0.1 — (in progress)

### 🐛 Fixed
- (pending) Importing a new plugin build over an older one marks the plugin Crashed until re-enabled
- (pending) Dependencies installed automatically show no author in the Installed tab
- (pending) Installed tab row keeps its old state after installing another version from the Versions modal
