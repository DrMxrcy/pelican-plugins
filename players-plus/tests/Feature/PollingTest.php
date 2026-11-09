<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Enums\ServerState;
use App\Models\Allocation;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Console\Commands\PollPlayersCommand;
use DrMxrcy\PlayersPlus\Console\Commands\PruneSamplesCommand;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Jobs\PollServerJob;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Providers\PlayersPlusServiceProvider;
use DrMxrcy\PlayersPlus\Services\PlayerPoller;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Services\SessionTracker;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeChannel;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConnector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\Packets;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Plan 25 step 3: poll command, per-server job, status rules and storage. Game servers are
 * fake channels and the daemon is Http::fake(); nothing leaves the test process.
 */
class PollingTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private FakeConnector $connector;

    /** @var array<string, string> daemon container state per server uuid */
    private array $states = [];

    /** @var array<string, string|null> server.properties per server uuid (null = 404) */
    private array $properties = [];

    private bool $daemonDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connector = new FakeConnector();
        $this->app->instance(Connector::class, $this->connector);

        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand($this->app->make(PollPlayersCommand::class));
        $kernel->registerCommand($this->app->make(PruneSamplesCommand::class));

        Http::fake(function (Request $request) {
            if ($this->daemonDown) {
                return Http::response('', 502);
            }

            if (preg_match('#/api/servers/([0-9a-f-]+)/files/list-directory#', $request->url(), $m)) {
                $content = $this->properties[$m[1]] ?? null;

                return Http::response($content === null ? [] : [['name' => 'server.properties', 'size' => strlen($content), 'directory' => false, 'file' => true]]);
            }

            if (preg_match('#/api/servers/([0-9a-f-]+)/files/contents#', $request->url(), $m)) {
                $content = $this->properties[$m[1]] ?? null;

                return $content === null ? Http::response('', 404) : Http::response($content);
            }

            if (preg_match('#/api/servers/([0-9a-f-]+)$#', $request->url(), $m)) {
                return Http::response(['state' => $this->states[$m[1]] ?? 'offline', 'utilization' => []]);
            }

            return Http::response('unexpected', 500);
        });
    }

    /** @param  list<int>  $extraPorts  more allocations of the server on the same IP */
    private function minecraftServer(string $state = 'running', ?string $properties = "enable-query=false\n", string $ip = '10.0.0.5', int $port = 25565, array $extraPorts = [], ?int $nodeId = null): Server
    {
        $server = $this->createServerModel($nodeId === null ? [] : ['node_id' => $nodeId]);
        $egg = $this->cloneEggAndVariables($server->egg);
        $egg->forceFill(['tags' => ['minecraft', 'paper']])->save();
        $server->forceFill(['egg_id' => $egg->id])->save();
        $server->allocation->forceFill(['ip' => $ip, 'ip_alias' => null, 'port' => $port])->save();
        foreach ($extraPorts as $extra) {
            Allocation::factory()->create(['node_id' => $server->node_id, 'server_id' => $server->id, 'ip' => $ip, 'port' => $extra]);
        }

        $this->states[$server->uuid] = $state;
        $this->properties[$server->uuid] = $properties;

        return $server->refresh();
    }

    private function answerPing(string $host = '10.0.0.5', int $port = 25565): FakeChannel
    {
        return $this->connector->channels["tcp://$host:$port"] = new FakeChannel(Packets::statusResponse(Packets::vanillaStatusJson()));
    }

    /** @param  list<string>  $names */
    private function answerQuery(array $names, string $host = '10.0.0.5', int $port = 25575): FakeChannel
    {
        return $this->connector->channels["udp://$host:$port"] = new FakeChannel(responder: fn (string $request) => $request[2] === "\x09"
            ? Packets::handshakeResponse(substr($request, 3, 4), '12345')
            : Packets::fullStatResponse(substr($request, 3, 4), $names));
    }

    private function poll(Server $server, int $slot = 1_800_000_000, float $daemonSlowSeconds = 0.9): Sample
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($slot + 10));
        $poller = $this->app->make(PlayerPoller::class);
        $poller->daemonSlowSeconds = $daemonSlowSeconds;
        (new PollServerJob($server->id, $slot))->handle($poller, $this->app->make(SessionTracker::class));

        return Sample::query()->where('server_id', $server->id)->where('polled_at', Carbon::createFromTimestamp($slot))->firstOrFail();
    }

    private function daemonCalls(): int
    {
        return Http::recorded(fn (Request $request) => (bool) preg_match('#/api/servers/[0-9a-f-]+$#', $request->url()))->count();
    }

    // ---- command -------------------------------------------------------------------------

    public function test_command_queues_one_job_per_minecraft_server_and_contacts_nothing(): void
    {
        Queue::fake();
        $a = $this->minecraftServer();
        $b = $this->minecraftServer();
        $other = $this->createServerModel();
        $otherEgg = $this->cloneEggAndVariables($other->egg);
        $otherEgg->forceFill(['tags' => ['source', 'steamcmd']])->save();
        $other->forceFill(['egg_id' => $otherEgg->id])->save();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_250));

        $this->artisan('players-plus:poll')->assertSuccessful();

        Queue::assertPushed(PollServerJob::class, 2);
        foreach ([$a, $b] as $server) {
            Queue::assertPushed(PollServerJob::class, fn (PollServerJob $job) => $job->serverId === $server->id && $job->slot === 1_800_000_240);
        }
        Queue::assertNotPushed(PollServerJob::class, fn (PollServerJob $job) => $job->serverId === $other->id);
        $this->assertSame([], $this->connector->opened);
        Http::assertNothingSent();
    }

    public function test_disabled_polling_queues_nothing(): void
    {
        Queue::fake();
        $this->minecraftServer();
        config()->set('players-plus.enabled', false);

        $this->artisan('players-plus:poll')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_slot_is_the_start_of_the_interval(): void
    {
        $this->assertSame(1_800_000_000, PollPlayersCommand::slot(1_800_000_119, 2));
        $this->assertSame(1_800_000_120, PollPlayersCommand::slot(1_800_000_120, 2));
        $this->assertSame(1_799_999_940, PollPlayersCommand::slot(1_800_000_000, 1) - 60);
        $this->assertSame(0, PollPlayersCommand::slot(1_800_000_000, 5) % 300);
    }

    /** A backed-up queue must not write samples for slots that are already over. */
    public function test_job_for_a_finished_slot_is_dropped(): void
    {
        $server = $this->minecraftServer();
        $this->answerPing();
        $slot = 1_800_000_000;

        Carbon::setTestNow(Carbon::createFromTimestamp($slot + 120));
        (new PollServerJob($server->id, $slot))->handle($this->app->make(PlayerPoller::class), $this->app->make(SessionTracker::class));

        $this->assertSame(0, Sample::query()->count());
        $this->assertSame([], $this->connector->opened);
        Http::assertNothingSent();

        Carbon::setTestNow(Carbon::createFromTimestamp($slot + 119));
        (new PollServerJob($server->id, $slot))->handle($this->app->make(PlayerPoller::class), $this->app->make(SessionTracker::class));

        $this->assertSame(1, Sample::query()->count());
    }

    /** A daemon that times out trips a breaker: its node's other servers skip it for the rest of the slot. */
    public function test_slow_daemon_failure_skips_the_rest_of_the_node_for_the_slot(): void
    {
        $first = $this->minecraftServer();
        $sameNode = $this->minecraftServer(ip: '10.0.0.7', nodeId: $first->node_id);
        $otherNode = $this->minecraftServer();
        $this->daemonDown = true;

        // Threshold 0: every "missing" counts as slow, standing in for the daemon's 1 s timeout.
        $this->assertSame(SampleStatus::Offline, $this->poll($first, daemonSlowSeconds: 0.0)->status);
        $this->assertSame(1, $this->daemonCalls());

        $this->assertSame(SampleStatus::Offline, $this->poll($sameNode, daemonSlowSeconds: 0.0)->status);
        $this->assertSame(1, $this->daemonCalls(), 'same node, same slot: daemon not asked again');

        $this->poll($otherNode, daemonSlowSeconds: 0.0);
        $this->assertSame(2, $this->daemonCalls(), 'other nodes are not affected');

        $this->daemonDown = false;
        $this->answerPing('10.0.0.7');
        $this->assertSame(SampleStatus::Online, $this->poll($sameNode, 1_800_000_120)->status, 'the breaker ends with the slot');
    }

    /** A fast "missing" (e.g. server not on the daemon) is not a node outage. */
    public function test_fast_missing_status_does_not_trip_the_breaker(): void
    {
        $first = $this->minecraftServer();
        $sameNode = $this->minecraftServer(ip: '10.0.0.7', nodeId: $first->node_id);
        $this->daemonDown = true;

        $this->poll($first, daemonSlowSeconds: 60.0);
        $this->poll($sameNode, daemonSlowSeconds: 60.0);

        $this->assertSame(2, $this->daemonCalls());
    }

    public function test_job_is_short_unique_per_slot_and_not_retried(): void
    {
        $job = new PollServerJob(7, 1_800_000_000);

        $this->assertSame(1, $job->tries);
        $this->assertLessThanOrEqual(60, $job->timeout);
        $this->assertSame('7:1800000000', $job->uniqueId());
    }

    // ---- status rules --------------------------------------------------------------------

    public function test_stopped_server_is_offline_and_not_contacted(): void
    {
        $server = $this->minecraftServer(state: 'offline');

        $sample = $this->poll($server);

        $this->assertSame(SampleStatus::Offline, $sample->status);
        $this->assertNull($sample->online);
        $this->assertNull($sample->players);
        $this->assertSame([], $this->connector->opened);
    }

    public function test_suspended_server_is_offline_without_asking_anyone(): void
    {
        $server = $this->minecraftServer();
        $server->forceFill(['status' => ServerState::Suspended])->save();

        $this->assertSame(SampleStatus::Offline, $this->poll($server)->status);
        $this->assertSame([], $this->connector->opened);
        Http::assertNothingSent();
    }

    public function test_query_disabled_uses_ping_count_and_sample(): void
    {
        $server = $this->minecraftServer(properties: "enable-query=false\nquery.port=25575\n");
        $this->answerPing();

        $sample = $this->poll($server);

        $this->assertSame(SampleStatus::Online, $sample->status);
        $this->assertSame(2, $sample->online);
        $this->assertSame(20, $sample->max);
        $this->assertSame('ping', $sample->source);
        $this->assertSame([
            ['name' => 'Notch', 'uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5'],
            ['name' => 'jeb_', 'uuid' => '853c80ef-3c37-49fd-aa49-938b674adae6'],
        ], $sample->players);
        $this->assertSame(['tcp://10.0.0.5:25565'], array_column($this->connector->opened, 'uri'));
    }

    public function test_query_enabled_uses_the_full_name_list_with_ping_uuids(): void
    {
        $server = $this->minecraftServer(properties: "enable-query=true\nquery.port=25575\n", extraPorts: [25575]);
        $this->answerQuery(['Notch', 'Dinnerbone', 'jeb_']);
        $this->answerPing();

        $sample = $this->poll($server);

        $udp = array_values(array_filter($this->connector->opened, fn (array $open) => str_starts_with($open['uri'], 'udp://')));
        $this->assertCount(1, $udp);
        $this->assertLessThanOrEqual(3.0, $udp[0]['timeout']);
        $this->assertGreaterThanOrEqual(0.25, $udp[0]['timeout']);

        $this->assertSame(SampleStatus::Online, $sample->status);
        $this->assertSame(3, $sample->online);
        $this->assertSame('query', $sample->source);
        $this->assertSame([
            ['name' => 'Notch', 'uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5'],
            ['name' => 'Dinnerbone', 'uuid' => null],
            ['name' => 'jeb_', 'uuid' => '853c80ef-3c37-49fd-aa49-938b674adae6'],
        ], $sample->players);
    }

    public function test_query_without_a_port_uses_the_game_port(): void
    {
        $server = $this->minecraftServer(properties: "enable-query=true\n");
        $this->answerQuery(['Notch'], port: 25565);
        $this->answerPing();

        $sample = $this->poll($server);

        $this->assertSame('query', $sample->source);
        $this->assertSame(1, $sample->online);
    }

    public function test_failed_query_falls_back_to_ping(): void
    {
        $server = $this->minecraftServer(properties: "enable-query=true\nquery.port=25575\n", extraPorts: [25575]);
        $this->answerPing();

        $sample = $this->poll($server);

        $this->assertSame(SampleStatus::Online, $sample->status);
        $this->assertSame('ping', $sample->source);
        $this->assertContains('udp://10.0.0.5:25575', array_column($this->connector->opened, 'uri'));
    }

    /** server.properties is tenant-editable: a query.port that isn't the server's own is never contacted. */
    public function test_query_port_outside_the_servers_allocations_is_never_contacted(): void
    {
        $server = $this->minecraftServer(properties: "enable-query=true\nquery.port=22\n", extraPorts: [25575]);
        $this->answerQuery(['Notch'], port: 22);
        $this->answerPing();

        $sample = $this->poll($server);

        $this->assertSame('ping', $sample->source);
        $this->assertSame(['tcp://10.0.0.5:25565'], array_column($this->connector->opened, 'uri'));
    }

    /** An allocation of the same server on a different IP is not a valid Query target either. */
    public function test_query_port_on_another_ip_is_rejected(): void
    {
        $server = $this->minecraftServer(properties: "enable-query=true\nquery.port=25575\n");
        Allocation::factory()->create(['node_id' => $server->node_id, 'server_id' => $server->id, 'ip' => '10.0.0.99', 'port' => 25575]);
        $this->answerPing();

        $this->assertSame('ping', $this->poll($server)->source);
        $this->assertSame(['tcp://10.0.0.5:25565'], array_column($this->connector->opened, 'uri'));
    }

    public function test_unreadable_properties_means_ping_only(): void
    {
        $server = $this->minecraftServer(properties: null);
        $this->answerPing();

        $this->assertSame('ping', $this->poll($server)->source);
    }

    public function test_running_but_silent_is_unreachable_and_logged_once_per_hour(): void
    {
        $log = Log::spy();
        $server = $this->minecraftServer(properties: "enable-query=true\n");
        $this->answerQuery(['Notch'], port: 25565);

        $first = $this->poll($server, 1_800_000_000);
        $second = $this->poll($server, 1_800_000_120);

        $this->assertSame(SampleStatus::Unreachable, $first->status);
        $this->assertSame(SampleStatus::Unreachable, $second->status);
        $this->assertNull($first->online);
        $log->shouldHaveReceived('warning')->once();
        // Ping failed, so Query was skipped: a dead server costs one timeout, not two.
        $this->assertSame(['tcp://10.0.0.5:25565'], array_unique(array_column($this->connector->opened, 'uri')));
    }

    /** The panel turns a daemon error into ContainerStatus::Missing, i.e. "not running". */
    public function test_daemon_error_is_offline_without_a_game_query(): void
    {
        $server = $this->minecraftServer();
        $this->daemonDown = true;

        $this->assertSame(SampleStatus::Offline, $this->poll($server)->status);
        $this->assertSame([], $this->connector->opened);
    }

    public function test_unexpected_poller_error_still_stores_a_sample(): void
    {
        $log = Log::spy();
        $server = $this->minecraftServer();
        $this->mock(PlayerPoller::class)->shouldReceive('poll')->andThrow(new RuntimeException('boom'));

        $this->assertSame(SampleStatus::Unreachable, $this->poll($server)->status);
        $log->shouldHaveReceived('warning')->once();
    }

    public function test_rerunning_a_slot_overwrites_its_sample(): void
    {
        $server = $this->minecraftServer(state: 'offline');
        $this->poll($server);

        $this->states[$server->uuid] = 'running';
        cache()->forget("servers.$server->uuid.status");
        $this->answerPing();
        $sample = $this->poll($server);

        $this->assertSame(SampleStatus::Online, $sample->status);
        $this->assertSame(1, Sample::query()->where('server_id', $server->id)->count());
    }

    public function test_polls_build_sessions_and_a_stopped_server_closes_them(): void
    {
        $server = $this->minecraftServer();
        $this->answerPing();
        $this->poll($server, 1_800_000_000);
        $this->answerPing();
        $this->poll($server, 1_800_000_120);

        $this->assertSame(2, PlayerSession::query()->whereNull('ended_at')->count());

        $this->states[$server->uuid] = 'offline';
        cache()->forget("servers.$server->uuid.status");
        $this->poll($server, 1_800_000_240);

        // Closed at the last online sample, not left open while the server is down.
        $this->assertSame(0, PlayerSession::query()->whereNull('ended_at')->count());
        $notch = Player::query()->where('server_id', $server->id)->where('name', 'Notch')->firstOrFail();
        $this->assertSame('069a79f4-44e9-4726-a5be-fca90e38aaf5', $notch->uuid);
        $this->assertSame(120, $notch->total_seconds);

        // Profiles and stats are read on demand by the profile page, never by the poll.
        $this->assertSame(0, Http::recorded(fn (Request $request) => str_contains(urldecode($request->url()), 'stats/'))->count());
    }

    public function test_tracker_failure_still_stores_the_sample_and_is_reported_once_per_hour(): void
    {
        Exceptions::fake();
        $server = $this->minecraftServer();
        $this->mock(SessionTracker::class)->shouldReceive('track')->andThrow(new RuntimeException('tracker bug'));

        $this->answerPing();
        $this->assertSame(SampleStatus::Online, $this->poll($server, 1_800_000_000)->status);
        $this->answerPing();
        $this->assertSame(SampleStatus::Online, $this->poll($server, 1_800_000_120)->status);

        Exceptions::assertReportedCount(1);
    }

    public function test_server_properties_are_read_once_and_cached(): void
    {
        $server = $this->minecraftServer(properties: "enable-query=false\n");
        $this->answerPing();
        $this->poll($server, 1_800_000_000);
        $this->answerPing();
        $this->poll($server, 1_800_000_120);

        $reads = Http::recorded(fn (Request $request) => str_contains($request->url(), '/files/contents'))->count();
        $this->assertSame(1, $reads, 'server.properties is read once and cached');
    }

    // ---- schedule and retention ----------------------------------------------------------

    public function test_schedule_polls_on_the_interval_without_overlapping(): void
    {
        config()->set('players-plus.poll_interval', 3);
        (new PlayersPlusServiceProvider($this->app))->boot();

        $events = collect($this->app->make(Schedule::class)->events());
        $poll = $events->first(fn (Event $event) => str_contains((string) $event->command, 'players-plus:poll'));
        $prune = $events->first(fn (Event $event) => str_contains((string) $event->command, 'players-plus:prune'));

        $this->assertNotNull($poll);
        $this->assertSame('*/3 * * * *', $poll->expression);
        $this->assertTrue($poll->withoutOverlapping);
        $this->assertSame(10, $poll->expiresAt);
        $this->assertNotNull($prune);
    }

    public function test_prune_deletes_samples_past_retention(): void
    {
        $server = $this->minecraftServer();
        config()->set('players-plus.retention_days', 7);
        Sample::query()->create(['server_id' => $server->id, 'polled_at' => now()->subDays(8), 'status' => SampleStatus::Offline]);
        $kept = Sample::query()->create(['server_id' => $server->id, 'polled_at' => now()->subDays(6), 'status' => SampleStatus::Offline]);

        $this->artisan('players-plus:prune')->assertSuccessful();

        $this->assertSame([$kept->id], Sample::query()->pluck('id')->all());
    }
}
