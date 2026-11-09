# Modpacks Plus

Browse [Modrinth](https://modrinth.com) and [CurseForge](https://www.curseforge.com) modpacks and install one onto a Minecraft server, switching
the server to the pack's loader (Fabric, Forge, NeoForge, Quilt) and Minecraft version when they differ.

## What it does

- A **Modpacks** server page with a **Modrinth** and a **CurseForge** source: search modpacks filtered by
  the server's Minecraft version and loader, with categories and sort; a pack detail view (description,
  gallery, versions). See *CurseForge* below for the API key and the opt-in mirror.
- Versions for another Minecraft version or loader offer **Switch to &lt;loader&gt; &lt;version&gt; and install**
  (admins, or everyone with the install permissions when the setting allows it; see *Loader switching*).
- An install runs as chained short queue jobs:
  1. **prepare** — download the `.mrpack` on the panel, verify its sha512, read and validate the index;
  2. **backup** — a locked backup through Pelican's backup service (never deletes an old backup).
     Only the backup whose uuid the install stored is trusted; after a crash between creating it and
     storing its uuid, only a locked backup made after the install started and carrying the install's
     random nonce in its name is accepted;
  3. **move aside** — `mods/`, `config/`, `defaultconfigs/`, `kubejs/`, `scripts/` go to
     `.modpacks-plus/previous-<date>/` (nothing is deleted);
  4. **download** — server-side files land in `.modpacks-plus/staging/<install>/`, are verified there
     and only then renamed into place (see "Downloads" below);
  5. **overrides** — `overrides/` then `server-overrides/` from the archive;
  6. **finalize**.
- A live step list and log, a cancel button (stops between steps / download batches) and a final summary.
  The last installed pack shows as a banner on the page.

## Safety rules

Checked before anything is written to the server; archive entries are checked again when overrides are applied.

- Every path is relative: no `..`, no leading `/` or `\`, no backslashes, no drive letter, no
  percent-encoding, no control characters.
- Worlds (`level-name` from `server.properties`, `world`, `*_nether`, `*_the_end`, any top-level
  folder holding `level.dat`), `ops.json`, `whitelist.json`, `banned-players.json`, `banned-ips.json`,
  `usercache.json`, `server.properties` and `.modpacks-plus/` are never moved or written. A pack file
  aimed at one rejects the pack; an override aimed at one is skipped and listed in the summary.
- Downloads must be HTTPS from the pack's provider hosts (no credentials, no custom port): Modrinth packs
  `cdn.modrinth.com`, `github.com`, `raw.githubusercontent.com`, `objects.githubusercontent.com` or
  `gitlab.com`; CurseForge packs `edge.forgecdn.net`, `mediafilez.forgecdn.net` or `cdn.modrinth.com`. The first allowlisted
  URL of a file is used; a file without one rejects the pack.
- **Downloads.** The node (Wings) follows redirects on its own pulls, so it is only ever asked to pull
  files on `cdn.modrinth.com` whose sha512 Modrinth's own hash lookup knows at exactly that URL; their
  size is checked after the pull. Every other file (other hosts, or a lookup miss) is fetched by the
  panel, with every redirect hop checked against the allowlist and a byte cap, hashed there
  (sha512, else sha1) and only then written to the server. The `.mrpack` itself is fetched the same
  way and hashed (sha512) on the panel. A mismatch fails the install; nothing unverified is ever
  renamed out of `.modpacks-plus/staging/`.
- Caps (config / env): 1000 files, 256 MB per file, 100 MB archive, 4 GB total, 20 000 archive entries,
  32 MB per panel-fetched file (`max_panel_fetch_bytes`; such files are held in the worker's memory),
  16 MB per override file (`max_override_bytes`). A pack over a cap is refused before anything is written.
- Overrides only a game client uses — `resourcepacks/`, `shaderpacks/`, `screenshots/`, `options.txt`,
  `optionsof.txt`, `optionsshaders.txt` — are skipped whatever their size and listed in the summary
  (after the same path checks as every other entry).
- The daemon adapter refuses again, right before each call: paths outside the root, creating, writing,
  deleting or moving a world, player list or `server.properties`, and any pull that is not
  `cdn.modrinth.com`.
- `server.properties` with escaped keys or line continuations is not guessed at: the install stops.
- Files marked `env.server: unsupported` are skipped.
- A pre-existing file in the way of a pack file or override is moved into the moved-aside folder first.
  Paths are compared exactly (case-sensitively), as on the server's filesystem.
- Every step job re-checks the stored plan; only one job chain per install can act (sequence number),
  every step has a deadline, and the scheduled watchdog resumes or fails stalled installs.
- File steps never run while the server is suspended, installing, restoring a backup or transferring.

## CurseForge

- **Setup.** An admin enters a CurseForge API key (console.curseforge.com) in the settings. It is stored in
  the panel's `.env` as `MODPACKS_PLUS_CURSEFORGE_API_KEY`, never shown again (the field only says a key
  is saved; empty keeps it), sent only to `https://api.curseforge.com/v1/` (redirects are never followed)
  and never written to install records, logs or error messages. Without a key (and without the mirror)
  the CurseForge tab says how to set it up and makes no request.
