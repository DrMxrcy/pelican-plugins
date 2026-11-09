<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Filament\Server\Pages\Console;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayersPage;
use DrMxrcy\PlayersPlus\Filament\Server\Widgets\OnlinePlayersWidget;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\PlayersPlusPlugin;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConnector;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Plan 25 step 4: the Players page and console widget render from stored samples only.
 * Http (daemon) and sockets (game server) are faked and asserted untouched.
 */
class OnlineViewTest extends TestCase
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

    /** @param  list<array{name: string, uuid: string|null}>  $players */
    private function sample(Server $server, SampleStatus $status, ?int $online = null, ?int $max = null, array $players = [], string $source = 'ping', int $minutesAgo = 1): Sample
    {
        return Sample::query()->create([
            'server_id' => $server->id,
            'polled_at' => now()->subMinutes($minutesAgo),
            'status' => $status,
            'online' => $online,
            'max' => $max,
            'players' => $status === SampleStatus::Online ? $players : null,
            'source' => $status === SampleStatus::Online ? $source : null,
        ]);
    }

    private function assertNothingContacted(): void
    {
        $this->assertSame([], $this->connector->opened, 'a page load must not query the game server');
        Http::assertNothingSent();
    }

    public function test_page_lists_online_players_with_avatars_from_the_latest_sample(): void
    {
        $server = $this->account();
        $this->sample($server, SampleStatus::Online, 1, 20, [['name' => 'Old', 'uuid' => null]], minutesAgo: 10);
        $this->sample($server, SampleStatus::Online, 2, 20, [
            ['name' => 'jeb_', 'uuid' => '853c80ef-3c37-49fd-aa49-938b674adae6'],
            ['name' => 'Notch', 'uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5'],
        ]);

        Livewire::test(PlayersPage::class)
            ->assertOk()
            ->loadTable()
            ->assertSee(trans('players-plus::strings.page.online_now'))
            ->assertSee('2 / 20')
            ->assertSee('Notch')
            ->assertSee('jeb_')
            ->assertSee('https://mc-heads.net/avatar/069a79f4-44e9-4726-a5be-fca90e38aaf5/32')
            ->assertDontSee('Old');

        $this->assertNothingContacted();
    }

    public function test_offline_server_says_offline_instead_of_zero_players(): void
    {
        $server = $this->account();
        $this->sample($server, SampleStatus::Offline);

        Livewire::test(PlayersPage::class)
            ->assertSee(trans('players-plus::strings.page.offline_title'))
            ->assertDontSee(trans('players-plus::strings.page.empty'))
            ->assertDontSee(trans('players-plus::strings.page.online_now'));

        $this->assertNothingContacted();
    }

    public function test_unreachable_and_no_data_states(): void
    {
        $server = $this->account();

        Livewire::test(PlayersPage::class)->assertSee(trans('players-plus::strings.page.no_data_title'));

        $this->sample($server, SampleStatus::Unreachable);
        Livewire::test(PlayersPage::class)->assertSee(trans('players-plus::strings.page.unreachable_title'));

        $this->assertNothingContacted();
    }

    public function test_empty_server_shows_nobody_online(): void
    {
        $server = $this->account();
        $this->sample($server, SampleStatus::Online, 0, 20);

        Livewire::test(PlayersPage::class)->loadTable()
            ->assertSee('0 / 20')
            ->assertSee(trans('players-plus::strings.page.empty'));
    }

    public function test_partial_ping_list_and_stale_data_are_flagged(): void
    {
        $server = $this->account();
        $this->sample($server, SampleStatus::Online, 30, 50, [['name' => 'Notch', 'uuid' => null]], minutesAgo: 30);

        Livewire::test(PlayersPage::class)->loadTable()
            ->assertSee('30 / 50')
            ->assertSee('enable-query=true')
            ->assertSee(trans('players-plus::strings.page.stale_title'));
    }

    public function test_page_is_hidden_for_other_games(): void
    {
        $this->account(['source']);

        $this->assertFalse(PlayersPage::canAccess());
    }

    public function test_page_needs_console_access(): void
    {
        $this->account(['minecraft'], [SubuserPermission::FileRead]);

        $this->assertFalse(PlayersPage::canAccess());
        Livewire::test(PlayersPage::class)->assertForbidden();
    }

    public function test_console_readers_can_open_the_page(): void
    {
        $this->account(['minecraft'], [SubuserPermission::WebsocketConnect]);

        $this->assertTrue(PlayersPage::canAccess());
    }

    public function test_widget_shows_count_or_status(): void
    {
        $server = $this->account();

        Livewire::test(OnlinePlayersWidget::class, ['server' => $server])->assertSee(trans('players-plus::strings.widget.no_data'));

        $this->sample($server, SampleStatus::Online, 1, 20, [['name' => 'Notch', 'uuid' => null]]);
        Livewire::test(OnlinePlayersWidget::class, ['server' => $server])
            ->assertSee(trans('players-plus::strings.widget.label'))
            ->assertSee('1 / 20');

        $this->sample($server, SampleStatus::Offline, minutesAgo: 0);
        Livewire::test(OnlinePlayersWidget::class, ['server' => $server])
            ->assertSee(trans('players-plus::strings.status.offline'))
            ->assertDontSee('1 / 20');

        $this->assertNothingContacted();
    }

    public function test_widget_hidden_for_other_games(): void
    {
        $this->account(['source']);

        $this->assertFalse(OnlinePlayersWidget::canView());
    }

    public function test_plugin_registers_the_console_widget(): void
    {
        (new PlayersPlusPlugin())->register(Filament::getPanel('server'));

        $this->assertContains(OnlinePlayersWidget::class, (new Console())->getWidgets());
    }
}
