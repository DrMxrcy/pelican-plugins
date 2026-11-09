# Modpacks Plus

Browse [Modrinth](https://modrinth.com) modpacks and install one onto a Minecraft server whose
loader and Minecraft version **already match** the pack.

## What it does

- A **Modpacks** server page: search Modrinth modpacks filtered by the server's Minecraft version
  and loader, with categories and sort; a pack detail view (description, gallery, versions).
- Versions for another Minecraft version or loader show "Needs &lt;loader&gt; &lt;version&gt;" and
  cannot be installed (loader switching is a later release).
- An install runs as chained short queue jobs:
  1. **prepare** — download the `.mrpack` on the panel, verify its sha512, read and validate the index;
  2. **backup** — a locked backup through Pelican's backup service (never deletes an old backup);
  3. **move aside** — `mods/`, `config/`, `defaultconfigs/`, `kubejs/`, `scripts/` go to
     `.modpacks-plus/previous-<date>/` (nothing is deleted);
  4. **download** — server-side files are pulled by the node, then verified;
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
- Downloads must be HTTPS from `cdn.modrinth.com`, `github.com`, `raw.githubusercontent.com`,
  `objects.githubusercontent.com` or `gitlab.com` (no credentials, no custom port). The first allowlisted
  URL of a file is used; a file without one rejects the pack.
- Hashes: the `.mrpack` is hashed (sha512) on the panel. Files on `cdn.modrinth.com` are checked
  against Modrinth's own hash lookup before the node downloads them, and their size is checked after;
  other files are read back through the panel and hashed (sha512, else sha1). A mismatch deletes the
  file and fails the install.
- Caps (config / env): 1000 files, 256 MB per file, 100 MB archive, 4 GB total, 20 000 archive entries,
  64 MB per read-back file.
- Files marked `env.server: unsupported` are skipped.
- File steps never run while the server is suspended, installing, restoring a backup or transferring.

## Permissions

- Viewing the page: `file.read`.
- Installing or cancelling: `settings.reinstall` + `file.create` + `file.delete` + `backup.create`.

Both are checked when the page or button is shown and again inside every Livewire handler.

## Requirements

- The egg has the `minecraft` tag and a loader tag (`fabric`, `forge`, `neoforge` or `quilt`);
  the Minecraft version comes from the `MINECRAFT_VERSION` or `MC_VERSION` variable.
- A queue worker (`QUEUE_CONNECTION` other than `sync`) and the scheduler (`schedule:run` every minute),
  which Pelican already needs. The scheduler resumes a stalled install or fails it after its step deadline.

## Settings

Admin → Plugins → Modpacks Plus → Settings: **Allow skipping the backup** (off by default).
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
