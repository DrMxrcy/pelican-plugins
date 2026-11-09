# Players Plus

See who is online on your Minecraft servers, right in Pelican Panel.

- **Players** page on every Minecraft server: who is online now, with skin avatars, and
  **All players** ever seen, with last seen, total playtime, sessions and first seen.
- **Player profile**: name, UUID and past names, online status, total and last-7-days
  playtime, every session, and vanilla stats (play time, deaths, kills, distance, jumps).
- **Console widget**: online count (`3 / 20`) or "offline" next to the console.
- Polls every running Minecraft server on a schedule (default every 2 minutes, 1 to 5).
  Pages only read the stored result; opening a page never contacts the game server.

Minecraft Java only. Bedrock, proxies (Velocity/Bungee) and other games are not supported.

## Requirements

- The server's egg has the `minecraft` _tag_ (same rule as Modrinth Plus).
- Pelican's queue worker and cron (`schedule:run` every minute) are running. Both are
  already required for plugin installs.
- Each poll is one short queue job per server (at most ~3 s per step and ~9 s of game-server
  time in total). A stock panel runs a single `queue:work`, so with many servers that don't
  answer, polls queue up behind each other. Jobs whose poll interval has already passed are
  dropped instead of writing stale samples, and a node whose daemon times out is skipped for
  the rest of that interval. If you run many Minecraft servers, run more than one queue worker.
- The panel can reach the game port. The address used is the allocation's alias when it is
  set, else its IP, else the node FQDN when the IP is `0.0.0.0` / `::`.

## How players are found

1. **Server List Ping** (TCP, no setup): online count, max players and a sample of up to
   12 names with UUIDs.
2. **Query** (UDP): the full name list. Used only when `server.properties` has
   `enable-query=true`; it uses `query.port` (default: the game port).

Query is only sent to a `query.port` that is one of the server's own allocation ports on the
same IP, and only after Ping answered. Each poll keeps at most 200 names.

A server stopped in the panel is recorded as **offline** without being contacted. A running
server that does not answer Ping is recorded as **unreachable**.

## Sessions and playtime

Each poll is compared with the players already online: a new name starts a session at the
poll time, a missing name ends it at the poll time, and a server that goes offline or stops
answering ends every session at the last poll that saw it online. Playtime is therefore
accurate to one poll interval. A gap in polling (scheduler or worker down) is not counted.

With Ping only, more than 12 players online means the list is partial: sessions of players
who are just not in the sample stay open instead of ending. Enable Query for full tracking.

Players are matched by UUID: from the Ping sample, else from the server's `usercache.json`
(read through the daemon at most every 10 minutes, only when a name has no UUID), else by
name until a UUID shows up. A new name on a known UUID is kept as a past name.

Stats come from `<level-name>/stats/<uuid>.json`, read through the daemon when a profile is
opened (cached 10 minutes), never during polls. Only users who may read the server's files
see them.

## Privacy

Skin avatars are loaded from [mc-heads.net](https://mc-heads.net) by the viewer's browser
(by UUID, or name when no UUID is known), so that service sees the viewer's IP address and
which players are being looked at. The panel itself never contacts it.

## Settings

Admin → Plugins → Players Plus → Settings: polling on/off, poll interval (minutes) and how
many days samples are kept. Stored in `.env` as `PLAYERS_PLUS_*`.

## Install

Pelican → Admin → Plugins → Import from URL:

```
https://github.com/DrMxrcy/pelican-plugins/releases/download/players-plus-latest/players-plus.zip
```

## Compatibility

Everything this plugin adds is prefixed (`pp_` tables, `players-plus` config, translations
and routes, `PLAYERS_PLUS_*` env), so it can run next to Player Counter or Server Analytics.

## License

GPL-3.0
