<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a sampled name (and UUID, when Ping sent one) into this server's Player record.
 *
 * UUID sources, in order: the Ping sample, then the server's `usercache.json` (read through
 * the daemon only when a name has no UUID, cached per server), else the record stays
 * name-only (uuid null) until a UUID shows up, when it is adopted or merged.
 */
class PlayerIdentity
{
    public const CACHE_SECONDS = 600;

    public const MAX_PREVIOUS_NAMES = 20;

    /** A usercache holds ~1000 entries of ~110 bytes; anything far past that is not one. */
    private const MAX_USERCACHE_BYTES = 1_048_576;

    private const MAX_USERCACHE_ENTRIES = 5000;

    public function resolve(Server $server, string $name, ?string $uuid, Carbon $seenAt): Player
    {
        $uuid ??= $this->usercache($server)[strtolower($name)] ?? null;

        return DB::transaction(fn () => $uuid === null
            ? $this->resolveByName($server, $name, $seenAt)
            : $this->resolveByUuid($server, $name, $uuid, $seenAt));
    }

    private function resolveByUuid(Server $server, string $name, string $uuid, Carbon $seenAt): Player
    {
        $player = Player::query()->where('server_id', $server->id)->where('uuid', $uuid)->first();
        $nameOnly = Player::query()->where('server_id', $server->id)->whereNull('uuid')->where('name', $name)->orderBy('id')->first();

        if ($player === null && $nameOnly !== null) {
            // The UUID was missing until now: the name-only record is this player.
            $nameOnly->uuid = $uuid;

            return $this->seen($nameOnly, $name, $seenAt);
        }

        if ($player === null) {
            return $this->create($server, $name, $uuid, $seenAt);
        }

        if ($nameOnly !== null) {
            $this->merge($nameOnly, $player);
        }

        return $this->seen($player, $name, $seenAt);
    }

    private function resolveByName(Server $server, string $name, Carbon $seenAt): Player
    {
        // Prefer a record with a UUID that currently has this name; else the name-only one.
        $player = Player::query()->where('server_id', $server->id)->where('name', $name)
            ->orderByRaw('uuid is null')->orderBy('id')->first();

        return $player === null ? $this->create($server, $name, null, $seenAt) : $this->seen($player, $name, $seenAt);
    }

    private function create(Server $server, string $name, ?string $uuid, Carbon $seenAt): Player
    {
        return Player::query()->create([
            'server_id' => $server->id,
            'uuid' => $uuid,
            'name' => $name,
            'previous_names' => null,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
            'total_seconds' => 0,
        ]);
    }

    private function seen(Player $player, string $name, Carbon $seenAt): Player
    {
        if ($player->name !== $name) {
            $player->previous_names = self::pushName($player->previous_names ?? [], $player->name, $name);
            $player->name = $name;
        }
        if ($player->last_seen_at->lt($seenAt)) {
            $player->last_seen_at = $seenAt;
        }
        if ($player->first_seen_at->gt($seenAt)) {
            $player->first_seen_at = $seenAt;
        }
        $player->save();

        return $player;
    }

    /** Folds a name-only record into the record that owns its UUID. */
    private function merge(Player $from, Player $into): void
    {
        PlayerSession::query()->where('player_id', $from->id)->update(['player_id' => $into->id]);

        // Both may have been "online" at once; keep the earliest open session only.
        $open = $into->sessions()->whereNull('ended_at')->orderBy('started_at')->orderBy('id')->pluck('id');
        if ($open->count() > 1) {
            PlayerSession::query()->whereIn('id', $open->slice(1)->all())->delete();
        }

        $into->total_seconds += $from->total_seconds;
        $into->first_seen_at = $into->first_seen_at->min($from->first_seen_at);
        $into->last_seen_at = $into->last_seen_at->max($from->last_seen_at);
        $names = $into->previous_names ?? [];
        foreach ([...($from->previous_names ?? []), $from->name] as $old) {
            $names = self::pushName($names, $old, $into->name);
        }
        $into->previous_names = $names;
        $from->delete();
    }

    /**
     * @param  array<mixed>  $names  stored JSON, so not trusted to be strings
     * @return list<string>
     */
    private static function pushName(array $names, string $old, string $current): array
    {
        $names = array_values(array_filter($names, fn ($n) => is_string($n) && $n !== $old && $n !== $current));
        if ($old !== $current) {
            $names[] = $old;
        }

        return array_slice($names, -self::MAX_PREVIOUS_NAMES);
    }

    /**
     * Lower-case name => UUID from the server's usercache.json. Tenant-editable, so every
     * entry is validated; an unreadable or malformed file is an empty map.
     *
     * @return array<string, string>
     */
    public function usercache(Server $server): array
    {
        /** @var mixed $map */
        $map = Cache::remember("players-plus.usercache.$server->id", self::CACHE_SECONDS, function () use ($server) {
            try {
                $content = app(DaemonFileRepository::class)->setServer($server)->getContent('usercache.json', self::MAX_USERCACHE_BYTES);
            } catch (Throwable) {
                return [];
            }

            return self::parseUsercache($content);
        });

        return is_array($map) ? $map : [];
    }

    /** @return array<string, string> */
    public static function parseUsercache(string $content): array
    {
        if (strlen($content) > self::MAX_USERCACHE_BYTES) {
            return [];
        }

        $entries = json_decode($content, true);
        if (!is_array($entries) || !array_is_list($entries)) {
            return [];
        }

        $map = [];
        foreach (array_slice($entries, 0, self::MAX_USERCACHE_ENTRIES) as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            $uuid = is_array($entry) ? MinecraftQuery::normalizeUuid($entry['uuid'] ?? null) : null;
            if (is_string($name) && $uuid !== null && MinecraftQuery::isPlayerName($name)) {
                $map[strtolower($name)] = $uuid;
            }
        }

        return $map;
    }
}
