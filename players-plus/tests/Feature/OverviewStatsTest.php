<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Tests\Traits\Integration\CreatesTestModels;
use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Console\Commands\RollupCommand;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Support\ActivityStats;
use DrMxrcy\PlayersPlus\Support\Minecraft;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Plan 34 step 1: grouped cross-server stats for the admin overview. One query per metric for
 * any number of servers; numbers match the per-server rules (offline is never 0 players).
 */
class OverviewStatsTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** Wednesday 2026-11-11 14:37 UTC. */
    private const NOW = '2026-11-11 14:37:00';

    private ?Egg $minecraftEgg = null;

    private ?User $owner = null;

    private ?Node $node = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        app()->setLocale('en');
    }

    /** @param  list<string>  $tags */
    private function server(array $tags = ['minecraft']): Server
    {
        if ($this->owner === null || $this->node === null) {
            /** @var User $owner */
            $owner = User::factory()->create();
            /** @var Node $node */
            $node = Node::factory()->create();
            [$this->owner, $this->node] = [$owner, $node];
        }

        $server = $this->createServerModel(['owner_id' => $this->owner->id, 'node_id' => $this->node->id]);
        if ($tags === ['minecraft'] && $this->minecraftEgg !== null) {
            $egg = $this->minecraftEgg;
        } else {
            $egg = $this->cloneEggAndVariables($server->egg);
            $egg->forceFill(['tags' => $tags])->save();
            if ($tags === ['minecraft']) {
                $this->minecraftEgg = $egg;
            }
        }
        $server->forceFill(['egg_id' => $egg->id])->save();

        return $server->refresh();
    }

    private function sample(Server $server, string $at, SampleStatus $status, ?int $online = null): void
    {
        Sample::query()->create([
            'server_id' => $server->id,
            'polled_at' => CarbonImmutable::parse($at, 'UTC'),
            'status' => $status,
            'online' => $online,
            'max' => $status === SampleStatus::Online ? 20 : null,
        ]);
    }

    private function player(Server $server, string $name, int $lastSeenMinutesAgo): void
    {
        Player::query()->create([
            'server_id' => $server->id,
            'name' => $name,
            'first_seen_at' => now()->subDays(30),
            'last_seen_at' => now()->subMinutes($lastSeenMinutesAgo),
        ]);
    }

    private function rollup(int $hours): void
    {
        $current = CarbonImmutable::now()->startOfHour();
        for ($i = $hours - 1; $i >= 0; $i--) {
            RollupCommand::rollupHour($current->subHours($i));
        }
    }

    /**
     * A: live, 7 now; 24 h peak 12 (the 30 is older than 24 h); 3 of its players, 2 seen this week.
     * B: offline now, online at 14:00 with 4. C: online but its last poll is stale (13:00).
     * D: Minecraft, never polled. E: not Minecraft, 50 online (must not leak into totals).
     *
     * @return array{Server, Server, Server, Server, Server}
     */
    private function seedServers(): array
    {
        [$a, $b, $c, $d] = [$this->server(), $this->server(), $this->server(), $this->server()];
        $e = $this->server(['source']);

        $this->sample($a, '2026-11-10 12:00:00', SampleStatus::Online, 30);
        $this->sample($a, '2026-11-11 10:00:00', SampleStatus::Online, 12);
        $this->sample($a, '2026-11-11 14:00:00', SampleStatus::Online, 3);
        $this->sample($a, '2026-11-11 14:36:00', SampleStatus::Online, 7);
        $this->sample($b, '2026-11-11 14:00:00', SampleStatus::Online, 4);
        $this->sample($b, '2026-11-11 14:30:00', SampleStatus::Unreachable);
        $this->sample($b, '2026-11-11 14:36:00', SampleStatus::Offline);
        $this->sample($c, '2026-11-11 13:00:00', SampleStatus::Online, 9);
        $this->sample($e, '2026-11-11 14:36:00', SampleStatus::Online, 50);
        $this->rollup(72);

        $this->player($a, 'Alice', 60);
        $this->player($a, 'Bob', 3 * 24 * 60);
        $this->player($a, 'Carol', 8 * 24 * 60);
        $this->player($e, 'Eve', 5);

        return [$a, $b, $c, $d, $e];
    }

    private function stats(string $timezone = 'UTC'): ActivityStats
    {
        return ActivityStats::acrossServers($timezone);
    }

    public function test_minecraft_servers_query_matches_the_egg_tag_rule(): void
    {
        [$a, $b, $c, $d, $e] = $this->seedServers();
        $tagged = $this->server(['proxy', 'minecraft']);
        $lookalike = $this->server(['minecraft-proxy']);

        $ids = Minecraft::serversQuery()->orderBy('id')->pluck('id')->all();

        $this->assertSame([$a->id, $b->id, $c->id, $d->id, $tagged->id], $ids);
        foreach ([$a, $e, $tagged, $lookalike] as $server) {
            $this->assertSame(Minecraft::isMinecraft($server), in_array($server->id, $ids, true));
        }
    }

    public function test_per_server_overview_matches_hand_computed_values(): void
    {
        [$a, $b, $c, $d] = $this->seedServers();

        $rows = $this->stats()->overview([$a->id, $b->id, $c->id, $d->id]);

        $this->assertSame([$a->id, $b->id, $c->id, $d->id], array_keys($rows));

        $this->assertSame(SampleStatus::Online, $rows[$a->id]['status']);
        $this->assertSame(7, $rows[$a->id]['online']);
        $this->assertSame(20, $rows[$a->id]['max']);
        $this->assertSame('2026-11-11 14:36:00', $rows[$a->id]['polled_at']?->toDateTimeString());
        $this->assertTrue($rows[$a->id]['live']);
        // The 30 from yesterday 12:00 is older than 24 hours.
        $this->assertSame(12, $rows[$a->id]['peak_24h']);
        // (30 + 12 + 3 + 7) / 4 online samples.
        $this->assertSame(13.0, $rows[$a->id]['average_7d']);
        // Alice (1 h ago) and Bob (3 days ago); Carol was last seen 8 days ago.
        $this->assertSame(2, $rows[$a->id]['unique_7d']);

        $this->assertSame(SampleStatus::Offline, $rows[$b->id]['status']);
        $this->assertNull($rows[$b->id]['online']);
        $this->assertFalse($rows[$b->id]['live']);
        $this->assertSame(4, $rows[$b->id]['peak_24h']);
        // Offline and unreachable samples are not averaged in as 0.
        $this->assertSame(4.0, $rows[$b->id]['average_7d']);
        $this->assertSame(0, $rows[$b->id]['unique_7d']);

        $this->assertSame(SampleStatus::Online, $rows[$c->id]['status']);
        $this->assertSame(9, $rows[$c->id]['online']);
        $this->assertFalse($rows[$c->id]['live'], 'last poll is older than three intervals');
        $this->assertSame(9, $rows[$c->id]['peak_24h']);

        $this->assertSame([
            'status' => null, 'online' => null, 'max' => null, 'polled_at' => null, 'live' => false,
            'peak_24h' => null, 'average_7d' => null, 'unique_7d' => 0,
        ], $rows[$d->id]);
    }

    public function test_totals_count_live_minecraft_servers_only(): void
    {
        $this->seedServers();

        // A only: B is offline, C is stale, D has no data, E is not Minecraft.
        $this->assertSame(['players' => 7, 'servers_online' => 1], $this->stats()->totals(Minecraft::serversQuery()->select('id')));
    }

    public function test_combined_series_sums_servers_per_poll_slot_with_gaps(): void
    {
        $this->seedServers();

        $series = $this->stats()->combinedSeries(Minecraft::serversQuery()->select('id'));
        $this->assertCount(24 * 30, $series['data']);
        $this->assertCount(24 * 30, $series['labels']);
        $points = array_combine($series['labels'], $series['data']);

        $this->assertSame(7, $points['14:00'], 'A 3 + B 4');
        $this->assertSame(7, $points['14:36'], 'A only; B is offline and E is not Minecraft');
        $this->assertSame(9, $points['13:00']);
        $this->assertSame(12, $points['10:00']);
        $this->assertNull($points['14:30'], 'unreachable is a gap, not 0');
        $this->assertNull($points['14:34'], 'not polled');
        $this->assertSame('14:36', array_key_last($points));
    }

    public function test_combined_series_labels_follow_the_viewer_timezone(): void
    {
        $this->seedServers();

        $points = array_combine(...array_values($this->stats('Europe/Berlin')->combinedSeries(Minecraft::serversQuery()->select('id'))));

        // 14:36 UTC is 15:36 in Berlin (CET, UTC+1).
        $this->assertSame(7, $points['15:36']);
    }

    public function test_no_servers_gives_empty_results_without_queries(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->stats()->overview([]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([], $rows);
        $this->assertSame(0, $count);
    }

    /** One grouped query per metric: the count is the same for 3 servers and for 30. */
    public function test_query_count_does_not_grow_with_server_count(): void
    {
        $three = $this->measure($this->seedMany(3));
        $thirty = $this->measure($this->seedMany(27));

        $this->assertSame(6, $three['count'], implode("\n", $three['queries']));
        $this->assertSame($three['count'], $thirty['count'], implode("\n", $thirty['queries']));
        $this->assertSame(30, Minecraft::serversQuery()->count());
    }

    /**
     * @param  list<int>  $ids  servers seeded by this call; every Minecraft server is measured
     * @return array{count: int, queries: list<string>}
     */
    private function measure(array $ids): array
    {
        $all = Minecraft::serversQuery()->orderBy('id')->pluck('id')->all();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $stats = $this->stats();
        $rows = $stats->overview($all);
        $stats->totals(Minecraft::serversQuery()->select('id'));
        $stats->combinedSeries(Minecraft::serversQuery()->select('id'));
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertCount(count($all), $rows);
        foreach ($ids as $id) {
            $this->assertSame(5, $rows[$id]['peak_24h']);
        }

        return ['count' => count($queries), 'queries' => $queries];
    }

    /** @return list<int> */
    private function seedMany(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $server = $this->server();
            $this->sample($server, '2026-11-11 14:30:00', SampleStatus::Online, 5);
            $this->sample($server, '2026-11-11 14:36:00', SampleStatus::Online, 2);
            $this->player($server, "P$i", 10);
            $ids[] = $server->id;
        }
        $this->rollup(2);

        return $ids;
    }
}