- **Key-free mirror (opt-in, off by default).** *Use the public CurseForge mirror when no API key is set*
  reads CurseForge through `https://api.curse.tools/v1`, a third party's copy of the API running on their
  key: no uptime guarantee, it may be rate-limited or disappear, and it may not be allowed by CurseForge's
  API terms. It is used for metadata only (HTTPS, never its `/v1/cf` form, which redirects to plain HTTP).
  A saved key always wins. If the mirror fails or rate-limits, the tab says "CurseForge unavailable — add an
  API key" and no install starts. **With the mirror, you trust it to say which files (CurseForge file ids)
  a pack contains and whether their authors allow third-party downloads.** It can't choose the bytes:
  every file is still fetched from CurseForge's own CDN at the path built from its id and name (or from
  Modrinth, see below) and checked against its hash.
- **Server pack first.** When the pack version has a server pack (`serverPackFileId`, from the API only),
  its files are written like overrides; its start scripts, loader installers, `libraries/` and bundled Java
  are skipped (the egg installs the loader version from the pack's `manifest.json`, see *Loader switching*).
  A server pack over the caps or unusable falls back to the manifest build; the summary says why.
- **Manifest build.** Otherwise `manifest.json` is read, its files resolved in batches and placed by kind
  (`mods/`; resource and shader packs are client-only and skipped), optional files left out, `overrides/`
  applied.
- **Download URLs.** A file's URL is always built from its own id and name
  (`https://edge.forgecdn.net/files/<id/1000>/<id%1000>/<name>`); the API's `downloadUrl` must name exactly
  that path or the file isn't fetched. File names must be plain `.jar`/`.zip` names. CurseForge CDN files
  are fetched, redirect-checked and sha1-verified by the panel (md5 is ignored), never pulled by the node.
- **Modrinth first.** Every file is looked up on Modrinth by sha1. A match (same size) downloads from
  `cdn.modrinth.com`, verified by sha512, and is recorded for Modrinth Plus; Modrinth's side data drops
  client-only mods (unknown files stay in). A failed Modrinth lookup stops the install (nothing guessed).
  The summary counts files from Modrinth, from CurseForge and to download manually.
- **Download manually.** Files whose author turned third-party downloads off (and that aren't on
  Modrinth), and data packs (they belong in the world, which installs never touch), are listed in the
  summary with a link to their CurseForge page. They are never fetched or scraped.
- **Also on Modrinth.** A CurseForge pack whose title (case-insensitive) and loader match a Modrinth
  modpack shows "Also on Modrinth — install from there", which lists it in the Modrinth tab. A suggestion
  only: titles can collide.

## Modrinth Plus

After an install, the pack's mods that are on Modrinth (top-level jars in `mods/`, matched by hash
through Modrinth's `version_files` lookup) are recorded in `mods/.modrinth-metadata.json`, the file the
official Modrinth plugin and Modrinth Plus read. They then show as installed there and can be updated
one by one; Modrinth Plus marks them "from modpack" and warns before updating one. Neither plugin needs
the other: the shared file is the only link.

- Entries use Modrinth Plus's format plus an `installed_as_modpack` key (provider, pack id, version id,
  pack name, version number). Older Modrinth Plus versions and the official plugin ignore it.
