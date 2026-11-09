<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Enums\ServerState;
use App\Exceptions\Service\Backup\TooManyBackupsException;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use App\Services\Backups\InitiateBackupService;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Console\Commands\WatchInstallsCommand;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Jobs\Steps\BackupStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\DownloadStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\InstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\PrepareStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Console\Application;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Plan 30 step 4: the install engine (backup → move aside → downloads → overrides →
 * finalize) against a fake daemon. Protected paths, resumability, cancel, deadlines.
 */
class InstallEngineTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
    }

    private function engine(): InstallEngine
    {
        return app(InstallEngine::class);
    }

    private function start(Server $server): PackInstall
    {
        return $this->engine()->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Test Pack', '1.0.0');
    }

    /** A server with an old pack, a world, player lists and a few unrelated files. */
    private function seedServer(): void
    {
        $this->files->seed([
            'mods/old-mod.jar' => 'old',
            'config/old.toml' => 'old config',
            'kubejs/startup.js' => 'old script',
            'world/level.dat' => 'level',
            'world/region/r.0.0.mca' => 'region',
            'world_nether/DIM-1/x' => 'nether',
            'ops.json' => '[{"name":"owner"}]',
            'whitelist.json' => '[]',
            'banned-players.json' => '[]',
            'banned-ips.json' => '[]',
            'server.properties' => "motd=hi\nlevel-name=world\n",
            'eula.txt' => 'eula=true',
        ]);
    }

    /** @return array<string, string> */
    private function protectedSnapshot(): array
    {
        return array_filter($this->files->files, fn (string $path) => preg_match('#^(world|world_nether|world_the_end)/|^(ops|whitelist|banned-players|banned-ips|usercache)\.json$|^server\.properties$#', $path) === 1, ARRAY_FILTER_USE_KEY);
    }

    private function runAll(Server $server): void
    {
        $this->runJobs(between: fn () => $this->finishBackups($server));
    }

    public function test_installs_a_matching_pack_end_to_end(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->seedServer();
        $protected = $this->protectedSnapshot();

        $this->start($server);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        foreach ($install->steps ?? [] as $step) {
            $this->assertContains($step['status'], [PackInstall::STEP_DONE, PackInstall::STEP_SKIPPED], $step['key']);
        }

        // Server-side mods are in mods/, the client-only one is not.
        $this->assertSame('sodium-bytes', $this->files->files['mods/sodium.jar'] ?? null);
        $this->assertSame('lithium-bytes', $this->files->files['mods/lithium.jar'] ?? null);
        $this->assertSame('github-bytes', $this->files->files['mods/from-github.jar'] ?? null);
        $this->assertArrayNotHasKey('mods/zoomify.jar', $this->files->files);

        // Overrides applied, server-overrides win, client-overrides ignored.
        $this->assertSame('server', $this->files->files['config/sodium.toml'] ?? null);
        $this->assertSame('{}', $this->files->files['config/extra/deep.json'] ?? null);
        $this->assertArrayNotHasKey('options.txt', $this->files->files);

        // The previous pack is moved aside, not deleted.
        $aside = (string) $install->moved_aside_path;
        $this->assertMatchesRegularExpression('#^\.modpacks-plus/previous-\d{8}-\d{6}-\d+$#', $aside);
        $this->assertSame('old', $this->files->files["$aside/mods/old-mod.jar"] ?? null);
        $this->assertSame('old config', $this->files->files["$aside/config/old.toml"] ?? null);
        $this->assertSame('old script', $this->files->files["$aside/kubejs/startup.js"] ?? null);
        $this->assertArrayNotHasKey('mods/old-mod.jar', $this->files->files);

        // Worlds, ops, whitelist, ban lists and server.properties are untouched.
        $this->assertSame($protected, $this->protectedSnapshot());
        $this->assertArrayNotHasKey('world/datapacks/x.zip', $this->files->files);
        $this->assertSame('eula=true', $this->files->files['eula.txt']);

        // One locked backup; github file read back, Modrinth files verified by lookup.
        $this->assertSame(1, $this->backupsCreated);
        $this->assertNotNull($install->backup_uuid);
        $this->assertSame(['mods/from-github.jar'], array_values(array_filter($this->files->callsOf('read'), fn ($path) => $path !== 'server.properties')));
        $this->assertSame([], $this->files->violations);

        $summary = $install->summary ?? [];
        $this->assertSame(3, $summary['downloaded']);
        $this->assertSame(3, $summary['files_total']);
        $this->assertSame(1, $summary['skipped_client_files']);
        $this->assertEqualsCanonicalizing(['ops.json', 'world/datapacks/x.zip'], $summary['skipped_overrides']);
        $this->assertDirectoryDoesNotExist($this->engine()->workDir($install));
    }

    public function test_zip_slip_pack_fails_before_anything_is_written(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack(['overrides/../evil' => 'pwned']);
        $this->seedServer();

        $this->start($server);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('unsafe_path', $install->error);
        $this->assertSame(0, $this->files->writeCalls());
        $this->assertSame(0, $this->backupsCreated);
        $this->assertSame([], $this->files->violations);
    }

    public function test_pack_for_another_loader_or_version_fails_before_anything_is_written(): void
    {
        foreach ([['minecraft' => '1.20.1', 'forge' => '47.2.0'], ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.0']] as $dependencies) {
            PackInstall::query()->delete();
            [, $server] = $this->scenarioServer();
            $this->scenarioPack(dependencies: $dependencies);

            $this->start($server);
            $this->runAll($server);

            $install = $this->latestInstall();
            $this->assertSame('target_mismatch', $install->error);
            $this->assertSame(0, $this->files->writeCalls());
            $this->assertSame(0, $this->backupsCreated);
        }
    }

    public function test_server_without_a_known_loader_or_exact_version_is_refused(): void
    {
        [, $server] = $this->scenarioServer(minecraft: 'latest');
        $this->scenarioPack();

        $this->start($server);
        $this->runAll($server);

        $this->assertSame('server_not_supported', $this->latestInstall()->error);
        $this->assertSame(0, $this->files->writeCalls());
    }

    public function test_a_nested_or_unsafe_level_name_is_protected_or_fails_closed(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->files->seed([
            'server.properties' => "level-name=config/world\n",
            'config/world/level.dat' => 'level',
            'config/old.toml' => 'old',
            'mods/old.jar' => 'old',
        ]);

        $this->start($server);
        $this->runAll($server);

        // config/ holds the world: it is kept in place, mods/ is still moved.
        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('level', $this->files->files['config/world/level.dat']);
        $this->assertSame('old', $this->files->files['config/old.toml']);
        $this->assertNotContains('config', $install->summary['moved'] ?? []);
        $this->assertContains('mods', $install->summary['moved'] ?? []);

        PackInstall::query()->delete();
        $this->files->seed(['server.properties' => "level-name=../elsewhere\n"]);
        $writes = $this->files->writeCalls();

        $this->start($server);
        $this->runAll($server);

        $this->assertSame('unsafe_level_name', $this->latestInstall()->error);
        $this->assertSame($writes, $this->files->writeCalls());
    }

    public function test_an_unreadable_server_properties_fails_closed(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->files->seed(['server.properties' => str_repeat('x', 2 * 1024 * 1024)]);

        $this->start($server);
        $this->runAll($server);

        $this->assertSame('server_properties_unreadable', $this->latestInstall()->error);
        $this->assertSame(0, $this->files->writeCalls());
    }

    public function test_impossible_backup_stops_the_install(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->seedServer();
        $this->backups->shouldReceive('handle')->andThrow(new TooManyBackupsException(0))->byDefault();
        $this->app->instance(InitiateBackupService::class, $this->tooManyBackups());

        $this->start($server);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame('backup_impossible', $install->error);
        $this->assertSame(0, $this->files->writeCalls());
        $this->assertSame('old', $this->files->files['mods/old-mod.jar']);
    }

    public function test_impossible_backup_is_skipped_when_the_admin_allows_it(): void
    {
        config()->set('modpacks-plus.allow_skip_backup', true);
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->app->instance(InitiateBackupService::class, $this->tooManyBackups());

        $this->start($server);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertSame(PackInstall::STEP_SKIPPED, $install->stepStatus('backup'));
        $this->assertTrue($install->summary['backup_skipped'] ?? false);
    }

    private function tooManyBackups(): MockInterface
    {
        $mock = Mockery::mock(InitiateBackupService::class);
        $mock->shouldReceive('setIsLocked')->andReturnSelf();
        $mock->shouldReceive('handle')->andThrow(new TooManyBackupsException(0));

        return $mock;
    }

    public function test_waits_for_the_backup_and_stops_if_it_fails(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->seedServer();

        $this->start($server);
        $this->runJobs(10);

        // Still waiting on the backup: nothing moved yet, the chain keeps polling.
        $install = $this->latestInstall();
        $this->assertTrue($install->isActive());
        $this->assertSame('backup', $install->current_step);
        $this->assertSame('old', $this->files->files['mods/old-mod.jar']);
        $this->assertSame(1, $this->backupsCreated);

        $this->finishBackups($server, successful: false);
        $this->runJobs();

        $this->assertSame('backup_failed', $this->latestInstall()->error);
        $this->assertSame('old', $this->files->files['mods/old-mod.jar']);
    }

    public function test_crash_after_backup_creation_reuses_that_backup(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        // The worker makes the backup, then dies before the uuid is stored.
        $mock = Mockery::mock(InitiateBackupService::class);
        $mock->shouldReceive('setIsLocked')->with(true)->andReturnSelf();
        $mock->shouldReceive('handle')->once()->andReturnUsing(function (Server $server, string $name) {
            $this->backupsCreated++;
            Backup::factory()->create([
                'server_id' => $server->id,
                'backup_host_id' => BackupHost::factory()->create()->getKey(),
                'name' => $name,
                'is_locked' => true,
                'is_successful' => true,
            ]);

            throw new RuntimeException('worker killed');
        });
        $this->app->instance(InitiateBackupService::class, $mock);

        $this->start($server);

        try {
            $this->runAll($server);
            $this->fail('The simulated crash did not happen');
        } catch (RuntimeException $exception) {
            $this->assertSame('worker killed', $exception->getMessage());
        }

        // The queue retries the same job.
        $install = $this->latestInstall();
        $this->assertNull($install->backup_uuid);
        $this->app->call([new BackupStep($install->id, $install->seq), 'handle']);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(1, $this->backupsCreated);
        $this->assertSame(Backup::query()->where('server_id', $server->id)->value('uuid'), $install->backup_uuid);
    }

    public function test_a_backup_someone_else_made_is_never_reused(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $host = BackupHost::factory()->create()->getKey();

        // Same-looking names, made by a subuser with backup.create or left from an earlier run.
        $foreign = [
            Backup::factory()->create(['server_id' => $server->id, 'backup_host_id' => $host, 'name' => 'Modpacks Plus install #1', 'is_locked' => true]),
            Backup::factory()->create(['server_id' => $server->id, 'backup_host_id' => $host, 'name' => 'Modpacks Plus install #2', 'is_locked' => true]),
        ];

        $this->start($server);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertSame(1, $this->backupsCreated);
        $this->assertNotContains($install->backup_uuid, array_map(fn ($backup) => $backup->getAttribute('uuid'), $foreign));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function untrustedRecoveryCandidates(): array
    {
        return [
            'unlocked' => [['is_locked' => false]],
            'older than the install' => [['created_at' => now()->subDay()]],
            'other nonce' => [['name_nonce' => 'ffffffffffffffffffffffffffffffff']],
        ];
    }

    /** @param  array<string, mixed>  $state */
    #[DataProvider('untrustedRecoveryCandidates')]
    public function test_crash_recovery_only_accepts_the_installs_own_locked_backup(array $state): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        $install = $this->start($server);
        $nonce = '0123456789abcdef0123456789abcdef';
        $install->setProgress('backup_nonce', $nonce);
        $install->save();

        $planted = Backup::factory()->create([
            'server_id' => $server->id,
            'backup_host_id' => BackupHost::factory()->create()->getKey(),
            'name' => BackupStep::backupName($install, $state['name_nonce'] ?? $nonce),
            'is_locked' => $state['is_locked'] ?? true,
            'created_at' => $state['created_at'] ?? now(),
        ]);

        $this->runAll($server);

        $install->refresh();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertSame(1, $this->backupsCreated);
        $this->assertNotSame($planted->getAttribute('uuid'), $install->backup_uuid);
    }

    public function test_worker_killed_mid_download_resumes_without_duplicate_downloads_or_backups(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->seedServer();

        $killed = false;
        $this->files->afterPull = function (string $url) use (&$killed) {
            if (!$killed && str_contains($url, 'lithium')) {
                $killed = true;

                throw new RuntimeException('worker killed');
            }
        };

        $this->start($server);

        try {
            $this->runAll($server);
            $this->fail('The simulated crash did not happen');
        } catch (RuntimeException $exception) {
            $this->assertSame('worker killed', $exception->getMessage());
        }

        // The queue retries the same job (same seq), as --tries=3 would.
        $install = $this->latestInstall();
        $this->assertTrue($install->isActive());
        $this->assertSame('download', $install->current_step);
        $retry = new DownloadStep($install->id, $install->seq);
        $this->app->call([$retry, 'handle']);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(1, $this->backupsCreated);
        $pulls = array_count_values($this->files->callsOf('pull'));
        $this->assertSame([1], array_values(array_unique($pulls)), (string) json_encode($pulls));
        $this->assertCount(3, $pulls);
    }

    public function test_a_stale_job_does_nothing(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        $install = $this->start($server);
        $this->runAll($server);

        $before = count($this->files->calls);
        $this->app->call([new PrepareStep($install->id, 1), 'handle']);
        $this->app->call([new DownloadStep($install->id, 3), 'handle']);

        $this->assertSame($before, count($this->files->calls));
        $this->assertSame(PackInstall::STATUS_INSTALLED, $this->latestInstall()->status);
    }

    public function test_an_old_job_of_the_current_step_does_nothing(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        $install = $this->start($server);
        $this->runJobs(4);

        // Polling the backup: the chain is a few hops further than the first backup job.
        $install->refresh();
        $this->assertSame('backup', $install->current_step);
        $this->assertGreaterThan(3, $install->seq);
        $seq = $install->seq;
        $pushed = count(Queue::pushedJobs()[BackupStep::class] ?? []);

        $this->finishBackups($server);
        $this->app->call([new BackupStep($install->id, 2), 'handle']);

        $this->assertSame($seq, $install->refresh()->seq);
        $this->assertSame('backup', $install->current_step);
        $this->assertCount($pushed, Queue::pushedJobs()[BackupStep::class] ?? []);
        $this->assertSame([], $this->files->callsOf('rename'));
    }

    public function test_timed_out_pull_is_checked_before_pulling_again(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        $timedOut = false;
        $this->files->afterPull = function (string $url) use (&$timedOut) {
            if (!$timedOut && str_contains($url, 'sodium')) {
                $timedOut = true;

                // The node finished, but the panel's request timed out.
                throw new ConnectionException('timeout');
            }
        };

        $this->start($server);
        $this->runAll($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $this->latestInstall()->status);
        $this->assertSame(1, array_count_values($this->files->callsOf('pull'))[$this->pack['sodium']['downloads'][0]]);
    }

    public function test_cancel_during_downloads_stops_after_the_current_batch(): void
    {
        config()->set('modpacks-plus.download_batch', 1);
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->seedServer();

        $this->start($server);
        $this->runJobs(between: function (InstallStep $job, PackInstall $install) use ($server) {
            $this->finishBackups($server);
            if ($job instanceof DownloadStep && (int) $install->progressValue('download_index', 0) === 1) {
                $this->engine()->requestCancel($install);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_CANCELLED, $install->status);
        $this->assertSame(PackInstall::STEP_CANCELLED, $install->stepStatus('download'));
        $this->assertSame(1, $install->summary['downloaded']);
        $this->assertSame(3, $install->summary['files_total']);
        $this->assertSame(0, $install->summary['overrides_applied']);
        $this->assertCount(1, $this->files->callsOf('pull'));
        $this->assertNotNull($install->summary['moved_aside_path']);
        $this->assertNotNull($install->summary['backup_uuid']);
    }

    public function test_readback_hash_mismatch_deletes_the_file_and_fails(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->files->remote[$this->pack['github']['downloads'][0]] = 'githuX-bytes';

        $this->start($server);
        $this->runAll($server);

        $this->assertSame('hash_mismatch', $this->latestInstall()->error);
        $this->assertArrayNotHasKey('mods/from-github.jar', $this->files->files);
    }

    public function test_size_mismatch_after_a_modrinth_pull_deletes_the_file_and_fails(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->files->remote[$this->pack['sodium']['downloads'][0]] = 'sodium-bytes-but-longer';

        $this->start($server);
        $this->runAll($server);

        $this->assertSame('download_incomplete', $this->latestInstall()->error);
        $this->assertArrayNotHasKey('mods/sodium.jar', $this->files->files);
    }

    public function test_a_stored_plan_tampered_with_is_rejected_before_overrides_are_written(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        $this->start($server);
        $this->runJobs(between: function (InstallStep $job, PackInstall $install) use ($server) {
            $this->finishBackups($server);
            if ($install->current_step === 'overrides' && is_array($install->plan)) {
                $plan = $install->plan;
                $plan['overrides'][0]['path'] = '../escape.toml';
                PackInstall::query()->whereKey($install->id)->update(['plan' => json_encode($plan)]);
            }
        });

        $this->assertSame('unsafe_path', $this->latestInstall()->error);
        $this->assertSame([], $this->files->callsOf('write'));
        $this->assertSame([], $this->files->violations);
    }

    public function test_file_operations_wait_while_the_server_is_in_a_conflict_state(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $server->forceFill(['status' => ServerState::Suspended])->save();

        $this->start($server);
        $this->runJobs(5);

        $install = $this->latestInstall();
        $this->assertTrue($install->isActive());
        $this->assertSame('prepare', $install->current_step);
        $this->assertSame([], $this->files->calls);
    }

    public function test_a_step_past_its_deadline_fails_the_install(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        $install = $this->start($server);
        $install->forceFill(['step_deadline_at' => now()->subMinute()])->save();
        $this->runJobs();

        $this->assertSame('step_timeout', $this->latestInstall()->error);
        $this->assertSame(PackInstall::STATUS_FAILED, $this->latestInstall()->status);
    }

    public function test_watchdog_resumes_a_lost_chain_then_fails_it_and_enforces_deadlines(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        $install = $this->start($server);
        // The first job was lost: nothing ran and the heartbeat went stale.
        $install->forceFill(['heartbeat_at' => now()->subMinutes(10)])->save();
        Queue::fake();

        // The panel skips plugin loading under test; register the command PluginService would.
        Application::starting(fn ($artisan) => $artisan->resolve(WatchInstallsCommand::class));
        $kernel = $this->app->make(Kernel::class);
        $kernel->setArtisan(null);
        $this->artisan('modpacks-plus:watch')->assertSuccessful();

        $install->refresh();
        $this->assertSame(1, $install->resumes);
        $this->assertSame(2, $install->seq);
        Queue::assertPushed(PrepareStep::class, fn (PrepareStep $job) => $job->seq === 2);

        // Resumed and lost again, MAX_RESUMES times: it fails instead of looping forever.
        $install->forceFill(['heartbeat_at' => now()->subMinutes(10), 'resumes' => InstallEngine::MAX_RESUMES])->save();
        $this->engine()->watchdog();
        $this->assertSame('stalled', $install->refresh()->error);

        // Deadline.
        PackInstall::query()->delete();
        $install = $this->start($server);
        $install->forceFill(['step_deadline_at' => now()->subSecond()])->save();
        $this->engine()->watchdog();
        $this->assertSame('step_timeout', $install->refresh()->error);
    }

    public function test_queue_exhaustion_fails_the_install(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();

        $install = $this->start($server);
        (new PrepareStep($install->id, $install->seq))->failed(new RuntimeException('boom'));

        $this->assertSame('unexpected', $install->refresh()->error);
    }

    public function test_refuses_a_second_install_and_the_sync_queue(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack();
        $this->start($server);

        try {
            $this->start($server);
            $this->fail('A second install was started');
        } catch (InstallRefusedException $exception) {
            $this->assertSame('already_running', $exception->reason);
        }

        PackInstall::query()->delete();
        config()->set('queue.default', 'sync');

        try {
            $this->start($server);
            $this->fail('An install was started on the sync queue');
        } catch (InstallRefusedException $exception) {
            $this->assertSame('queue_required', $exception->reason);
        }
    }

    public function test_an_override_differing_only_in_case_from_a_download_does_not_overwrite_an_existing_file(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack([
            'overrides/Mods/sodium.jar' => 'override bytes',
        ]);
        // A pre-existing file whose path differs from the downloaded mods/sodium.jar only in case.
        $this->files->seed(['Mods/sodium.jar' => 'existing bytes']);

        $this->start($server);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('sodium-bytes', $this->files->files['mods/sodium.jar']);
        $this->assertSame('override bytes', $this->files->files['Mods/sodium.jar']);
        $this->assertSame('existing bytes', $this->files->files[$install->moved_aside_path.'/Mods/sodium.jar'] ?? null);
    }

    public function test_a_file_in_the_way_is_moved_aside_not_overwritten(): void
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack([
            'overrides/server-icon.png' => 'pack icon',
        ]);
        $this->files->seed(['server-icon.png' => 'my icon']);

        $this->start($server);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertSame('pack icon', $this->files->files['server-icon.png']);
        $this->assertSame('my icon', $this->files->files[$install->moved_aside_path.'/server-icon.png'] ?? null);
    }
}
