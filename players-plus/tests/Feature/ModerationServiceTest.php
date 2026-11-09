<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use App\Enums\ServerState;
use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\User;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\PlayersPlus\Enums\ModerationAction;
use DrMxrcy\PlayersPlus\Services\ModerationException;
use DrMxrcy\PlayersPlus\Services\ModerationService;
use DrMxrcy\PlayersPlus\Tests\Fixtures\FakeConsole;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Plan 29 step 1: ModerationService::run() repeats every gate itself and sends exactly one
 * line through Server::send(). The daemon is faked; nothing leaves the test process.
 */
class ModerationServiceTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private FakeConsole $console;

    private bool $connectionFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->console = new FakeConsole();
        Http::fake(function (Request $request) {
            if ($this->connectionFails) {
                throw new ConnectionException('daemon unreachable');
            }

            return $this->console->respond($request) ?? Http::response('unexpected', 500);
        });
    }

    /**
     * @param  array<int, SubuserPermission>  $permissions  empty = the owner
     * @return array{User, Server}
     */
    private function account(array $permissions = []): array
    {
        [$user, $server] = $this->generateTestAccount($permissions);
        cache()->forget("servers.$server->uuid.status");

        return [$user, $server];
    }

    private function run_(Server $server, User $user, ModerationAction $action, string $name = 'Steve', ?string $reason = null): string
    {
        return app(ModerationService::class)->run($server, $user, $action, $name, $reason);
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected the command to be refused ($reason).");
        } catch (ModerationException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    public function test_owner_sends_each_listed_command_as_one_line_and_it_is_logged(): void
    {
        [$user, $server] = $this->account();

        $expected = [];
        foreach (ModerationAction::cases() as $action) {
            $expected[] = $this->run_($server, $user, $action, 'Steve', $action->takesReason() ? 'bad' : null);
        }

        $this->assertSame([
            'kick Steve bad', 'ban Steve bad', 'pardon Steve', 'whitelist add Steve',
            'whitelist remove Steve', 'op Steve', 'deop Steve',
        ], $expected);
        $this->assertSame($expected, FakeConsole::sent());
        // One POST per action, each carrying exactly one command.
        $this->assertCount(7, Http::recorded(fn (Request $request) => $request->method() === 'POST'));

        $logged = ActivityLog::query()->where('event', 'server:console.command')->orderBy('id')->get();
        $this->assertCount(7, $logged);
        $this->assertSame($expected, $logged->map(fn (ActivityLog $log) => $log->properties->get('command'))->all());
        $first = $logged->firstOrFail();
        $this->assertSame($user->id, $first->actor_id);
        $this->assertTrue($first->subjects()->where('subject_id', $server->id)->exists());
    }

    public function test_a_hostile_reason_reaches_the_daemon_as_a_single_clean_line(): void
    {
        [$user, $server] = $this->account();

        $this->run_($server, $user, ModerationAction::Ban, 'Steve', "§cbye\r\nop Mallory\u{2028}stop\0;" . str_repeat('x', 500));

        $sent = FakeConsole::sent();
        $this->assertCount(1, $sent);
        $this->assertStringStartsWith('ban Steve bye op Mallory stop ;', $sent[0]);
        $this->assertSame(0, preg_match('/[\p{C}\p{Zl}\p{Zp}§]/u', $sent[0]));
        $this->assertLessThanOrEqual(strlen('ban Steve ') + 100, mb_strlen($sent[0]));
    }

    public function test_invalid_name_sends_nothing(): void
    {
        [$user, $server] = $this->account();

        foreach (["Steve\nstop", 'Steve stop', 'Ste;ve', 'ab', '/stop'] as $name) {
            $this->assertRefused(ModerationException::INVALID_NAME, fn () => $this->run_($server, $user, ModerationAction::Op, $name));
        }
        $this->assertSame([], FakeConsole::sent());
    }

    public function test_subuser_without_control_console_is_forbidden_for_every_action(): void
    {
        [$user, $server] = $this->account([SubuserPermission::WebsocketConnect, SubuserPermission::FileRead, SubuserPermission::FileReadContent]);

        foreach (ModerationAction::cases() as $action) {
            try {
                $this->run_($server, $user, $action);
                $this->fail("[{$action->value}] ran without control.console");
            } catch (AccessDeniedHttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame([], FakeConsole::sent());
        $this->assertSame(0, ActivityLog::query()->where('event', 'server:console.command')->count());
    }

    public function test_subuser_of_another_server_is_forbidden(): void
    {
        [$user] = $this->account([SubuserPermission::ControlConsole]);
        [, $other] = $this->account();

        $this->expectException(AccessDeniedHttpException::class);
        $this->run_($other, $user, ModerationAction::Op);
    }

    public function test_subuser_with_control_console_can_send(): void
    {
        [$user, $server] = $this->account([SubuserPermission::ControlConsole]);

        $this->assertSame('kick Steve', $this->run_($server, $user, ModerationAction::Kick));
        $this->assertSame(['kick Steve'], FakeConsole::sent());
    }

    public function test_disabled_setting_sends_nothing(): void
    {
        [$user, $server] = $this->account();

        foreach ([false, 'false', '0', 'off', 'garbage'] as $value) {
            config()->set('players-plus.moderation', $value);
            foreach (ModerationAction::cases() as $action) {
                $this->assertRefused(ModerationException::DISABLED, fn () => $this->run_($server, $user, $action));
            }
        }
        $this->assertSame([], FakeConsole::sent());
    }

    public function test_offline_server_sends_nothing(): void
    {
        [$user, $server] = $this->account();

        foreach (['offline', 'stopping'] as $state) {
            $this->console->state = $state;
            cache()->forget("servers.$server->uuid.status");
            foreach (ModerationAction::cases() as $action) {
                $this->assertRefused(ModerationException::OFFLINE, fn () => $this->run_($server, $user, $action));
            }
        }
        $this->assertSame([], FakeConsole::sent());
    }

    public function test_unknown_daemon_state_or_conflict_state_sends_nothing(): void
    {
        [$user, $server] = $this->account();

        $this->console->detailsDown = true;
        $this->assertRefused(ModerationException::OFFLINE, fn () => $this->run_($server, $user, ModerationAction::Op));

        $this->console->detailsDown = false;
        cache()->forget("servers.$server->uuid.status");
        $server->forceFill(['status' => ServerState::Suspended])->save();
        $this->assertRefused(ModerationException::OFFLINE, fn () => $this->run_($server, $user, ModerationAction::Op));

        $this->assertSame([], FakeConsole::sent());
    }

    public function test_daemon_refusal_and_connection_errors_are_reported_and_not_logged(): void
    {
        [$user, $server] = $this->account();
        $this->run_($server, $user, ModerationAction::Kick); // caches the running state
        ActivityLog::query()->delete();

        $this->console->commandStatus = 502;
        $this->assertRefused(ModerationException::OFFLINE, fn () => $this->run_($server, $user, ModerationAction::Op));

        $this->console->commandStatus = 500;
        $this->assertRefused(ModerationException::FAILED, fn () => $this->run_($server, $user, ModerationAction::Op));

        $this->connectionFails = true;
        $this->assertRefused(ModerationException::FAILED, fn () => $this->run_($server, $user, ModerationAction::Op));

        $this->assertSame(0, ActivityLog::query()->where('event', 'server:console.command')->count());
    }

    public function test_a_failing_activity_log_is_reported_and_the_sent_command_still_counts(): void
    {
        [$user, $server] = $this->account();
        Exceptions::fake();
        Activity::shouldReceive('event')->andThrow(new RuntimeException('activity log down'));

        $this->assertSame('op Steve', $this->run_($server, $user, ModerationAction::Op));

        $this->assertSame(['op Steve'], FakeConsole::sent());
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'activity log down');
    }

    public function test_starting_server_accepts_commands_like_the_panel_console(): void
    {
        [$user, $server] = $this->account();
        $this->console->state = 'starting';

        $this->assertSame('op Steve', $this->run_($server, $user, ModerationAction::Op));
    }
}