- The file is only appended to or merged into: other entries (pinned or not), unknown keys and the
  order are kept. A mod you track yourself under another jar is left alone.
- The pack's own mods folder replaces the old one, so the old `mods/.modrinth-metadata.json` (Modrinth
  Plus's previous list and pins) moves aside with it and is kept in
  `.modpacks-plus/previous-<date>-<id>/mods/.modrinth-metadata.json`; the install summary says where.
- A pack can't ship its own `.modrinth-metadata.json` (in `mods/` or `plugins/`): such overrides are
  skipped and listed in the summary. Jars a pack also writes through overrides aren't recorded.
- Written under Modrinth Plus's metadata lock. If the file can't be read (or is over 8 MB), isn't
  valid, or the lock or Modrinth isn't available, nothing is written and the install summary says so;
  the install itself still succeeds.

## Loader switching

When a pack needs another loader or Minecraft version, the install first moves the server to the
official [pelican-eggs/minecraft](https://github.com/pelican-eggs/minecraft) loader egg:

1. **check** and **record the current setup** — the egg (recognised by uuid or update URL, or tagged
   `minecraft` + the loader; the admin's default egg wins, see *Settings*), the version variables, a Java
   image the egg allows (≤1.16 → 8, 1.17 → 16, 1.18–1.20.4 → 17, 1.20.5–1.21.x → 21, newer → highest);
   the server's egg, startup, image, variables and mounts are stored on the install;
2. backup and old pack folders aside, as for any install;
3. **stop the server** and **set worlds and player files aside** (worlds with `_nether`/`_the_end`,
   `ops.json`, `whitelist.json`, ban lists, `usercache.json`, `server.properties`, `eula.txt`) into
   `.modpacks-plus/preserved-<date>/`;
4. **change the egg** with Pelican's egg changer (new startup, variables kept by name, mounts the egg
   doesn't allow detached), set `MC_VERSION` and the loader version, set the image;
5. **reinstall** with Pelican's reinstall service and **wait** for it (20-minute limit);
6. **put worlds and player files back** (anything the reinstall made in their place is kept in
   `.modpacks-plus/replaced-<date>/`), then download and apply the pack.

No egg is ever created, imported or edited, and no other server is touched. Without a usable egg,
nothing changes and the page says which egg to import. If the reinstall fails, the install ends
`failed`, the egg is **not** switched back automatically (the summary names the previous egg and
values), and **Restore previous files** moves the preserved worlds and player files and the old pack
folders back (never overwriting: whatever is in the way goes to `.modpacks-plus/replaced-<date>/`).
A cancel asked for while worlds are set aside takes effect once they are back. While worlds or
player files of a switch are still set aside, no new install starts; restore them first.

The backup can't be skipped for a switch, even with **Allow skipping the backup** on (the reinstall
is destructive). Every value the server keeps or gets is checked against the new egg's rules before
anything changes, and again (with the egg and image) right before the egg changes.

**Restore waits for the node.** No file is moved while the panel shows the server `installing`, or
while a reinstall was requested less than 30 minutes ago and the node never reported it finished
(Wings doesn't tell the panel whether an install script is still running). If a server stays
`installing` (the node never called back), an admin checks on the node that no install container is
running for it, then uses **Toggle install status** on the server (Admin → Servers → the server);
"Restore previous files" works from 30 minutes after the last reinstall request.

The install record shows the previous egg; the previous startup command, image and variable values
only to users with `startup.read`, and values of variables users can't see are never recorded
(names only).

Official eggs: Fabric (`LOADER_VERSION`), Forge (`FORGE_VERSION` = `<mc>-<forge>`), NeoForge
(`NEOFORGE_VERSION`), Quilt (no loader variable: its egg always installs the latest Quilt loader).

## Permissions

- Viewing the page: `file.read`.
- Installing, cancelling or restoring previous files: `settings.reinstall` + `file.create` +
  `file.delete` + `backup.create`.
- Switching the loader (changes the egg): admins with *update server* on the server (Pelican itself only
  lets admins change eggs). The setting **Let server users switch loaders** extends it to anyone with
  the install permissions. Without it, owners see "ask an admin to switch loaders".

Users without these permissions don't see the buttons; the Livewire handlers (`installPack`,
`switchLoaderAndInstall`, `cancelInstall`, `restorePreviousFiles`) check every permission again, so
direct calls get 403.

## Requirements

- The egg has the `minecraft` tag and a loader tag (`fabric`, `forge`, `neoforge` or `quilt`), or is one
  of the official pelican-eggs loader eggs; the Minecraft version comes from the `MINECRAFT_VERSION` or
  `MC_VERSION` variable.
- A queue worker (`QUEUE_CONNECTION` other than `sync`) and the scheduler (`schedule:run` every minute),
  which Pelican already needs. The scheduler resumes a stalled install or fails it after its step deadline.

## Known limits

- A node pull whose request timed out may still be writing when the next job looks at it; the file is
  only accepted once it has its full size, and pulls only come from Modrinth's CDN, which makes this rare.
- `cdn.modrinth.com` is trusted not to redirect off Modrinth (the node follows its redirects).
- Mods are code. These checks keep files inside the server and make downloads match what the pack
  declares; they don't make a malicious pack safe to run. A pack may still replace loader jars or start
  scripts at the server root.
- The install backup is locked, so it counts toward the server's backup limit until someone unlocks
  and deletes it. `.modpacks-plus/previous-*` folders are not cleaned up automatically yet (that comes
  with pack updates, a later release). After a failed install, `.modpacks-plus/staging/` may keep
  files that were never used.
- Stop the server before installing; a plain install doesn't stop it for you (a loader switch does,
  before moving worlds; a server started again during the switch is stopped again before they move back).
