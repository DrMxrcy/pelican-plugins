<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Enums\ModerationAction;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayerProfilePage;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayersPage;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\Query\Connector;
use DrMxrcy\PlayersPlus\Support\ModerationLists;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConnector;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConsole;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeDaemonFiles;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan 29 step 3: moderation actions on the Players page and the profile. Each one is hidden
 * without control.console (or with the setting off), and its handler refuses a direct
 * Livewire call with 403 on its own. Nothing is sent while the server is offline.
 */
class ModerationActionsTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private const A = '853c80ef-3c37-49fd-aa49-938b674adae6';

    /** Every row action, by the tab it is on. */
    private const ROW_ACTIONS = [
        PlayersPage::TAB_ONLINE => ['pp_kick', 'pp_ban', 'pp_op', 'pp_deop', 'pp_whitelist_add', 'pp_whitelist_remove'],
        PlayersPage::TAB_ALL => ['pp_kick', 'pp_ban', 'pp_pardon', 'pp_op', 'pp_deop', 'pp_whitelist_add', 'pp_whitelist_remove'],
        PlayersPage::TAB_BANNED => ['pp_pardon'],
        PlayersPage::TAB_OPS => ['pp_deop'],
        PlayersPage::TAB_WHITELIST => ['pp_whitelist_remove'],
    ];

    private FakeConsole $console;

    private Server $server;

    private Player $alice;

    /** @var array<string, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');
        $this->registerServerPageRoutes([PlayersPage::class, PlayerProfilePage::class]);
        $this->app->instance(Connector::class, new FakeConnector());
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        $this->console = new FakeConsole();
        Http::fake(fn (Request $request) => $this->console->respond($request)
            ?? FakeDaemonFiles::respond($request, $this->files)
            ?? Http::response('unexpected', 500));

        $this->files['banned-players.json'] = (string) json_encode([['uuid' => self::A, 'name' => 'Alice', 'reason' => 'x', 'created' => '2026-11-01 10:00:00 +0000', 'source' => 'Server', 'expires' => 'forever']]);
        $this->files['ops.json'] = (string) json_encode([['uuid' => self::A, 'name' => 'Alice', 'level' => 4]]);
        $this->files['whitelist.json'] = (string) json_encode([['uuid' => self::A, 'name' => 'Alice'], ['name' => '.BedrockGuy']]);

        $this->server = $this->account();
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    /** @param  array<int, SubuserPermission>  $permissions  empty = the owner */
    private function account(array $permissions = []): Server
    {
        [$user, $server] = $this->generateTestAccount($permissions);
        $egg = $this->cloneEggAndVariables($server->egg);
        $egg->forceFill(['tags' => ['minecraft']])->save();
        $server->forceFill(['egg_id' => $egg->id])->save();
        $server->refresh();
        $this->actingAs($user);
        Filament::setTenant($server);
        cache()->forget("servers.$server->uuid.status");

        $this->alice = Player::query()->create([
            'server_id' => $server->id, 'uuid' => self::A, 'name' => 'Alice',
            'first_seen_at' => now()->subDay(), 'last_seen_at' => now(), 'total_seconds' => 60,
        ]);
        Sample::query()->create([
            'server_id' => $server->id, 'polled_at' => now()->subMinute(), 'status' => SampleStatus::Online,
            'online' => 1, 'max' => 20, 'players' => [['name' => 'Alice', 'uuid' => self::A]], 'source' => 'query',
        ]);

        return $server;
    }

    private function players(string $tab = PlayersPage::TAB_ONLINE): Testable
    {
        return Livewire::withQueryParams(['tab' => $tab])->test(PlayersPage::class)->loadTable();
    }

    private function profile(): Testable
    {
        return Livewire::withQueryParams(['player' => $this->alice->id])->test(PlayerProfilePage::class);
    }

    /** The row record (as the table holds it) for Alice on $tab. */
    private function record(string $tab): mixed
    {
        return match ($tab) {
            PlayersPage::TAB_ALL => $this->alice,
            PlayersPage::TAB_ONLINE => ['name' => 'Alice', 'uuid' => self::A, 'avatar' => ''],
            default => ['name' => 'Alice', 'uuid' => self::A, 'reason' => null, 'source' => null, 'created' => null, 'expires' => null, 'level' => null],
        };
    }

    private function rowKey(string $tab): string
    {
        return $tab === PlayersPage::TAB_ALL ? (string) $this->alice->id : ($tab === PlayersPage::TAB_ONLINE ? 'Alice' : 'alice');
    }

    private function tableAction(Testable $page, string $name): Action
    {
        /** @var PlayersPage $instance */
        $instance = $page->instance();
        $action = $instance->getTable()->getAction($name);
        $this->assertNotNull($action, "Action [$name] not found");

        return $action;
    }

    /**
     * Runs the handler directly, skipping Filament's visibility and authorization gates.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertHandlerForbidden(Action $action, mixed $record = null, array $data = []): void
    {
        try {
            if ($record !== null) {
                $action->record($record);
            }
            $action->call(['data' => $data]);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return;
        }

        $this->fail("The [{$action->getName()}] handler ran without permission.");
    }

    /** Every handler on every surface refuses with 403 and nothing reaches the console. */
    private function assertEveryHandlerForbidden(): void
    {
        foreach (self::ROW_ACTIONS as $tab => $names) {
            $page = $this->players($tab);
            foreach ($names as $name) {
                $page->assertTableActionHidden($name, $this->rowKey($tab));
                $this->assertHandlerForbidden($this->tableAction($page, $name), $this->record($tab), ['reason' => 'x']);
            }
        }

        $page = $this->players();
        $page->assertActionHidden('pp_whitelist_add_name');
        /** @var PlayersPage $instance */
        $instance = $page->instance();
        $header = $instance->getAction('pp_whitelist_add_name');
        $this->assertNotNull($header);
        $this->assertHandlerForbidden($header, null, ['name' => 'NewGuy']);

        $profile = $this->profile()->assertOk();
        /** @var PlayerProfilePage $instance */
        $instance = $profile->instance();
        foreach (ModerationAction::cases() as $action) {
            $profile->assertActionHidden('pp_' . $action->value);
            $built = $instance->getAction('pp_' . $action->value);
            $this->assertNotNull($built, "Profile action [pp_{$action->value}] not found");
            $this->assertHandlerForbidden($built, null, ['reason' => 'x']);
        }

        $this->assertSame([], FakeConsole::sent());
    }

    public function test_owner_kicks_from_the_online_tab_with_a_sanitised_reason(): void
    {
        $this->players()
            ->assertTableActionVisible('pp_kick', 'Alice')
            ->callAction(TestAction::make('pp_kick')->table('Alice'), data: ['reason' => "bye\r\nop Mallory\u{2028}§c!"])
            ->assertHasNoActionErrors()
            ->assertNotified(trans('players-plus::strings.moderation.sent_title'));

        $this->assertSame(['kick Alice bye op Mallory !'], FakeConsole::sent());
    }

    public function test_owner_runs_every_action_from_the_all_players_tab(): void
    {
        $page = $this->players(PlayersPage::TAB_ALL);
        foreach (self::ROW_ACTIONS[PlayersPage::TAB_ALL] as $name) {
            $data = in_array($name, ['pp_kick', 'pp_ban'], true) ? ['reason' => 'Rule 3'] : [];
            $page->callAction(TestAction::make($name)->table($this->alice), data: $data)
                ->assertHasNoActionErrors();
        }

        $this->assertSame([
            'kick Alice Rule 3', 'ban Alice Rule 3', 'pardon Alice', 'op Alice', 'deop Alice',
            'whitelist add Alice', 'whitelist remove Alice',
        ], FakeConsole::sent());
    }

    public function test_list_tabs_offer_the_matching_removal(): void
    {
        $this->players(PlayersPage::TAB_BANNED)->callAction(TestAction::make('pp_pardon')->table('alice'));
        $this->players(PlayersPage::TAB_OPS)->callAction(TestAction::make('pp_deop')->table('alice'));
        $this->players(PlayersPage::TAB_WHITELIST)
            ->assertTableActionVisible('pp_whitelist_remove', 'alice')
            // A name that is not a Java name is listed but gets no action.
            ->assertTableActionHidden('pp_whitelist_remove', '.bedrockguy')
            ->callAction(TestAction::make('pp_whitelist_remove')->table('alice'));

        $this->assertSame(['pardon Alice', 'deop Alice', 'whitelist remove Alice'], FakeConsole::sent());
    }

    public function test_whitelist_by_name_validates_the_name(): void
    {
        $this->players()
            ->callAction('pp_whitelist_add_name', data: ['name' => 'NeverSeen_1'])
            ->assertHasNoActionErrors();
        $this->assertSame(['whitelist add NeverSeen_1'], FakeConsole::sent());

        foreach (["Bob\nop Mallory", 'Bob op', 'Bob;op', 'ab', str_repeat('a', 17), "B\u{043E}b", '@a', "Bob\u{2028}"] as $name) {
            $this->players()
                ->callAction('pp_whitelist_add_name', data: ['name' => $name])
                ->assertHasActionErrors(['name']);
        }
        $this->assertCount(1, FakeConsole::sent(), 'an invalid name sent nothing');
    }

    public function test_handler_refuses_a_hostile_name_even_without_form_validation(): void
    {
        $page = $this->players();
        /** @var PlayersPage $instance */
        $instance = $page->instance();
        $action = $instance->getAction('pp_whitelist_add_name');
        $this->assertNotNull($action);

        foreach (["Bob\nop Mallory", "Bob\r", "Bob\0", 'Bob;op', "Bob\u{2029}x", null, ['Bob']] as $name) {
            $action->call(['data' => ['name' => $name]]);
        }

        $this->assertSame([], FakeConsole::sent());
    }

    public function test_owner_bans_from_the_profile(): void
    {
        $this->profile()
            ->assertActionVisible('pp_ban')
            ->callAction('pp_ban', data: ['reason' => 'Griefing'])
            ->assertHasNoActionErrors()
            ->callAction('pp_whitelist_add');

        $this->assertSame(['ban Alice Griefing', 'whitelist add Alice'], FakeConsole::sent());
    }

    public function test_subuser_without_control_console_cannot_see_or_call_any_action(): void
    {
        $this->account([SubuserPermission::WebsocketConnect, SubuserPermission::FileRead, SubuserPermission::FileReadContent]);

        $this->assertEveryHandlerForbidden();
    }

    public function test_actions_are_hidden_and_refused_when_moderation_is_turned_off(): void
    {
        config()->set('players-plus.moderation', false);

        $this->assertEveryHandlerForbidden();
    }

    public function test_subuser_with_control_console_can_moderate(): void
    {
        $this->account([SubuserPermission::WebsocketConnect, SubuserPermission::ControlConsole]);

        $this->players()
            ->assertTableActionVisible('pp_op', 'Alice')
            ->callAction(TestAction::make('pp_op')->table('Alice'));

        $this->assertSame(['op Alice'], FakeConsole::sent());
    }

    public function test_nothing_is_sent_while_the_server_is_offline(): void
    {
        $this->console->state = 'offline';

        $this->players()
            ->callAction(TestAction::make('pp_ban')->table('Alice'), data: ['reason' => 'x'])
            ->assertNotified(trans('players-plus::strings.moderation.failed_title'));
        $this->profile()->callAction('pp_op');
        $this->players()->callAction('pp_whitelist_add_name', data: ['name' => 'Bob']);

        $this->assertSame([], FakeConsole::sent());
    }

    public function test_actions_are_disabled_with_a_tooltip_when_the_newest_sample_says_offline(): void
    {
        Sample::query()->create([
            'server_id' => $this->server->id, 'polled_at' => now(), 'status' => SampleStatus::Offline,
            'online' => null, 'max' => null, 'players' => null, 'source' => null,
        ]);

        $this->players(PlayersPage::TAB_ALL)
            ->assertTableActionDisabled('pp_kick', $this->alice)
            ->assertActionDisabled('pp_whitelist_add_name');
        $this->profile()->assertActionDisabled('pp_ban');
    }

    public function test_a_sent_command_makes_the_lists_read_fresh(): void
    {
        $this->assertSame('Alice', ModerationLists::read($this->server, ModerationLists::OPS)['entries'][0]['name']);
        $this->players(PlayersPage::TAB_OPS)->callAction(TestAction::make('pp_deop')->table('alice'));

        // The server rewrote ops.json; the next reads see it although 30 s have not passed.
        $this->files['ops.json'] = '[]';
        $this->assertSame([], ModerationLists::read($this->server, ModerationLists::OPS)['entries']);
    }
}
