<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Enums\ServerState;
use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\ServerAvailability;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 39 (live 2026-11-10): after a failed reinstall the server stays reinstall_failed, and a
 * new install sat on "waiting_conflict" until its step deadline. Stuck states are refused up
 * front and fail a running install at once; busy states (a backup restore) are still waited out.
 */
class ServerAvailabilityTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
    }

    private function server(): Server
    {
        [, $server] = $this->scenarioServer();
        $this->files->seed(['mods/old.jar' => 'old', 'world/level.dat' => 'level', 'server.properties' => "level-name=world\n"]);

        return $server;
    }

    private function setStatus(Server $server, ?ServerState $status): Server
    {
        Server::query()->whereKey($server->id)->update(['status' => $status?->value]);

        return $server->fresh();
    }

    private function start(Server $server): PackInstall
    {
        return app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Test Pack', '1.0.0');
    }

    /** @return array<string, array{ServerState, string}> */
    public static function blockedStates(): array
    {
        return [
            'install failed' => [ServerState::InstallFailed, ServerAvailability::FAILED_INSTALL],
            'reinstall failed' => [ServerState::ReinstallFailed, ServerAvailability::FAILED_INSTALL],
            'suspended' => [ServerState::Suspended, ServerAvailability::SUSPENDED],
        ];
    }

    #[DataProvider('blockedStates')]
    public function test_an_install_is_refused_with_the_reason_while_the_server_is_blocked(ServerState $state, string $expected): void
    {
        $server = $this->setStatus($this->server(), $state);

        try {
            $this->start($server);
            $this->fail('the install was started');
        } catch (InstallRefusedException $exception) {
            $this->assertSame('server_unavailable', $exception->reason);
            $this->assertSame($expected, $exception->detail);
            $this->assertSame(trans('modpacks-plus::strings.install.server_'.$expected), InstallProgress::reason($exception->reason, $exception->detail));
        }

        $this->assertSame(0, PackInstall::query()->count());
        $this->assertSame(0, $this->backupsCreated);
    }

    public function test_a_healthy_server_takes_the_install(): void
    {
        $server = $this->server();
        $this->scenarioPack();

        $this->assertNull(ServerAvailability::stuck($server));
        $this->assertSame(PackInstall::STATUS_INSTALLING, $this->start($server)->status);
    }

    /** Busy states end by themselves, so an install may still be queued behind them. */
    public function test_a_busy_server_still_takes_the_install_and_it_waits(): void
    {
        $server = $this->setStatus($this->server(), ServerState::RestoringBackup);
        $this->scenarioPack();

        $this->assertNull(ServerAvailability::stuck($server));
        $this->assertSame(PackInstall::STATUS_INSTALLING, $this->start($server)->status);
        $this->runJobs(1);
        $this->assertContains('waiting_conflict', array_column($this->latestInstall()->log ?? [], 'key'));
    }

    public function test_a_running_install_fails_at_once_when_the_reinstall_failed(): void
    {
        $server = $this->server();
        $this->scenarioPack();
        $this->start($server);
        $before = $this->files->files;
        $this->setStatus($server, ServerState::ReinstallFailed);

        $this->runJobs(1);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('server_unavailable', $install->error);
        $this->assertStringContainsString('Reinstall the server', (string) InstallProgress::error($install));
        $this->assertNotContains('waiting_conflict', array_column($install->log ?? [], 'key'));
        $this->assertSame($before, $this->files->files, 'nothing touched');
        $this->assertSame(0, $this->backupsCreated);
    }

    public function test_a_running_install_still_waits_while_a_backup_is_restored(): void
    {
        $server = $this->server();
        $this->scenarioPack();
        $this->start($server);
        $this->setStatus($server, ServerState::RestoringBackup);

        $this->runJobs(1);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLING, $install->status);
        $this->assertContains('waiting_conflict', array_column($install->log ?? [], 'key'));
    }
}
