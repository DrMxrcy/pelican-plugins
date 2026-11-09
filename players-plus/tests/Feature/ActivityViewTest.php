<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Console\Commands\RollupCommand;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\ActivityPage;
use DrMxrcy\PlayersPlus\Filament\Server\Widgets\ActivityHeatmapWidget;
use DrMxrcy\PlayersPlus\Filament\Server\Widgets\PlayerCountChartWidget;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConnector;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionMethod;

/** Plan 26 step 4: the Activity page renders from stored samples and rollups only. */
class ActivityViewTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private FakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');
        $this->connector = new FakeConnector();
        $this->app->instance(Connector::class, $this->connector);
        Http::fake();
        app()->setLocale('en');
        // Wednesday 2026-11-11 14:37 UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 14:37:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /**
     * @param  list<string>  $tags
     * @param  array<int, SubuserPermission>  $permissions  empty = the owner
     */
    private function account(array $tags = ['minecraft'], array $permissions = []): Server
    {
        [$user, $server] = $this->generateTestAccount($permissions);
        $egg = $this->cloneEggAndVariables($server->egg);
        $egg->forceFill(['tags' => $tags])->save();
        $server->forceFill(['egg_id' => $egg->id])->save();
        $server->refresh();

        $this->actingAs($user);
        Filament::setTenant($server);

        return $server;
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

    private function rollup(int $hours): void
    {
        $current = CarbonImmutable::now()->startOfHour();
        for ($i = $hours - 1; $i >= 0; $i--) {
            RollupCommand::rollupHour($current->subHours($i));
        }
    }

    /** @return array<string, mixed> */
    private function chartData(string $range): array
    {
        $widget = Livewire::test(PlayerCountChartWidget::class)->set('filter', $range)->instance();

        return (new ReflectionMethod($widget, 'getData'))->invoke($widget);
    }

    private function assertNothingContacted(): void
    {
        $this->assertSame([], $this->connector->opened, 'a page load must not query the game server');
        Http::assertNothingSent();
    }

    public function test_page_shows_peaks_chart_and_heatmap_from_stored_data(): void
    {
        $server = $this->account();
        $this->sample($server, '2026-11-09 10:00:00', SampleStatus::Online, 11);
        $this->sample($server, '2026-11-11 12:00:00', SampleStatus::Online, 3);
        $this->sample($server, '2026-11-11 12:02:00', SampleStatus::Online, 5);
        $this->sample($server, '2026-11-11 12:04:00', SampleStatus::Offline);
        $this->sample($server, '2026-11-11 12:06:00', SampleStatus::Unreachable);
        $this->rollup(72);

        $html = Livewire::test(ActivityPage::class)
            ->assertOk()
            ->assertSee(trans('players-plus::strings.activity.peak_today'))
            ->assertSee(trans('players-plus::strings.activity.peak_week'))
            ->assertSee(trans('players-plus::strings.activity.average_7d'))
            ->assertSee(trans('players-plus::strings.activity.chart_heading'))
            ->assertSee(trans('players-plus::strings.activity.heatmap_heading'))
            // (11 + 3 + 5) / 3 online samples; offline and unreachable are not zeros.
            ->assertSee('6.3')
            ->html();

        $this->assertSame(7 * 24, substr_count($html, 'data-pp-cell='), 'heatmap is 7 x 24');
        $this->assertSame(2, substr_count($html, 'data-pp-cell="filled"'), 'only Mon 10:00 and Wed 12:00 have data');
        $this->assertSame(7 * 24 - 2, substr_count($html, 'data-pp-cell="empty"'));
        $this->assertStringContainsString('Mon 10:00: 11.0 players on average', $html);

        $day = $this->chartData('24h');
        $points = array_combine($day['labels'], $day['datasets'][0]['data']);
        $this->assertSame([3, 5, null, null], [$points['12:00'], $points['12:02'], $points['12:04'], $points['12:06']]);
        $this->assertFalse($day['datasets'][0]['spanGaps']);

        $week = $this->chartData('7d');
        $this->assertCount(168, $week['labels']);
        $points = array_combine($week['labels'], $week['datasets'][0]['data']);
        $this->assertSame(4.0, $points['Wed 12:00']);
        $this->assertNull($points['Wed 13:00']);

        $this->assertNothingContacted();
    }

    public function test_heatmap_follows_the_viewer_timezone(): void
    {
        $server = $this->account();
        user()->forceFill(['timezone' => 'Europe/Berlin'])->save();
        $this->sample($server, '2026-11-11 12:00:00', SampleStatus::Online, 4);
        $this->rollup(3);

        $html = Livewire::test(ActivityHeatmapWidget::class)->html();

        // 12:00 UTC is 13:00 in Berlin (CET, UTC+1).
        $this->assertStringContainsString('Wed 13:00: 4.0 players on average', $html);
        $this->assertStringContainsString('Europe/Berlin', $html);
    }

    public function test_empty_history_renders_without_zeros(): void
    {
        $this->account();

        $html = Livewire::test(ActivityPage::class)->assertOk()->html();

        $this->assertSame(7 * 24, substr_count($html, 'data-pp-cell="empty"'));
        $this->assertSame([], array_filter($this->chartData('30d')['datasets'][0]['data'], fn ($v) => $v !== null));
    }

    public function test_page_render_with_30_days_of_data_stays_within_five_queries(): void
    {
        $server = $this->account();
        $this->seedMonth($server->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ActivityPage::class)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, count($queries), implode("\n", array_column($queries, 'query')));
        // The four ActivityStats queries ran: peaks (2), chart series, heatmap.
        $this->assertCount(4, array_filter($queries, fn (array $query) => str_contains($query['query'], '"pp_')));
        foreach ($queries as $query) {
            // Only the 24 h series and the recent peaks read raw samples, both time-bounded.
            if (str_contains($query['query'], 'pp_samples')) {
                $this->assertStringContainsString('"polled_at" >=', $query['query']);
            }
        }
    }

    /** 30 days of 2-minute samples (21,600), every 7th offline, rolled up hour by hour. */
    private function seedMonth(int $serverId): void
    {
        $start = CarbonImmutable::now()->startOfHour()->subDays(30);
        $rows = [];
        for ($i = 0; $i < 30 * 24 * 30; $i++) {
            $offline = $i % 7 === 0;
            $rows[] = [
                'server_id' => $serverId,
                'polled_at' => $start->addMinutes(2 * $i)->toDateTimeString(),
                'status' => $offline ? SampleStatus::Offline->value : SampleStatus::Online->value,
                'online' => $offline ? null : $i % 5,
                'max' => 20,
            ];
            if (count($rows) === 500) {
                Sample::query()->insert($rows);
                $rows = [];
            }
        }
        Sample::query()->insert($rows);
        $this->rollup(24 * 30 + 1);
        $this->assertSame(21600, Sample::query()->count());
    }

    public function test_non_minecraft_servers_have_no_activity_page(): void
    {
        $this->account(['source']);

        $this->assertFalse(ActivityPage::canAccess());
    }

    public function test_subuser_needs_console_access(): void
    {
        $this->account(['minecraft'], [SubuserPermission::FileRead]);

        $this->assertFalse(ActivityPage::canAccess());
        Livewire::test(ActivityPage::class)->assertForbidden();
    }

    public function test_subuser_with_console_access_can_view(): void
    {
        $this->account(['minecraft'], [SubuserPermission::WebsocketConnect]);

        $this->assertTrue(ActivityPage::canAccess());
        Livewire::test(ActivityPage::class)->assertOk();
    }
}
