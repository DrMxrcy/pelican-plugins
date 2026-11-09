<?php

namespace DrMxrcy\PlayersPlus\Support;

use App\Models\Server;
use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Models\Sample;
use Filament\Schemas\Components\Callout;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether polling is keeping up. A sample is fresh while it is no older than three poll
 * intervals (plus a minute for the job itself); after that the scheduler or the queue worker is
 * probably not running. Same rule as PlayerList::isStale(). Reads stored samples only.
 */
final class PollHealth
{
    /** Oldest polled_at that still counts as fresh at $now. */
    public static function freshFrom(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->utc()->subMinutes(3 * Settings::pollInterval() + 1);
    }

    /**
     * The newest poll of any Minecraft server, whatever it found (online, offline or no answer).
     * Rows of servers that are no longer Minecraft servers aren't polled any more, so they don't
     * count. One query.
     */
    public static function lastPoll(): ?CarbonImmutable
    {
        $latest = Sample::query()->toBase()
            ->whereIn('server_id', Minecraft::serversQuery()->select('id'))
            ->max('polled_at');

        return $latest === null ? null : CarbonImmutable::parse((string) $latest, 'UTC');
    }

    /**
     * Why polling looks stopped, or null when it is fine: polling is enabled and no poll has run
     * for three intervals. When none has ever run, it only warns once the oldest Minecraft server
     * in $servers is older than that, so a fresh install or a new server gets time for its first
     * poll; $servers is the viewer's scope (default: every Minecraft server), so a node-limited
     * admin learns nothing about other nodes. The last-poll check itself is panel-wide: it is
     * about the queue worker and the scheduler, not about any one server.
     *
     * @param  Builder<Server>|null  $servers
     */
    public static function warning(?Builder $servers = null): ?string
    {
        if (!Settings::enabled()) {
            return null;
        }

        $last = self::lastPoll();
        if ($last === null) {
            $oldest = ($servers ?? Minecraft::serversQuery())->min('servers.created_at');

            return $oldest !== null && CarbonImmutable::parse((string) $oldest, 'UTC')->lt(self::freshFrom())
                ? trans('players-plus::strings.poll_health.never_body', ['minutes' => Settings::pollInterval()])
                : null;
        }

        return $last->lt(self::freshFrom())
            ? trans('players-plus::strings.poll_health.stale_body', ['time' => $last->diffForHumans(), 'minutes' => Settings::pollInterval()])
            : null;
    }

    /**
     * The warning as a callout for the admin overview and the plugin settings, or null.
     *
     * @param  Builder<Server>|null  $servers  see warning()
     */
    public static function callout(?Builder $servers = null): ?Callout
    {
        $warning = self::warning($servers);

        return $warning === null ? null : Callout::make(trans('players-plus::strings.poll_health.stale_title'))
            ->description($warning)
            ->icon('tabler-alert-triangle')
            ->color('warning');
    }
}
