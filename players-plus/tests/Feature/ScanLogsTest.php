<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Console\Commands\ScanLogsCommand;
use DrMxrcy\PlayersPlus\Jobs\ScanLogsJob;
use DrMxrcy\PlayersPlus\Models\LogCursor;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Providers\PlayersPlusServiceProvider;
use DrMxrcy\PlayersPlus\Services\LogReader;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeDaemonFiles;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** Plan 28 step 4: the scheduled scan command, its per-server job and the setting. */
class ScanLogsTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $files = [];

    /** @var array<string, int> */
    private array $sizes = [];

    private bool $daemonDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-09 12:30:00');
        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand($this->app->make(ScanLogsCommand::class));

        Http::fake(fn (Request $request) => $this->daemonDown ? Http::response('boom', 500) : FakeDaemonFiles::respond($request, $this->files, $this->sizes, ['logs/latest.log' => '2026-11-09T12:30:00+00:00'])
            ?? Http::response('unexpected', 500));
    }

    private function minecraftServer(): Server
    {
        $server = $this->createServerModel();
        $egg = $this->cloneEggAndVariables($server->egg);
        $egg->forceFill(['tags' => ['minecraft']])->save();
        $server->forceFill(['egg_id' => $egg->id])->save();

        return $server->refresh();
    }

    public function test_command_queues_one_scan_per_minecraft_server(): void
    {
        Queue::fake();
        $a = $this->minecraftServer();
        $b = $this->minecraftServer();
        $other = $this->createServerModel();
        $otherEgg = $this->cloneEggAndVariables($other->egg);
        $otherEgg->forceFill(['tags' => ['source', 'steamcmd']])->save();
        $other->forceFill(['egg_id' => $otherEgg->id])->save();

        $this->artisan('players-plus:scan-logs')->assertSuccessful();

        Queue::assertPushed(ScanLogsJob::class, 2);
        foreach ([$a, $b] as $server) {
            Queue::assertPushed(ScanLogsJob::class, fn (ScanLogsJob $job) => $job->serverId === $server->id);
        }
        Queue::assertNotPushed(ScanLogsJob::class, fn (ScanLogsJob $job) => $job->serverId === $other->id);
        Http::assertNothingSent();
    }

    public function test_the_setting_or_disabled_polling_turns_scanning_off(): void
    {
        Queue::fake();
        $this->minecraftServer();

        config()->set('players-plus.log_scan', 'false');
        $this->artisan('players-plus:scan-logs')->assertSuccessful();
        config()->set('players-plus.log_scan', true);
        config()->set('players-plus.enabled', false);
        $this->artisan('players-plus:scan-logs')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_job_scans_the_log_into_sessions(): void
    {
        $server = $this->minecraftServer();
        $this->files = ['logs/latest.log' => (string) file_get_contents(dirname(__DIR__) . '/Fixtures/logs/vanilla.log')];

        $this->dispatchJob($server);

        $this->assertSame(1, PlayerSession::query()->count());
        $this->assertSame(strlen($this->files['logs/latest.log']), LogCursor::query()->sole()->offset);
    }

    public function test_a_missing_log_is_quiet_and_retried_next_time(): void
    {
        $server = $this->minecraftServer();

        $this->dispatchJob($server);
        $this->dispatchJob($server);

        $this->assertSame(2, FakeDaemonFiles::listings());
        $this->assertSame(0, LogCursor::query()->count());
    }

    public function test_an_oversized_log_is_backed_off_after_one_listing(): void
    {
        $server = $this->minecraftServer();
        $this->files = ['logs/latest.log' => "[12:00:00] [Server thread/INFO]: x\n"];
        $this->sizes = ['logs/latest.log' => LogReader::MAX_LOG_BYTES + 1];

        $this->dispatchJob($server);
        $this->dispatchJob($server);

        $this->assertSame(1, FakeDaemonFiles::listings());
        $this->assertSame(0, FakeDaemonFiles::contentReads('latest.log'));
    }

    public function test_daemon_errors_are_backed_off_and_do_not_fail_the_job(): void
    {
        $server = $this->minecraftServer();
        $this->daemonDown = true;

        $this->dispatchJob($server);
        $this->dispatchJob($server);

        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_schedule_scans_every_five_minutes_without_overlapping(): void
    {
        (new PlayersPlusServiceProvider($this->app))->boot();

        $scan = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'players-plus:scan-logs'));

        $this->assertNotNull($scan);
        $this->assertSame('*/5 * * * *', $scan->expression);
        $this->assertTrue($scan->withoutOverlapping);
    }

    private function dispatchJob(Server $server): void
    {
        ScanLogsJob::dispatchSync($server->id);
    }
}