- A node pull that times out is checked and retried, at most 3 attempts per file; a very slow CDN
  download can therefore fail the install (retry it later).
- Moves never replace anything because the panel checks the target is free right before each one;
  that Wings' own rename refuses to replace an existing target is not verified, so a file appearing
  in that instant (e.g. a server started by hand) could be overwritten by the node.
- Any `server.properties` line ending in a single backslash is read as a line continuation and stops
  the install (fail closed), even when it isn't next to `level-name`.

## Settings

Admin → Plugins → Modpacks Plus → Settings: **Allow skipping the backup** (off by default),
**Let server users switch loaders** (off by default), a default egg per loader for when several
official eggs are imported (otherwise the switch is refused as ambiguous), the **CurseForge API key**
and **Use the public CurseForge mirror when no API key is set** (off by default; see *CurseForge*).
Caps can be changed with `MODPACKS_PLUS_*` environment variables (see `config/modpacks-plus.php`).

## Install

Pelican → Admin → Plugins → Import from URL:

```
https://github.com/DrMxrcy/pelican-plugins/releases/download/modpacks-plus-latest/modpacks-plus.zip
```

## Credits

Ideas from Modpack Manager by cosmii02 (MIT). This plugin is written fresh; no code is copied from it.

## License

GPL-3.0
