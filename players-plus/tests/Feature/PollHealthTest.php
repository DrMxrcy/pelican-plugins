<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Tests\Traits\Integration\CreatesTestModels;
use Carbon\CarbonImmutable;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Filament\Admin\Pages\PlayersOverviewPage;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\PlayersPlusPlugin;
use DrMxrcy\PlayersPlus\Support\PollHealth;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Callout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Plan 34 step 3: when no poll has run for three intervals (the queue worker or the scheduler is
 * not running), the admin overview and the plugin settings say so.
 */
class PollHealthTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** Wednesday 2026-11-11 14:37 UTC. */
    private const NOW = '2026-11-11 14:37:00';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        config()->set('players-plus.poll_interval', 2);
        config()->set('players-plus.enabled', true);
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        Filament::setCurrentPanel('admin');

        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @param  list<string>  $tags */
    private function server(array $tags = ['minecraft']): Server
    {
        $server = $this->createServerModel();
        $egg = $this->cloneEggAndVariables($server->egg);
        $egg->forceFill(['tags' => $tags])->save();
        $server->forceFill(['egg_id' => $egg->id])->save();

        return $server->refresh();
    }

    private function polledMinutesAgo(Server $server, int $minutes, SampleStatus $status = SampleStatus::Online): void
    {
        Sample::query()->create([
            'server_id' => $server->id,
            'polled_at' => now()->subMinutes($minutes),
            'status' => $status,
            'online' => $status === SampleStatus::Online ? 1 : null,
            'max' => $status === SampleStatus::Online ? 20 : null,
        ]);
    }

    private function settingsWarning(): ?Callout
    {
        foreach ((new PlayersPlusPlugin())->getSettingsForm() as $component) {
            if ($component instanceof Callout) {
                return $component;
            }
        }

        return null;
    }

    private function assertWarned(string $body): void
    {
        $this->assertSame($body, PollHealth::warning());

        Livewire::test(PlayersOverviewPage::class)
            ->assertOk()
            ->assertSee(trans('players-plus::strings.poll_health.stale_title'))
            ->assertSee($body);

        $callout = $this->settingsWarning();
        $this->assertNotNull($callout, 'settings page shows the warning');
        $this->assertSame(trans('players-plus::strings.poll_health.stale_title'), $callout->getHeading());
        $this->assertSame($body, $callout->getDescription());
    }

    private function assertNotWarned(): void
    {
        $this->assertNull(PollHealth::warning());

        Livewire::test(PlayersOverviewPage::class)
            ->assertOk()
            ->assertDontSee(trans('players-plus::strings.poll_health.stale_title'));

        $this->assertNull($this->settingsWarning());
    }

    public function test_recent_poll_shows_no_warning(): void
    {
        $this->polledMinutesAgo($this->server(), 2);

        $this->assertNotWarned();
    }

    public function test_offline_and_unreachable_polls_still_count_as_polls(): void
    {
        $this->polledMinutesAgo($this->server(), 2, SampleStatus::Offline);
        $this->polledMinutesAgo($this->server(), 30, SampleStatus::Online);

        $this->assertNotWarned();
    }

    public function test_last_poll_older_than_three_intervals_warns_on_overview_and_settings(): void
    {
        $server = $this->server();
        $this->polledMinutesAgo($server, 60);
        $this->polledMinutesAgo($server, 8);

        $this->assertSame('2026-11-11 14:29:00', PollHealth::lastPoll()?->toDateTimeString());
        $this->assertWarned(trans('players-plus::strings.poll_health.stale_body', [
            'time' => CarbonImmutable::parse('2026-11-11 14:29:00', 'UTC')->diffForHumans(),
            'minutes' => 2,
        ]));
    }

    public function test_three_intervals_is_the_threshold(): void
    {
        $server = $this->server();
        // 3 x 2 minutes, plus a minute for the poll job itself.
        $this->polledMinutesAgo($server, 7);
        $this->assertNull(PollHealth::warning());

        $this->travel(1)->minutes();
        $this->assertNotNull(PollHealth::warning());
    }

    public function test_threshold_follows_the_poll_interval(): void
    {
        config()->set('players-plus.poll_interval', 5);
        $this->polledMinutesAgo($this->server(), 15);
        $this->assertNull(PollHealth::warning());

        $this->travel(2)->minutes();
        $this->assertNotNull(PollHealth::warning());
    }

    public function test_never_polled_minecraft_server_warns(): void
    {
        $this->server();

        $this->assertNull(PollHealth::lastPoll());
        $this->assertWarned(trans('players-plus::strings.poll_health.never_body', ['minutes' => 2]));
    }

    public function test_no_minecraft_servers_no_warning(): void
    {
        $this->server(['source']);

        $this->assertNotWarned();
    }

    public function test_polls_of_non_minecraft_servers_do_not_count(): void
    {
        $this->polledMinutesAgo($this->server(), 30);
        // A server whose egg lost the minecraft tag is no longer polled; its old rows don't count.
        $this->polledMinutesAgo($this->server(['source']), 1);

        $this->assertSame('2026-11-11 14:07:00', PollHealth::lastPoll()?->toDateTimeString());
        $this->assertNotNull(PollHealth::warning());
    }

    public function test_disabled_polling_never_warns(): void
    {
        config()->set('players-plus.enabled', false);
        $this->polledMinutesAgo($this->server(), 600);

        $this->assertNotWarned();
    }
}
