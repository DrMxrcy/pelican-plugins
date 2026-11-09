<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Enums\SessionSource;
use DrMxrcy\PlayersPlus\Models\LogCursor;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\LogBackfill;
use DrMxrcy\PlayersPlus\Services\SessionTracker;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeDaemonFiles;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 step 3: log events merged into poll-built sessions. Times are UTC on 2026-11-09;
 * the default poll interval is 2 minutes, so the override window is 4 minutes.
 */
class LogBackfillTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const A = '853c80ef-3c37-49fd-aa49-938b674adae6';

    private Server $server;

    private string $log = '';

    private string $modified = '2026-11-09T12:30:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->createServerModel();
        Http::fake(fn (Request $request) => FakeDaemonFiles::respond($request, ['logs/latest.log' => $this->log], [], ['logs/latest.log' => $this->modified])
            ?? Http::response('', 404, ['User-Agent' => 'Pelican Wings/v1.0.0']));
        Carbon::setTestNow('2026-11-09 12:30:00');
    }

    private static function at(string $time): Carbon
    {
        return Carbon::parse("2026-11-09 $time");
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . "/Fixtures/logs/$name.log");
    }

    /** Appends lines to the log, as the server would. */
    private function logs(string ...$lines): void
    {
        $this->log .= implode('', array_map(fn ($line) => "$line\n", $lines));
        $this->modified = Carbon::parse($this->modified)->addSecond()->toIso8601String();
    }

    private function scan(): void
    {
        $this->app->make(LogBackfill::class)->scan($this->server);
    }

    /** @param  list<array{name: string, uuid: string|null}>  $players */
    private function poll(string $time, array $players, SampleStatus $status = SampleStatus::Online): void
    {
        $sample = Sample::query()->create([
            'server_id' => $this->server->id,
            'polled_at' => self::at($time),
            'status' => $status,
            'online' => $status === SampleStatus::Online ? count($players) : null,
            'max' => 20,
            'players' => $status === SampleStatus::Online ? $players : null,
            'source' => 'query',
        ]);
        $this->app->make(SessionTracker::class)->track($this->server, $sample);
    }

    /** @return array{name: string, uuid: string} */
    private static function alice(): array
    {
        return ['name' => 'Alice', 'uuid' => self::A];
    }

    private function player(string $name): Player
    {
        return Player::query()->where('server_id', $this->server->id)->where('name', $name)->sole();
    }

    /** @return list<array{string, string|null, string}> [start, end, source] as H:i:s */
    private function sessions(string $name): array
    {
        return $this->player($name)->sessions()->orderBy('started_at')->get()
            ->map(fn (PlayerSession $s) => [$s->started_at->format('H:i:s'), $s->ended_at?->format('H:i:s'), $s->source->value])
            ->all();
    }

    /** Playtime is never counted twice: totals match the closed sessions, which never overlap. */
    private function assertConsistent(): void
    {
        foreach (Player::query()->with('sessions')->get() as $player) {
            $closed = $player->sessions->whereNotNull('ended_at')->sortBy('started_at')->values();
            $this->assertSame($closed->sum(fn (PlayerSession $s) => $s->seconds()), $player->total_seconds, "$player->name total");

            $previousEnd = null;
            foreach ($player->sessions->sortBy('started_at') as $session) {
                if ($previousEnd !== null) {
                    $this->assertTrue($session->started_at->greaterThanOrEqualTo($previousEnd), "$player->name sessions overlap");
                }
                $previousEnd = $session->ended_at ?? Carbon::create(9999);
            }
        }
    }

    public function test_a_log_join_moves_a_poll_start_back_to_the_exact_time(): void
    {
        $this->poll('12:02:00', [self::alice()]);
        $this->logs('[12:00:30] [User Authenticator #1/INFO]: UUID of player Alice is ' . self::A, '[12:00:31] [Server thread/INFO]: Alice joined the game');

        $this->scan();

        $this->assertSame([['12:00:31', null, 'log']], $this->sessions('Alice'));
        $this->assertTrue($this->player('Alice')->first_seen_at->eq(self::at('12:00:31')));
        $this->assertConsistent();
    }

    public function test_a_join_outside_the_window_does_not_move_the_poll_start(): void
    {
        $this->poll('12:10:00', [self::alice()]);
        $this->logs('[12:00:31] [Server thread/INFO]: Alice joined the game');

        $this->scan();

        $this->assertSame([['12:10:00', null, 'poll']], $this->sessions('Alice'));
        $this->assertSame(['alice'], array_keys(LogCursor::query()->sole()->pending ?? []), 'kept pending for its leave');
    }

    public function test_a_log_leave_moves_a_poll_end_back_and_corrects_playtime(): void
    {
        foreach (['12:02:00', '12:04:00', '12:06:00', '12:08:00', '12:10:00'] as $time) {
            $this->poll($time, [self::alice()]);
        }
        $this->poll('12:12:00', []);
        $this->logs('[12:00:31] [Server thread/INFO]: Alice joined the game', '[12:10:44] [Server thread/INFO]: Alice left the game');

        $this->scan();

        $this->assertSame([['12:00:31', '12:10:44', 'log']], $this->sessions('Alice'));
        $this->assertSame(613, $this->player('Alice')->total_seconds);
        $this->assertTrue($this->player('Alice')->last_seen_at->eq(self::at('12:10:44')));
        $this->assertConsistent();
    }

    public function test_a_log_leave_closes_the_open_poll_session(): void
    {
        $this->poll('12:02:00', [self::alice()]);
        $this->logs('[12:05:10] [Server thread/INFO]: Alice left the game');

        $this->scan();

        $this->assertSame([['12:02:00', '12:05:10', 'log']], $this->sessions('Alice'));
        $this->assertSame(190, $this->player('Alice')->total_seconds);

        // The next poll no longer sees Alice and has nothing left to close.
        $this->poll('12:06:00', []);
        $this->assertSame([['12:02:00', '12:05:10', 'log']], $this->sessions('Alice'));
        $this->assertConsistent();
    }

    public function test_a_join_seen_before_the_poll_waits_and_is_merged_later(): void
    {
        $this->logs('[12:00:31] [Server thread/INFO]: Alice joined the game');
        $this->scan();
        $this->assertSame(0, PlayerSession::query()->count());

        $this->poll('12:02:00', [self::alice()]);
        $this->scan(); // nothing new in the log; the pending join finds the poll session

        $this->assertSame([['12:00:31', null, 'log']], $this->sessions('Alice'));
        $this->assertNull(LogCursor::query()->sole()->pending);
        $this->assertConsistent();
    }

    public function test_a_fresh_install_backfills_earlier_joins_and_leaves(): void
    {
        $this->log = $this->fixture('vanilla')
            . "[12:15:00] [Server thread/INFO]: Bob joined the game\n"
            . "[12:20:00] [Server thread/INFO]: Bob left the game\n";

        $this->scan();

        $this->assertSame([['12:00:31', '12:10:44', 'log']], $this->sessions('Alice'));
        $this->assertSame([['12:15:00', '12:20:00', 'log']], $this->sessions('Bob'));
        $this->assertSame(self::A, $this->player('Alice')->uuid, 'from the UUID line');
        $this->assertSame(613, $this->player('Alice')->total_seconds);
        $this->assertSame(300, $this->player('Bob')->total_seconds);
        $this->assertConsistent();
    }

    public function test_scanning_the_same_log_again_adds_nothing(): void
    {
        $this->log = $this->fixture('vanilla');
        $this->scan();
        $before = PlayerSession::query()->get()->toArray();

        // Unchanged, re-downloaded (touched), and from scratch (cursor lost): all no-ops.
        $this->scan();
        $this->modified = '2026-11-09T12:31:00+00:00';
        $this->scan();
        LogCursor::query()->delete();
        $this->scan();

        $this->assertSame($before, PlayerSession::query()->get()->toArray());
        $this->assertSame(613, $this->player('Alice')->total_seconds);
        $this->assertConsistent();
    }

    public function test_a_rotated_log_restarts_at_zero_and_only_adds_its_own_events(): void
    {
        $this->log = $this->fixture('vanilla');
        $this->scan();

        $this->log = "[13:00:00] [Server thread/INFO]: Starting minecraft server version 1.21.4\n"
            . "[13:05:00] [Server thread/INFO]: Alice joined the game\n"
            . "[13:06:00] [Server thread/INFO]: Alice left the game\n";
        // The node's clock runs 36 minutes ahead of the panel (now is 12:30): still today.
        $this->modified = '2026-11-09T13:06:01+00:00';
        $this->scan();

        $this->assertSame([['12:00:31', '12:10:44', 'log'], ['13:05:00', '13:06:00', 'log']], $this->sessions('Alice'));
        $this->assertSame(strlen($this->log), LogCursor::query()->sole()->offset);
        $this->assertConsistent();
    }

    public function test_chat_spoofs_create_nothing(): void
    {
        $this->log = $this->fixture('spoof');

        $this->scan();

        $this->assertSame(0, Player::query()->count());
        $this->assertSame(0, PlayerSession::query()->count());
    }

    public function test_an_old_name_in_the_log_does_not_rename_the_player(): void
    {
        $this->poll('12:20:00', [['name' => 'Alicia', 'uuid' => self::A]]);
        $this->logs('[12:00:30] [User Authenticator #1/INFO]: UUID of player Alice is ' . self::A, '[12:00:31] [Server thread/INFO]: Alice joined the game', '[12:05:00] [Server thread/INFO]: Alice left the game');

        $this->scan();

        $player = Player::query()->sole();
        $this->assertSame('Alicia', $player->name);
        $this->assertSame(['Alice'], $player->previous_names);
        $this->assertSame([['12:00:31', '12:05:00', 'log'], ['12:20:00', null, 'poll']], $this->sessions('Alicia'));
        $this->assertConsistent();
    }

    public function test_no_ip_address_is_stored_in_any_pp_table(): void
    {
        $this->log = $this->fixture('vanilla') . $this->fixture('paper') . $this->fixture('forge');
        $this->poll('12:02:00', [self::alice()]);

        $this->scan();
        $this->assertGreaterThan(0, PlayerSession::query()->count());

        $tables = array_filter(array_column(Schema::getTables(), 'name'), fn ($table) => str_starts_with($table, 'pp_'));
        $this->assertContains('pp_log_cursors', $tables);
        foreach ($tables as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('203.0.113.7', $dump, $table);
            $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $dump, $table);
        }
    }

    public function test_sessions_from_poll_and_log_together_never_double_count(): void
    {
        // Alice rejoins between polls; Bob comes and goes between two polls entirely.
        $this->poll('12:00:00', []);
        $this->logs('[12:00:31] [Server thread/INFO]: Alice joined the game');
        $this->poll('12:02:00', [self::alice()]);
        $this->logs('[12:02:40] [Server thread/INFO]: Bob joined the game', '[12:03:10] [Server thread/INFO]: Alice left the game');
        $this->scan();
        $this->logs('[12:03:20] [Server thread/INFO]: Alice joined the game', '[12:03:50] [Server thread/INFO]: Bob left the game');
        $this->poll('12:04:00', [self::alice()]);
        $this->scan();
        $this->poll('12:06:00', []);
        $this->logs('[12:05:30] [Server thread/INFO]: Alice left the game');
        $this->scan();
        $this->scan();

        $this->assertSame([['12:00:31', '12:03:10', 'log'], ['12:03:20', '12:05:30', 'log']], $this->sessions('Alice'));
        $this->assertSame([['12:02:40', '12:03:50', 'log']], $this->sessions('Bob'));
        $this->assertSame(159 + 130, $this->player('Alice')->total_seconds);
        $this->assertConsistent();
    }

    public function test_a_lost_lock_changes_nothing_and_the_next_scan_catches_up(): void
    {
        $this->log = $this->fixture('vanilla');
        $lock = Cache::lock("players-plus.track.{$this->server->id}", 60);
        $lock->get();
        $backfill = $this->app->make(LogBackfill::class);
        $backfill->lockWaitSeconds = 0; // the lock's wait loop reads the frozen test clock

        $backfill->scan($this->server);
        $this->assertSame(0, LogCursor::query()->count());
        $this->assertSame(0, PlayerSession::query()->count());

        $lock->release();
        $this->scan();
        $this->assertSame([['12:00:31', '12:10:44', 'log']], $this->sessions('Alice'));
    }

    // ---- bounded work (plan 28 review) ---------------------------------------------------

    /** $count distinct players, each joining and leaving a second apart. */
    private static function flood(int $count, bool $joins = true, bool $leaves = true): string
    {
        $log = '';
        for ($i = 0; $i < $count; $i++) {
            [$in, $out] = [gmdate('H:i:s', 36000 + 2 * $i), gmdate('H:i:s', 36001 + 2 * $i)];
            $name = sprintf('Flood%05d', $i);
            $log .= $joins ? "[$in] [Server thread/INFO]: $name joined the game\n" : '';
            $log .= $leaves ? "[$out] [Server thread/INFO]: $name left the game\n" : '';
        }

        return $log;
    }

    public function test_a_flood_of_new_players_is_capped_per_scan_and_the_cursor_moves_on(): void
    {
        $this->log = self::flood(1000);

        $started = microtime(true);
        $this->scan();

        $this->assertSame(LogBackfill::MAX_NEW_PLAYERS, Player::query()->count());
        $cursor = LogCursor::query()->sole();
        $this->assertGreaterThan(0, $cursor->offset);
        $this->assertLessThan(strlen($this->log), $cursor->offset);
        $this->assertSame(0, $cursor->size, 'more to read');
        $this->assertSame(substr_count(substr($this->log, 0, $cursor->offset), "\n"), 2 * LogBackfill::MAX_NEW_PLAYERS, 'ends right after the 100th pair');

        $this->scan();
        $this->assertSame(2 * LogBackfill::MAX_NEW_PLAYERS, Player::query()->count());
        $this->assertSame(2 * LogBackfill::MAX_NEW_PLAYERS, PlayerSession::query()->count());
        $this->assertLessThan(30, microtime(true) - $started);
        $this->assertConsistent();
    }

    public function test_at_most_500_join_or_leave_lines_are_merged_per_scan(): void
    {
        $this->poll('12:02:00', [self::alice()]);
        $lines = '';
        for ($i = 0; $i < 400; $i++) {
            $time = gmdate('H:i:s', 36000 + 2 * $i);
            $lines .= "[$time] [Server thread/INFO]: Alice left the game\n[$time] [Server thread/INFO]: Alice joined the game\n";
        }
        $this->log = $lines;

        $this->scan();

        $cursor = LogCursor::query()->sole();
        $this->assertSame(LogBackfill::MAX_EVENTS, substr_count(substr($this->log, 0, $cursor->offset), "\n"));
        $this->scan();
        $this->assertSame(strlen($this->log), LogCursor::query()->sole()->offset);
    }

    public function test_lone_joins_and_leaves_of_unknown_players_create_no_rows(): void
    {
        $this->log = self::flood(300, joins: false) . self::flood(300, leaves: false);

        $this->scan();
        $this->scan();

        $this->assertSame(0, Player::query()->count());
        $this->assertSame(LogBackfill::MAX_PENDING, count(LogCursor::query()->sole()->pending ?? []));
    }

    public function test_the_merge_stops_at_its_time_budget_on_a_line_boundary(): void
    {
        $this->log = $this->fixture('vanilla') . "[12:15:00] [Server thread/INFO]: Bob joined the game\n[12:20:00] [Server thread/INFO]: Bob left the game\n";
        $backfill = $this->app->make(LogBackfill::class);
        $now = 0.0;
        $backfill->clock = function () use (&$now) {
            $t = $now;
            $now += LogBackfill::MERGE_SECONDS;

            return $t;
        };

        $backfill->scan($this->server);

        // Every clock reading costs the whole budget: the merge stops after the first join/leave.
        $cursor = LogCursor::query()->sole();
        $this->assertSame(0, PlayerSession::query()->count());
        $this->assertStringEndsWith("Alice joined the game\n", substr($this->log, 0, $cursor->offset));
        $this->assertSame(['alice'], array_keys($cursor->pending ?? []));

        $this->scan();
        $this->assertSame(2, PlayerSession::query()->count());
        $this->assertSame(strlen($this->log), LogCursor::query()->sole()->offset);
    }

    // ---- untrusted times (plan 28 review) ------------------------------------------------

    public function test_a_forged_chain_of_wraps_creates_nothing_in_the_future(): void
    {
        $chain = '';
        foreach (range(0, 9) as $day) {
            $chain .= sprintf("[%s] [Server thread/INFO]: tick\n", gmdate('H:i:s', 43000 - $day * 600));
        }
        $this->log = $chain . "[12:20:00] [Server thread/INFO]: Alice joined the game\n[12:25:00] [Server thread/INFO]: Alice left the game\n";

        $this->scan();

        foreach (PlayerSession::query()->get() as $session) {
            $this->assertTrue($session->started_at->lessThanOrEqualTo(now()->addMinutes(2)));
            $this->assertTrue(($session->ended_at ?? now())->lessThanOrEqualTo(now()->addMinutes(2)));
        }
        foreach (Player::query()->get() as $player) {
            $this->assertTrue($player->last_seen_at->lessThanOrEqualTo(now()->addMinutes(2)));
        }
        $this->assertTrue(LogCursor::query()->sole()->clock_at?->lessThanOrEqualTo(now()->addMinutes(2)));
    }

    public function test_future_and_out_of_range_neoforge_dates_are_ignored(): void
    {
        $this->log = "[09Nov2027 12:00:31.000] [Server thread/INFO] [x/]: Alice joined the game\n"
            . "[09Nov2027 12:10:00.000] [Server thread/INFO] [x/]: Alice left the game\n"
            . "[09Nov9999 12:00:31.000] [Server thread/INFO] [x/]: Bob joined the game\n"
            . "[09Nov9999 12:10:00.000] [Server thread/INFO] [x/]: Bob left the game\n"
            . "[09Nov2024 12:00:31.000] [Server thread/INFO] [x/]: Carol joined the game\n"
            . "[09Nov2024 12:10:00.000] [Server thread/INFO] [x/]: Carol left the game\n"
            . "[09Nov2026 12:00:31.000] [Server thread/INFO] [x/]: Dave joined the game\n"
            . "[09Nov2026 12:10:00.000] [Server thread/INFO] [x/]: Dave left the game\n";

        $this->scan();

        $this->assertSame(['Dave'], Player::query()->pluck('name')->all(), 'future, year-9999 and 2-year-old lines are dropped');
        $this->assertSame([['12:00:31', '12:10:00', 'log']], $this->sessions('Dave'));
    }

    public function test_a_node_clock_a_few_minutes_ahead_keeps_the_right_day(): void
    {
        // Now is 12:30 on the panel; the node's clock says 12:36.
        $this->log = "[12:34:00] [Server thread/INFO]: Alice joined the game\n[12:35:00] [Server thread/INFO]: Alice left the game\n";
        $this->modified = '2026-11-09T12:36:00+00:00';

        $this->scan();

        $this->assertSame([['2026-11-09 12:34:00', '2026-11-09 12:35:00']], PlayerSession::query()->get()
            ->map(fn (PlayerSession $s) => [$s->started_at->format('Y-m-d H:i:s'), $s->ended_at?->format('Y-m-d H:i:s')])->all());
    }

    public function test_times_far_ahead_of_the_panel_are_dropped_not_moved_a_day_back(): void
    {
        // The node's clock says 15:30 (three hours ahead): beyond the one-hour allowance.
        $this->log = "[15:20:00] [Server thread/INFO]: Alice joined the game\n[15:25:00] [Server thread/INFO]: Alice left the game\n";
        $this->modified = '2026-11-09T15:30:00+00:00';

        $this->scan();

        $this->assertSame(0, PlayerSession::query()->count());
        $this->assertSame(0, Player::query()->count());

        // Same with a modification time days ahead: nothing is dated in the future or a day early.
        $this->log = $this->fixture('vanilla');
        $this->modified = '2026-11-12T12:10:45+00:00';
        LogCursor::query()->delete();
        $this->scan();
        $this->assertSame(0, PlayerSession::query()->count());
    }

    public function test_an_old_log_scanned_over_several_capped_scans_keeps_every_session(): void
    {
        // Three days old; players overlap, so every cut leaves a join waiting for its leave.
        Carbon::setTestNow('2026-11-12 12:30:00');
        $this->modified = '2026-11-09T20:00:00+00:00';
        $log = '';
        for ($i = 0; $i < 300; $i++) {
            $log .= sprintf("[%s] [Server thread/INFO]: Old%05d joined the game\n", gmdate('H:i:s', 36000 + 60 * $i), $i);
            if ($i > 0) {
                $log .= sprintf("[%s] [Server thread/INFO]: Old%05d left the game\n", gmdate('H:i:s', 36030 + 60 * $i), $i - 1);
            }
        }
        $this->log = $log . sprintf("[%s] [Server thread/INFO]: Old00299 left the game\n", gmdate('H:i:s', 36000 + 60 * 300));

        for ($scan = 0; $scan < 6 && LogCursor::query()->first()?->offset !== strlen($this->log); $scan++) {
            $this->scan();
        }

        $this->assertSame(strlen($this->log), LogCursor::query()->sole()->offset);
        $this->assertSame(300, PlayerSession::query()->count());
        $this->assertSame(300, Player::query()->count());
        $this->assertConsistent();
    }

    public function test_pending_joins_expire_by_log_time_not_wall_clock(): void
    {
        Carbon::setTestNow('2026-11-12 12:30:00');
        $this->modified = '2026-11-09T12:00:00+00:00';
        $this->log = "[10:00:00] [Server thread/INFO]: Alice joined the game\n";
        $this->scan();
        $this->assertSame(['alice'], array_keys(LogCursor::query()->sole()->pending ?? []), 'three days old by the wall clock, but current in its log');

        $this->logs('[10:30:00] [Server thread/INFO]: Alice left the game');
        $this->modified = '2026-11-09T12:00:05+00:00';
        $this->scan();
        $this->assertSame(1, PlayerSession::query()->count());

        // A join 25 log-hours before the newest line is dropped.
        $this->logs('[11:00:00] [Server thread/INFO]: Bob joined the game', '[23:00:00] [Server thread/INFO]: tick', '[12:00:00] [Server thread/INFO]: tock');
        $this->modified = '2026-11-10T12:00:05+00:00';
        $this->scan();
        $this->assertNull(LogCursor::query()->sole()->pending);
    }

    public function test_the_usercache_is_skipped_when_the_scan_is_already_late(): void
    {
        $this->log = "[12:00:31] [Server thread/INFO]: Alice joined the game\n[12:05:00] [Server thread/INFO]: Alice left the game\n";
        $backfill = $this->app->make(LogBackfill::class);
        // The read took the whole allowance; the merge itself is quick.
        $calls = 0;
        $backfill->clock = function () use (&$calls) {
            return $calls++ === 0 ? 0.0 : (float) LogBackfill::USERCACHE_AFTER_SECONDS;
        };

        $backfill->scan($this->server);

        $this->assertSame(0, FakeDaemonFiles::contentReads('usercache.json'));
        $this->assertSame(0, Http::recorded(fn (Request $request) => FakeDaemonFiles::query($request, 'directory') === '/')->count(), 'no usercache listing either');
        $this->assertNull(Player::query()->sole()->uuid, 'name only until a later scan or poll');
    }

    public function test_a_log_without_a_newline_is_not_downloaded_again_while_unchanged(): void
    {
        $this->log = '[12:00:00] [Server thread/INFO]: Starting';

        $this->scan();
        $this->scan();

        $this->assertSame(1, FakeDaemonFiles::contentReads('latest.log'));
        $this->assertNull(LogCursor::query()->sole()->identity);

        $this->logs(' minecraft server', '[12:00:31] [Server thread/INFO]: Alice joined the game', '[12:01:00] [Server thread/INFO]: Alice left the game');
        $this->scan();
        $this->assertSame(1, PlayerSession::query()->count());
    }

    public function test_a_session_source_label_exists_for_log(): void
    {
        $this->assertSame('Log', SessionSource::Log->getLabel());
    }
}
