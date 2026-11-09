# Players Plus

See who is online on your Minecraft servers, right in Pelican Panel.

- **Players** page on every Minecraft server: who is online now, with skin avatars.
- **Console widget**: online count (`3 / 20`) or "offline" next to the console.
- Polls every running Minecraft server on a schedule (default every 2 minutes, 1 to 5).
  Pages only read the stored result; opening a page never contacts the game server.

Minecraft Java only. Bedrock, proxies (Velocity/Bungee) and other games are not supported.

## Requirements

- The server's egg has the `minecraft` _tag_ (same rule as Modrinth Plus).
- Pelican's queue worker and cron (`schedule:run` every minute) are running. Both are
  already required for plugin installs.
- The panel can reach the game port. The address used is the allocation's alias when it is
  set, else its IP, else the node FQDN when the IP is `0.0.0.0` / `::`.

## How players are found

1. **Server List Ping** (TCP, no setup): online count, max players and a sample of up to
   12 names with UUIDs.
2. **Query** (UDP): the full name list. Used only when `server.properties` has
   `enable-query=true`; it uses `query.port` (default: the game port).

A server stopped in the panel is recorded as **offline** without being contacted. A running
server that answers neither is recorded as **unreachable**.

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
