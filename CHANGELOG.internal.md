# Changelog (internal)

_Full work log — every item, including internal/dev work. The curated public changelog is CHANGELOG.md._

## v0.5.0 — (in progress)

### ✨ New
- Persistent page banner when installed mods are incompatible with the current Minecraft version
- Changelog preview in the update dialog
- Pin a mod to its current version so no updates are offered
- (pending) See who is online on your Minecraft server right from the panel.
- (pending) Charts show how many players were online over the last day, week and month, with peak times.
- (pending) Every player gets a profile with playtime, past sessions and in-game stats.
- (pending) Player history also includes joins and leaves recorded in the server log, even from before the plugin was installed.
- (pending) Kick, ban, whitelist and op players without typing console commands.
- (pending) Browse Modrinth modpacks and install one onto your server, with an automatic backup first.
- (pending) Install CurseForge modpacks too, using the official server pack when one exists.
- (pending) FTB and ATLauncher modpacks can be installed as well.
- Mods that came with a modpack show up in the Mods page, ready to update one by one.
- One page shows who is online and how busy every Minecraft server is.
- (pending) Installing a modpack can switch your server to the mod loader the pack needs.
- (pending) Update or change modpacks while your worlds, whitelist and bans stay safe, and get told when a pack update is out.
- (pending) Bigger modpacks install when the server has room: the pack size limit follows the server's disk limit and free space.

### 🐛 Fixed
- Client-only toggle is an unlabeled eye icon and shown client-only mods carry no badge on the All tab

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
