<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayersPage;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConnector;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 27 step 3: the All players tab lists stored players only; nothing is contacted.
 */
class AllPlayersViewTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private FakeConnector $connector;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');
        $this->connector = new FakeConnector();
        $this->app->instance(Connector::class, $this->connector);
        Http::fake();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));

        [$user, $server] = $this->generateTestAccount();
        $egg = $this->cloneEggAndVariables($server->egg);
        $egg->forceFill(['tags' => ['minecraft']])->save();
        $server->forceFill(['egg_id' => $egg->id])->save();
        $this->server = $server->refresh();
        $this->actingAs($user);
        Filament::setTenant($this->server);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @param  list<array{0: int, 1: int|null}>  $sessions  [start, end] in minutes before now */
    private function player(string $name, ?string $uuid, array $sessions, ?Server $server = null): Player
    {
        $closed = array_sum(array_map(fn (array $s) => $s[1] === null ? 0 : ($s[0] - $s[1]) * 60, $sessions));
        $player = Player::query()->create([
            'server_id' => ($server ?? $this->server)->id,
            'uuid' => $uuid,
            'name' => $name,
            'first_seen_at' => now()->subMinutes(max(array_column($sessions, 0))),
            'last_seen_at' => now()->subMinutes(min(array_map(fn (array $s) => $s[1] ?? 0, $sessions))),
            'total_seconds' => $closed,
        ]);
        foreach ($sessions as [$start, $end]) {
            PlayerSession::query()->create([
                'player_id' => $player->id,
                'started_at' => now()->subMinutes($start),
                'ended_at' => $end === null ? null : now()->subMinutes($end),
            ]);
        }

        return $player;
    }

    public function test_all_players_tab_lists_this_servers_players_with_playtime_and_sessions(): void
    {
        $alice = $this->player('Alice', '853c80ef-3c37-49fd-aa49-938b674adae6', [[600, 540], [90, null]]);
        $bob = $this->player('Bob', null, [[3000, 2880]]);
        $stranger = $this->player('Stranger', null, [[60, 30]], $this->createServerModel());

        Livewire::withQueryParams(['tab' => 'all'])->test(PlayersPage::class)
            ->assertOk()
            ->loadTable()
            ->assertSee(trans('players-plus::strings.tabs.all'))
            ->assertCanSeeTableRecords([$alice, $bob])
            ->assertCanNotSeeTableRecords([$stranger])
            // Alice: 1 h closed + 1 h 30 m still open, counted live.
            ->assertSee('2h 30m')
            ->assertSee('2h')
            ->assertSee(trans('players-plus::strings.all.online'))
            ->assertSee('https://mc-heads.net/avatar/853c80ef-3c37-49fd-aa49-938b674adae6/32')
            ->assertSee('https://mc-heads.net/avatar/Bob/32');

        $this->assertSame([], $this->connector->opened);
        Http::assertNothingSent();
    }

    public function test_switching_tabs_swaps_the_table(): void
    {
        $alice = $this->player('Alice', null, [[600, 540]]);
        Sample::query()->create([
            'server_id' => $this->server->id, 'polled_at' => now()->subMinute(), 'status' => SampleStatus::Online,
            'online' => 0, 'max' => 20, 'players' => [], 'source' => 'query',
        ]);

        Livewire::test(PlayersPage::class)
            ->loadTable()
            ->assertSee(trans('players-plus::strings.page.empty'))
            ->assertDontSee('Alice')
            ->set('tab', 'all')
            ->loadTable()
            ->assertCanSeeTableRecords([$alice]);
    }

    public function test_all_players_shows_even_when_the_server_is_offline(): void
    {
        $alice = $this->player('Alice', null, [[600, 540]]);
        Sample::query()->create(['server_id' => $this->server->id, 'polled_at' => now()->subMinute(), 'status' => SampleStatus::Offline]);

        Livewire::withQueryParams(['tab' => 'all'])->test(PlayersPage::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$alice])
            ->assertDontSee(trans('players-plus::strings.page.offline_title'));
    }

    public function test_unknown_tab_falls_back_to_online(): void
    {
        Livewire::test(PlayersPage::class)
            ->set('tab', 'nope')
            ->assertSet('tab', 'online');
    }

    public function test_search_and_sort(): void
    {
        $alice = $this->player('Alice', null, [[600, 540]]);
        $bob = $this->player('Bob', null, [[3000, 2880]]);
        $carol = $this->player('Carol', null, [[100, 90]]);

        Livewire::withQueryParams(['tab' => 'all'])->test(PlayersPage::class)
            ->loadTable()
            ->searchTable('bo')
            ->assertCanSeeTableRecords([$bob])
            ->assertCanNotSeeTableRecords([$alice, $carol])
            ->searchTable('')
            ->sortTable('total_seconds', 'desc')
            ->assertCanSeeTableRecords([$bob, $alice, $carol], inOrder: true)
            ->sortTable('sessions_count')
            ->assertCanSeeTableRecords([$alice, $bob, $carol])
            ->sortTable('first_seen_at', 'desc')
            ->assertCanSeeTableRecords([$carol, $alice, $bob], inOrder: true);
    }

    public function test_partial_ping_sample_notes_that_tracking_is_partial(): void
    {
        $this->player('Alice', null, [[60, null]]);
        Sample::query()->create([
            'server_id' => $this->server->id, 'polled_at' => now()->subMinute(), 'status' => SampleStatus::Online,
            'online' => 30, 'max' => 50, 'players' => [['name' => 'Alice', 'uuid' => null]], 'source' => 'ping',
        ]);

        Livewire::withQueryParams(['tab' => 'all'])->test(PlayersPage::class)
            ->loadTable()
            ->assertSee(trans('players-plus::strings.all.partial'));
    }
}
