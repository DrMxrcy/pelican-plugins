<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Enums\ServerState;
use App\Exceptions\Service\Backup\TooManyBackupsException;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ReinstallServerService;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Jobs\RestorePreviousFilesJob;
use DrMxrcy\ModpacksPlus\Jobs\Steps\AwaitReinstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\ChangeEggStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\DownloadStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\InstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\PreserveStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\ReinstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\RestorePreservedStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\SnapshotStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Plan 35 step 3: an install that switches the loader. Snapshot, worlds and player files
 * set aside, egg change, reinstall and wait, files back, the pack install; failure,
 * deadline, cancel and crash paths; "Restore previous files". Daemon and HTTP faked.
 */
class LoaderSwitchTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private Egg $neoforge;

    private int $reinstalls = 0;

    /** What the node reports when the reinstall ends: null = success, or a failed state. */
    private ?ServerState $reinstallOutcome = null;

    /** Thrown by the reinstall service after it marked the server installing (a crash). */
    private ?\Throwable $reinstallCrash = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();

        $this->reinstalls = 0;
        $reinstall = Mockery::mock(ReinstallServerService::class);
        $reinstall->shouldReceive('handle')->andReturnUsing(function (Server $server) {
            $this->reinstalls++;
            $server->forceFill(['status' => ServerState::Installing])->save();
            if ($this->reinstallCrash !== null) {
                throw $this->reinstallCrash;
            }

            return $server;
        });
        $this->app->instance(ReinstallServerService::class, $reinstall);

        $this->neoforge = $this->makeNeoForgeEgg();
    }

    private function makeNeoForgeEgg(): Egg
    {
        /** @var Egg $egg */
        $egg = Egg::factory()->create([
            'uuid' => LoaderEggResolverTest::NEOFORGE_UUID,
            'name' => 'NeoForge',
            'tags' => ['minecraft'],
            'update_url' => LoaderEggResolverTest::NEOFORGE_URL,
            'startup_commands' => ['Default' => 'java @unix_args.txt'],
            'docker_images' => [
                'Java 8' => 'ghcr.io/pelican-eggs/yolks:java_8',
                'Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17',
                'Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21',
            ],
        ]);
        EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'MC_VERSION', 'default_value' => 'latest', 'rules' => ['required', 'string', 'max:9']]);
        EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'NEOFORGE_VERSION', 'default_value' => '', 'rules' => ['nullable', 'regex:/^[0-9\.\-]+$/']]);

        return $egg->fresh() ?? $egg;
    }

    /** A Fabric 1.20.1 server with an old pack, a world, player lists and a NeoForge 1.21.1 pack on offer. */
    private function fabricServer(): Server
    {
        [, $server] = $this->scenarioServer();
        $this->scenarioPack(null, ['minecraft' => '1.21.1', 'neoforge' => '21.1.77']);
        $this->files->seed([
            'mods/old-mod.jar' => 'old',
            'config/old.toml' => 'old config',
            'world/level.dat' => 'level',
            'world/region/r.0.0.mca' => 'region',
            'world_nether/DIM-1/x' => 'nether',
            'ops.json' => '[{"name":"owner"}]',
            'whitelist.json' => '["friend"]',
            'banned-players.json' => '[]',
            'banned-ips.json' => '[]',
            'usercache.json' => '[]',
            'server.properties' => "motd=hi\nlevel-name=world\n",
            'eula.txt' => 'eula=true',
            'fabric-server-launch.jar' => 'fabric',
        ]);

        return $server;
    }

    private function startSwitch(Server $server, ?Egg $egg = null): PackInstall
    {
        $egg ??= $this->neoforge;

        return app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Neo Pack', '1.0.0', null, [
            'loader' => 'neoforge',
            'minecraft' => '1.21.1',
            'egg_id' => $egg->id,
            'egg_name' => $egg->name,
        ]);
    }

    /** @return array<string, string> worlds, player lists, server.properties and eula.txt by path */
    private function keptFiles(): array
    {
        $kept = array_filter($this->files->files, fn (string $path) => preg_match('#^(world|world_nether|world_the_end)/|^(ops|whitelist|banned-players|banned-ips|usercache)\.json$|^server\.properties$|^eula\.txt$#', $path) === 1, ARRAY_FILTER_USE_KEY);
        ksort($kept);

        return $kept;
    }

    /** @return array<string, string> files under $folder, keyed by their path relative to it */
    private function filesUnder(string $folder): array
    {
        $out = [];
        foreach ($this->files->files as $path => $contents) {
            if (str_starts_with($path, "$folder/")) {
                $out[substr($path, strlen($folder) + 1)] = $contents;
            }
        }

        return $out;
    }

    /** Runs the chain; the node "finishes" backups and the reinstall (with $reinstallOutcome) when waited on. */
    private function runSwitch(Server $server, int $max = 300, ?\Closure $also = null): void
    {
        $this->runJobs($max, function (InstallStep $job, PackInstall $install) use ($server, $also) {
            $this->finishBackups($server);
            if ($job instanceof AwaitReinstallStep) {
                $fresh = $server->fresh();
                if ($fresh?->status === ServerState::Installing) {
                    $fresh->forceFill(['status' => $this->reinstallOutcome, 'installed_at' => now()])->save();
                }
            }
            if ($also !== null) {
                $also($job, $install);
            }
        });
    }

    /** Runs queued restore jobs until none is left. */
    private function runRestoreJobs(int $max = 50): void
    {
        $ran = [];
        for ($i = 0; $i < $max; $i++) {
            $job = null;
            foreach (Queue::pushedJobs()[RestorePreviousFilesJob::class] ?? [] as $entry) {
                if (!isset($ran[spl_object_id($entry['job'])])) {
                    $job = $entry['job'];

                    break;
                }
            }
            if ($job === null) {
                return;
            }
            $ran[spl_object_id($job)] = true;
            $this->app->call([$job, 'handle']);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function eggRows(): array
    {
        return Egg::query()->orderBy('id')->get()->map(fn (Egg $egg) => $egg->getAttributes())->all();
    }

    /** @return array<string, mixed> */
    private function serverState(Server $server): array
    {
        $server = $server->fresh() ?? $server;

        return [
            'egg_id' => $server->egg_id,
            'image' => $server->image,
            'startup' => $server->startup,
            'status' => $server->status,
            'variables' => ServerVariable::query()->where('server_id', $server->id)->orderBy('id')->get(['variable_id', 'variable_value'])->toArray(),
            'mounts' => $server->mounts()->pluck('mounts.id')->all(),
        ];
    }

    private function reinstallCount(): int
    {
        return $this->reinstalls;
    }

    private function variable(Server $server, string $env): ?string
    {
        $value = $server->variables()->where('env_variable', $env)->first();

        return $value?->server_value;
    }

    public function test_switches_the_egg_reinstalls_keeps_worlds_and_player_files_and_installs_the_pack(): void
    {
        $server = $this->fabricServer();
        $before = $this->serverState($server);
        $kept = $this->keptFiles();
        $eggs = $this->eggRows();
        $other = $this->createServerModel();
        $otherBefore = $this->serverState($other);

        $this->startSwitch($server);
        $this->runSwitch($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame([], $this->files->violations);

        // The egg, image, startup and version variables of this server changed.
        $server->refresh();
        $this->assertSame($this->neoforge->id, $server->egg_id);
        $this->assertSame('ghcr.io/pelican-eggs/yolks:java_21', $server->image);
        $this->assertSame('java @unix_args.txt', $server->startup);
        $this->assertSame('1.21.1', $this->variable($server, 'MC_VERSION'));
        $this->assertSame('21.1.77', $this->variable($server, 'NEOFORGE_VERSION'));
        $this->assertSame('neoforge', ServerTarget::loader($server));
        $this->assertSame('1.21.1', ServerTarget::minecraftVersion($server));
        $this->assertSame(1, $this->reinstalls);

        // Worlds, player lists, server.properties and eula.txt are back, byte for byte.
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame([], $this->filesUnder((string) $install->preserved_path));
        $this->assertSame(['world', 'world_nether', 'ops.json', 'whitelist.json', 'banned-players.json', 'banned-ips.json', 'usercache.json', 'server.properties', 'eula.txt'], $install->preservedEntries());

        // The pack is installed; the old pack is moved aside, not deleted.
        $this->assertSame('sodium-bytes', $this->files->files['mods/sodium.jar'] ?? null);
        $this->assertSame('old', $this->files->files[$install->moved_aside_path.'/mods/old-mod.jar'] ?? null);

        // The previous setup is on the record and in the summary.
        $this->assertSame($before['egg_id'], $install->snapshot['egg_id'] ?? null);
        $this->assertSame($before['image'], $install->snapshot['image'] ?? null);
        $this->assertSame($before['startup'], $install->snapshot['startup'] ?? null);
        $this->assertSame('1.20.1', $install->snapshot['variables']['MINECRAFT_VERSION'] ?? null);
        $this->assertTrue($install->summary['loader_switch']['egg_changed'] ?? false);
        $this->assertSame($install->snapshot, $install->summary['loader_switch']['previous'] ?? null);

        // No egg row was written; the other server is untouched.
        $this->assertSame($eggs, $this->eggRows());
        $this->assertSame($otherBefore, $this->serverState($other));
        $this->assertFalse($install->isRestorable());
    }

    public function test_worlds_move_only_inside_the_own_folder_and_never_over_anything(): void
    {
        $server = $this->fabricServer();
        $this->startSwitch($server);
        $this->runSwitch($server);

        $install = $this->latestInstall();
        foreach ($this->files->argsOf('movePreserved') as [$from, $to]) {
            $this->assertTrue(str_starts_with($from, '.modpacks-plus/') xor str_starts_with($to, '.modpacks-plus/'), "$from -> $to");
        }
        // Nothing protected was ever written, deleted or renamed the ordinary way.
        foreach (['write', 'delete', 'rename'] as $operation) {
            foreach ($this->files->callsOf($operation) as $path) {
                $this->assertDoesNotMatchRegularExpression('#^(world|world_nether|ops\.json|whitelist\.json|server\.properties)(/|$)#', $path, "$operation $path");
            }
        }
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
    }

    public function test_a_failed_reinstall_ends_failed_keeps_every_file_and_restore_puts_them_back(): void
    {
        $server = $this->fabricServer();
        $kept = $this->keptFiles();
        $this->reinstallOutcome = ServerState::ReinstallFailed;

        $this->startSwitch($server);
        $this->runSwitch($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('reinstall_failed', $install->error);
        $this->assertSame(PackInstall::STEP_FAILED, $install->stepStatus(AwaitReinstallStep::KEY));

        // The world is still on disk, in the preserved folder, byte for byte.
        $preserved = (string) $install->preserved_path;
        $this->assertSame([], $this->keptFiles());
        foreach ($kept as $path => $contents) {
            $this->assertSame($contents, $this->files->files["$preserved/$path"] ?? null, $path);
        }
        $this->assertTrue($install->isRestorable());
        $this->assertSame('NeoForge', $install->summary['loader_switch']['egg_name'] ?? null);
        $this->assertSame($install->snapshot, $install->summary['loader_switch']['previous'] ?? null);

        // The reinstall left something where server.properties goes: it is kept, not overwritten.
        $this->files->seed(['server.properties' => 'made by the reinstall']);

        PackInstall::query()->whereKey($install->id)->update(['restore_requested_at' => now()]);
        dispatch(new RestorePreviousFilesJob($install->id));
        $this->runRestoreJobs();

        $install->refresh();
        $this->assertNotNull($install->restored_at, (string) json_encode($install->log));
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame('old', $this->files->files['mods/old-mod.jar'] ?? null);
        $this->assertSame('old config', $this->files->files['config/old.toml'] ?? null);
        $replaced = (string) $install->progressValue('restore_replaced_path');
        $this->assertSame('made by the reinstall', $this->files->files["$replaced/server.properties"] ?? null);
        $this->assertSame([], $this->files->violations);
        $this->assertFalse($install->isRestorable());

        // The egg is not switched back automatically.
        $this->assertSame($this->neoforge->id, $server->fresh()?->egg_id);
    }

    public function test_the_reinstall_wait_has_a_deadline_and_nothing_is_lost_when_it_passes(): void
    {
        $server = $this->fabricServer();
        $kept = $this->keptFiles();

        $this->startSwitch($server);
        // The node never reports back.
        $this->runJobs(100, function (InstallStep $job, PackInstall $install) use ($server) {
            $this->finishBackups($server);
            if ($job instanceof AwaitReinstallStep && $install->step_deadline_at !== null) {
                $this->assertLessThanOrEqual(20, (int) round(now()->diffInMinutes($install->step_deadline_at)));
                PackInstall::query()->whereKey($install->id)->update(['step_deadline_at' => now()->subSecond()]);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('step_timeout', $install->error);
        foreach ($kept as $path => $contents) {
            $this->assertSame($contents, $this->files->files[$install->preserved_path."/$path"] ?? null, $path);
        }
    }

    public function test_the_watchdog_fails_a_stuck_reinstall_wait_instead_of_waiting_forever(): void
    {
        $server = $this->fabricServer();
        $this->startSwitch($server);
        $this->runJobs(100, function (InstallStep $job, PackInstall $install) use ($server) {
            $this->finishBackups($server);
            if ($job instanceof AwaitReinstallStep) {
                // Stop running jobs: the chain is lost here.
                PackInstall::query()->whereKey($install->id)->update(['seq' => $install->seq + 100]);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame(AwaitReinstallStep::KEY, $install->current_step);
        PackInstall::query()->whereKey($install->id)->update(['step_deadline_at' => now()->subMinute()]);

        app(InstallEngine::class)->watchdog();

        $this->assertSame(PackInstall::STATUS_FAILED, $install->refresh()->status);
        $this->assertTrue($install->isRestorable());
    }

    public function test_cancel_during_the_switch_waits_until_worlds_are_back(): void
    {
        $server = $this->fabricServer();
        $kept = $this->keptFiles();

        $this->startSwitch($server);
        $this->runSwitch($server, also: function (InstallStep $job, PackInstall $install) {
            if ($job instanceof ChangeEggStep && $install->cancel_requested_at === null) {
                app(InstallEngine::class)->requestCancel($install);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_CANCELLED, $install->status);
        $this->assertSame(DownloadStep::KEY, $install->current_step);
        $this->assertSame(PackInstall::STEP_DONE, $install->stepStatus(RestorePreservedStep::KEY));
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame(1, $this->reinstalls);
    }

    public function test_cancel_before_anything_is_set_aside_changes_nothing(): void
    {
        $server = $this->fabricServer();
        $before = $this->serverState($server);
        $kept = $this->keptFiles();

        $this->startSwitch($server);
        $this->runSwitch($server, also: function (InstallStep $job, PackInstall $install) {
            if ($job instanceof SnapshotStep && $install->cancel_requested_at === null) {
                app(InstallEngine::class)->requestCancel($install);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_CANCELLED, $install->status);
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame($before, $this->serverState($server));
        $this->assertSame(0, $this->reinstalls);
        $this->assertSame([], $this->files->argsOf('movePreserved'));
    }

    public function test_the_watchdog_never_cancels_in_the_middle_of_a_switch(): void
    {
        $server = $this->fabricServer();
        $this->startSwitch($server);
        $this->runJobs(100, function (InstallStep $job, PackInstall $install) use ($server) {
            $this->finishBackups($server);
            if ($job instanceof AwaitReinstallStep) {
                PackInstall::query()->whereKey($install->id)->update(['seq' => $install->seq + 100]);
            }
        });

        $install = $this->latestInstall();
        PackInstall::query()->whereKey($install->id)->update(['cancel_requested_at' => now(), 'heartbeat_at' => now()->subMinutes(10)]);

        [$failed, $resumed] = app(InstallEngine::class)->watchdog();

        $install->refresh();
        $this->assertSame([0, 1], [$failed, $resumed]);
        $this->assertTrue($install->isActive());
        $this->assertSame(AwaitReinstallStep::KEY, $install->current_step);
    }

    public function test_a_running_server_is_stopped_before_any_world_moves(): void
    {
        $server = $this->fabricServer();
        $this->power->state = 'running';

        $this->startSwitch($server);
        $this->runSwitch($server, 40);

        $install = $this->latestInstall();
        $this->assertSame(PreserveStep::KEY, $install->current_step);
        // Plan 36 review: a server that keeps running gets the stop again every few checks.
        $polls = (int) $install->progressValue('stop_polls_'.PreserveStep::KEY);
        $this->assertSame(intdiv($polls - 1, InstallStep::STOP_RESEND_POLLS) + 1, $this->power->stops);
        $this->assertGreaterThan(1, $this->power->stops);
        $this->assertSame([], $this->files->argsOf('movePreserved'));
        $this->assertNull($install->preserved_path);

        $stops = $this->power->stops;
        $this->power->state = 'offline';
        $this->runSwitch($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $this->latestInstall()->status);
        $this->assertSame($stops, $this->power->stops);
    }

    public function test_a_server_started_during_the_reinstall_is_stopped_before_worlds_go_back(): void
    {
        $server = $this->fabricServer();
        $kept = $this->keptFiles();

        $this->startSwitch($server);
        $this->runSwitch($server, also: function (InstallStep $job) {
            if ($job instanceof RestorePreservedStep && $this->power->stops === 0) {
                // The user started the new server and it made a fresh world.
                $this->power->state = 'running';
                $this->power->afterStop = 'offline';
                $this->files->seed(['world/level.dat' => 'brand new world']);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertSame(1, $this->power->stops);
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame('brand new world', $this->files->files[$install->progressValue('replaced_path').'/world/level.dat'] ?? null);
    }

    public function test_crashes_mid_step_resume_without_moving_or_reinstalling_twice(): void
    {
        $server = $this->fabricServer();
        $kept = $this->keptFiles();
        // The node fails one move, then the reinstall request dies after reaching the node.
        $this->files->failMoves = ['ops.json'];
        $this->reinstallCrash = new RuntimeException('worker died');

        $this->startSwitch($server);
        try {
            $this->runSwitch($server);
            $this->fail('the failing move should have thrown');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ops.json', $exception->getMessage());
        }
        $install = $this->latestInstall();
        $this->assertSame(PreserveStep::KEY, $install->current_step);
        $movedBefore = count($this->files->argsOf('movePreserved'));

        // The queue retries the same job (same seq).
        $this->files->failMoves = [];
        dispatch(new PreserveStep($install->id, $install->seq));
        $this->runSwitch($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(1, $this->reinstalls);
        $this->assertSame($kept, $this->keptFiles());
        // world and world_nether were moved once before the crash, never again.
        $moves = array_map(fn (array $args) => $args[0], $this->files->argsOf('movePreserved'));
        $this->assertSame(1, count(array_keys($moves, 'world', true)));
        $this->assertGreaterThan(0, $movedBefore);
        $this->assertSame([], $this->files->violations);
    }

    public function test_the_egg_changed_by_someone_else_stops_the_switch(): void
    {
        $server = $this->fabricServer();
        $kept = $this->keptFiles();
        $elsewhere = $this->cloneEggAndVariables($server->egg);

        $this->startSwitch($server);
        $this->runSwitch($server, also: function (InstallStep $job) use ($server, $elsewhere) {
            if ($job instanceof ChangeEggStep) {
                Server::query()->whereKey($server->id)->update(['egg_id' => $elsewhere->id]);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('egg_changed_elsewhere', $install->error);
        $this->assertSame($elsewhere->id, $server->fresh()?->egg_id);
        $this->assertSame(0, $this->reinstalls);
        foreach ($kept as $path => $contents) {
            $this->assertSame($contents, $this->files->files[$install->preserved_path."/$path"] ?? null, $path);
        }
    }

    public function test_a_deleted_target_egg_fails_before_anything_is_set_aside(): void
    {
        $server = $this->fabricServer();
        $before = $this->serverState($server);
        $kept = $this->keptFiles();

        $this->startSwitch($server);
        Egg::query()->whereKey($this->neoforge->id)->delete();
        $this->runSwitch($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('egg_missing', $install->error);
        $this->assertSame(PackInstall::STEP_FAILED, $install->stepStatus(SnapshotStep::KEY));
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame($before, $this->serverState($server));
        $this->assertSame([], $this->files->argsOf('movePreserved'));
    }

    public function test_a_pack_for_another_target_than_confirmed_fails_before_any_change(): void
    {
        $server = $this->fabricServer();
        // The pack index says 1.21.4, the switch was confirmed for 1.21.1.
        $this->scenarioPack(null, ['minecraft' => '1.21.4', 'neoforge' => '21.4.1']);
        $before = $this->serverState($server);

        $this->startSwitch($server);
        $this->runSwitch($server);

        $install = $this->latestInstall();
        $this->assertSame('target_mismatch', $install->error);
        $this->assertSame($before, $this->serverState($server));
        $this->assertSame(0, $this->files->writeCalls());
    }

    public function test_reinstall_step_never_requests_a_second_reinstall_once_one_started(): void
    {
        $server = $this->fabricServer();
        $this->startSwitch($server);
        $this->runJobs(100, function (InstallStep $job, PackInstall $install) use ($server) {
            $this->finishBackups($server);
            if ($job instanceof AwaitReinstallStep) {
                PackInstall::query()->whereKey($install->id)->update(['seq' => $install->seq + 100]);
            }
        });
        $install = $this->latestInstall();
        $this->assertSame(1, $this->reinstalls);

        // A stale duplicate of the reinstall job, forced to look current.
        PackInstall::query()->whereKey($install->id)->update(['current_step' => ReinstallStep::KEY]);
        $install->refresh();
        $this->app->call([new ReinstallStep($install->id, $install->seq), 'handle']);

        $this->assertSame(1, $this->reinstallCount());
        $this->assertSame(AwaitReinstallStep::KEY, $install->refresh()->current_step);
    }

    public function test_no_install_starts_while_a_restore_runs(): void
    {
        $server = $this->fabricServer();
        $this->reinstallOutcome = ServerState::ReinstallFailed;
        $this->startSwitch($server);
        $this->runSwitch($server);
        $install = $this->latestInstall();
        PackInstall::query()->whereKey($install->id)->update(['restore_requested_at' => now()]);

        try {
            $this->startSwitch($server);
            $this->fail('expected restore_running');
        } catch (InstallRefusedException $exception) {
            $this->assertSame('restore_running', $exception->reason);
        }
        $this->assertSame(1, PackInstall::query()->count());
    }

    public function test_restore_waits_while_the_server_is_installing_and_moves_nothing(): void
    {
        $server = $this->fabricServer();
        $this->reinstallOutcome = ServerState::ReinstallFailed;
        $this->startSwitch($server);
        $this->runSwitch($server);
        $install = $this->latestInstall();

        $server->forceFill(['status' => ServerState::Installing])->save();
        $moves = count($this->files->argsOf('movePreserved'));
        PackInstall::query()->whereKey($install->id)->update(['restore_requested_at' => now()]);
        $this->app->call([new RestorePreviousFilesJob($install->id), 'handle']);

        $this->assertSame($moves, count($this->files->argsOf('movePreserved')));
        $this->assertNull($install->refresh()->restored_at);
        Queue::assertPushed(RestorePreviousFilesJob::class, 1);
    }

    public function test_a_restore_that_runs_too_long_ends_and_can_be_asked_for_again(): void
    {
        $server = $this->fabricServer();
        $this->reinstallOutcome = ServerState::ReinstallFailed;
        $this->startSwitch($server);
        $this->runSwitch($server);
        $install = $this->latestInstall();

        PackInstall::query()->whereKey($install->id)->update(['restore_requested_at' => now()->subMinutes(PackInstall::RESTORE_MINUTES + 1)]);
        $this->app->call([new RestorePreviousFilesJob($install->id), 'handle']);

        $install->refresh();
        $this->assertNull($install->restore_requested_at);
        $this->assertNull($install->restored_at);
        $this->assertTrue($install->isRestorable());
    }

    // --- Plan 35 security review ---

    public function test_snapshot_never_records_values_of_variables_users_cannot_see(): void
    {
        $server = $this->fabricServer();
        /** @var EggVariable $hidden */
        $hidden = EggVariable::factory()->create(['egg_id' => $server->egg_id, 'env_variable' => 'RCON_PASSWORD', 'default_value' => '', 'user_viewable' => false, 'rules' => ['nullable', 'string']]);
        ServerVariable::query()->create(['server_id' => $server->id, 'variable_id' => $hidden->id, 'variable_value' => 'top-secret-rcon']);
        $this->reinstallOutcome = ServerState::ReinstallFailed;

        $this->startSwitch($server);
        $this->runSwitch($server);

        $install = $this->latestInstall();
        $this->assertContains('RCON_PASSWORD', $install->snapshot['hidden_variables'] ?? []);
        $this->assertArrayNotHasKey('RCON_PASSWORD', $install->snapshot['variables'] ?? []);
        $this->assertStringNotContainsString('top-secret-rcon', (string) json_encode($install->getAttributes()));
    }

    public function test_backup_skipping_is_never_allowed_for_a_loader_switch(): void
    {
        config()->set('modpacks-plus.allow_skip_backup', true);
        $backups = Mockery::mock(InitiateBackupService::class);
        $backups->shouldReceive('setIsLocked')->andReturnSelf();
        $backups->shouldReceive('handle')->andThrow(new TooManyBackupsException(0));
        $this->app->instance(InitiateBackupService::class, $backups);
        $server = $this->fabricServer();
        $before = $this->serverState($server);

        $this->startSwitch($server);
        $this->runSwitch($server);

        $install = $this->latestInstall();
        $this->assertSame('backup_impossible', $install->error);
        $this->assertSame($before, $this->serverState($server));
        $this->assertSame([], $this->files->argsOf('movePreserved'));
    }

    public function test_a_crash_between_a_move_and_its_record_still_restores_the_entry(): void
    {
        $server = $this->fabricServer();
        $kept = $this->keptFiles();
        $crashed = false;
        $this->files->afterMove = function (string $from) use (&$crashed) {
            if ($from === 'ops.json' && !$crashed) {
                $crashed = true;

                throw new RuntimeException('worker died after the move');
            }
        };

        $this->startSwitch($server);
        try {
            $this->runSwitch($server);
            $this->fail('expected the worker to die');
        } catch (RuntimeException $exception) {
            $this->assertSame('worker died after the move', $exception->getMessage());
        }

        // The queue gives up: the install fails right there.
        $install = $this->latestInstall();
        (new PreserveStep($install->id, $install->seq))->failed(new RuntimeException('gave up'));
        $install->refresh();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertContains('ops.json', $install->preservedEntries());
        $this->assertArrayNotHasKey('ops.json', $this->files->files);

        PackInstall::query()->whereKey($install->id)->update(['restore_requested_at' => now()]);
        dispatch(new RestorePreviousFilesJob($install->id));
        $this->runRestoreJobs();

        $this->assertNotNull($install->refresh()->restored_at);
        $this->assertSame($kept, $this->keptFiles());
    }

    public function test_restore_reconciles_the_record_with_the_preserved_folder(): void
    {
        $server = $this->fabricServer();
        $kept = $this->keptFiles();
        $this->reinstallOutcome = ServerState::ReinstallFailed;
        $this->startSwitch($server);
        $this->runSwitch($server);
        $install = $this->latestInstall();

        // The record lost whitelist.json; the disk still has it.
        $install->setProgress('preserved', array_values(array_diff($install->preservedEntries(), ['whitelist.json'])));
        $install->restore_requested_at = now();
        $install->save();
        dispatch(new RestorePreviousFilesJob($install->id));
        $this->runRestoreJobs();

        $this->assertNotNull($install->refresh()->restored_at);
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame([], $this->filesUnder((string) $install->preserved_path));
    }

    public function test_no_new_install_while_preserved_files_of_an_earlier_switch_wait(): void
    {
        $server = $this->fabricServer();
        $this->reinstallOutcome = ServerState::ReinstallFailed;
        $this->startSwitch($server);
        $this->runSwitch($server);
        $failed = $this->latestInstall();

        foreach ([fn () => $this->startSwitch($server), fn () => app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Plain')] as $start) {
            try {
                $start();
                $this->fail('expected preserved_pending');
            } catch (InstallRefusedException $exception) {
                $this->assertSame('preserved_pending', $exception->reason);
                $this->assertSame((string) $failed->preserved_path, $exception->detail);
            }
        }
        $this->assertSame(1, PackInstall::query()->count());
    }

    public function test_restore_targets_the_newest_install_with_preserved_files_even_when_a_newer_one_exists(): void
    {
        $server = $this->fabricServer();
        $kept = $this->keptFiles();
        $this->reinstallOutcome = ServerState::ReinstallFailed;
        $this->startSwitch($server);
        $this->runSwitch($server);
        $switch = $this->latestInstall();

        // A newer record (e.g. from before this rule existed) that set nothing aside.
        $newer = $switch->replicate(['preserved_path', 'loader_switch', 'snapshot']);
        $newer->progress = ['moved' => []];
        $newer->moved_aside_path = null;
        $newer->save();

        $this->assertSame($switch->id, PackInstall::restoreCandidate($server->id)?->id);

        PackInstall::query()->whereKey($switch->id)->update(['restore_requested_at' => now()]);
        dispatch(new RestorePreviousFilesJob($switch->id));
        $this->runRestoreJobs();

        $this->assertSame($kept, $this->keptFiles());
        $this->assertNull(PackInstall::restoreCandidate($server->id));
    }

    public function test_restore_never_moves_files_while_a_reinstall_may_still_run(): void
    {
        $server = $this->fabricServer();
        $this->startSwitch($server);
        // The node never reports back; the wait times out.
        $this->runJobs(100, function (InstallStep $job, PackInstall $install) use ($server) {
            $this->finishBackups($server);
            if ($job instanceof AwaitReinstallStep) {
                PackInstall::query()->whereKey($install->id)->update(['step_deadline_at' => now()->subSecond()]);
            }
        });
        $install = $this->latestInstall();
        $this->assertSame('step_timeout', $install->error);
        $moves = count($this->files->argsOf('movePreserved'));

        // Panel still says installing: the job waits.
        PackInstall::query()->whereKey($install->id)->update(['restore_requested_at' => now()]);
        $this->app->call([new RestorePreviousFilesJob($install->id), 'handle']);
        $this->assertSame($moves, count($this->files->argsOf('movePreserved')));

        // An admin toggled the status, but the node never reported the install finished:
        // within the quiet period nothing moves; the job waits (re-dispatches) instead of ending.
        $server->refresh()->forceFill(['status' => null])->save();
        $pushed = count(Queue::pushedJobs()[RestorePreviousFilesJob::class] ?? []);
        $this->app->call([new RestorePreviousFilesJob($install->id), 'handle']);
        $install->refresh();
        $this->assertCount($moves, $this->files->argsOf('movePreserved'));
        $this->assertNull($install->restored_at);
        $this->assertNotNull($install->restore_requested_at);
        $this->assertCount($pushed + 1, Queue::pushedJobs()[RestorePreviousFilesJob::class] ?? []);

        // Long after the last request it may go ahead.
        $this->travel(RestorePreviousFilesJob::REINSTALL_QUIET_MINUTES + 1)->minutes();
        PackInstall::query()->whereKey($install->id)->update(['restore_requested_at' => now()]);
        dispatch(new RestorePreviousFilesJob($install->id));
        $this->runRestoreJobs();
        $this->assertNotNull($install->refresh()->restored_at, (string) json_encode(array_slice($install->log ?? [], -4)));
    }

    public function test_a_carried_over_variable_the_new_egg_rejects_refuses_the_switch_before_any_change(): void
    {
        $server = $this->fabricServer();
        /** @var EggVariable $own */
        $own = EggVariable::query()->where('egg_id', $server->egg_id)->where('env_variable', 'SERVER_JARFILE')->first()
            ?? EggVariable::factory()->create(['egg_id' => $server->egg_id, 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar', 'rules' => ['required', 'string']]);
        ServerVariable::query()->updateOrCreate(['server_id' => $server->id, 'variable_id' => $own->id], ['variable_value' => 'not a jar name!']);
        EggVariable::factory()->create(['egg_id' => $this->neoforge->id, 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar', 'rules' => ['required', 'regex:/^([\w\d._-]+)(\.jar)$/']]);
        $before = $this->serverState($server);

        $this->startSwitch($server);
        $this->runSwitch($server);

        $install = $this->latestInstall();
        $this->assertSame('variable_invalid', $install->error);
        $this->assertSame(PackInstall::STEP_FAILED, $install->stepStatus(SnapshotStep::KEY));
        $this->assertSame($before, $this->serverState($server));
        $this->assertSame(0, $this->files->writeCalls());
        $this->assertSame(0, $this->backupsCreated);
    }

    public function test_a_hidden_variable_that_fails_validation_never_shows_its_value(): void
    {
        $server = $this->fabricServer();
        /** @var EggVariable $own */
        $own = EggVariable::query()->where('egg_id', $server->egg_id)->where('env_variable', 'SERVER_JARFILE')->first()
            ?? EggVariable::factory()->create(['egg_id' => $server->egg_id, 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar', 'rules' => ['required', 'string']]);
        $own->forceFill(['user_viewable' => false])->save();
        ServerVariable::query()->updateOrCreate(['server_id' => $server->id, 'variable_id' => $own->id], ['variable_value' => 'top-secret-rcon value!']);
        EggVariable::factory()->create(['egg_id' => $this->neoforge->id, 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar', 'rules' => ['required', 'regex:/^([\w\d._-]+)(\.jar)$/']]);

        $this->startSwitch($server);
        $this->runSwitch($server);

        $install = $this->latestInstall();
        $this->assertSame('variable_invalid', $install->error);
        $this->assertSame('SERVER_JARFILE', $install->progressValue('error_detail'));
        $this->assertStringNotContainsString('top-secret', (string) json_encode($install->getAttributes()));
        $this->assertStringNotContainsString('top-secret', (string) InstallProgress::error($install));
        $this->assertStringNotContainsString('top-secret', (string) InstallProgress::log($install));
        $this->assertStringNotContainsString('top-secret', (string) InstallProgress::summary($install, true));
    }

    public function test_an_image_check_failure_names_the_egg_not_the_image(): void
    {
        $server = $this->fabricServer();

        $this->startSwitch($server);
        $this->runSwitch($server, also: function (InstallStep $job) {
            if ($job instanceof ChangeEggStep) {
                $this->neoforge->forceFill(['docker_images' => ['Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17']])->save();
            }
        });

        $install = $this->latestInstall();
        $this->assertSame('no_java_image', $install->error);
        $this->assertSame('NeoForge', $install->progressValue('error_detail'));
        $this->assertStringNotContainsString('java_21', (string) InstallProgress::error($install));
    }

    /** @return array<string, array{\Closure(Egg): mixed, string}> */
    public static function eggEditsAfterTheSnapshot(): array
    {
        return [
            'image removed' => [fn (Egg $egg) => $egg->forceFill(['docker_images' => ['Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17']])->save(), 'no_java_image'],
            'tagged as another loader' => [fn (Egg $egg) => $egg->forceFill(['tags' => ['minecraft', 'forge']])->save(), 'egg_unknown'],
            'variable rule tightened' => [fn (Egg $egg) => EggVariable::query()->where('egg_id', $egg->id)->where('env_variable', 'NEOFORGE_VERSION')->update(['rules' => json_encode(['required', 'regex:/^20\./'])]), 'variable_invalid'],
        ];
    }

    /** @param  \Closure(Egg): mixed  $edit */
    #[DataProvider('eggEditsAfterTheSnapshot')]
    public function test_the_egg_is_checked_again_right_before_the_change(\Closure $edit, string $reason): void
    {
        $server = $this->fabricServer();
        $before = $this->serverState($server);

        $this->startSwitch($server);
        $this->runSwitch($server, also: function (InstallStep $job) use ($edit) {
            if ($job instanceof ChangeEggStep) {
                $edit($this->neoforge);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame($reason, $install->error);
        $this->assertSame(PackInstall::STEP_FAILED, $install->stepStatus(ChangeEggStep::KEY));
        $this->assertSame($before, $this->serverState($server));
        $this->assertSame(0, $this->reinstalls);
        $this->assertTrue($install->isRestorable());
    }

    // --- Plan 35 security re-review ---

    /** A switch that failed after setting the world aside, with its restore requested. */
    private function failedSwitchAwaitingRestore(): PackInstall
    {
        $server = $this->fabricServer();
        $this->reinstallOutcome = ServerState::ReinstallFailed;
        $this->startSwitch($server);
        $this->runSwitch($server);

        return $this->latestInstall();
    }

    private function restore(PackInstall $install): PackInstall
    {
        PackInstall::query()->whereKey($install->id)->update(['restore_requested_at' => now()]);
        dispatch(new RestorePreviousFilesJob($install->id));
        $this->runRestoreJobs();

        return $install->refresh();
    }

    public function test_a_planted_own_folder_name_neither_blocks_restore_nor_installs(): void
    {
        $install = $this->failedSwitchAwaitingRestore();
        $kept = array_keys($this->filesUnder((string) $install->preserved_path));
        // A user with file.create plants the own folder's name inside the preserved folder.
        $this->files->seed([$install->preserved_path.'/.modpacks-plus/preserved-1/world/level.dat' => 'planted']);

        $install = $this->restore($install);

        $this->assertNotNull($install->restored_at, (string) json_encode(array_slice($install->log ?? [], -4)));
        $this->assertSame([], $this->files->violations);
        $this->assertSame('level', $this->files->files['world/level.dat'] ?? null);
        $this->assertSame('planted', $this->files->files[$install->preserved_path.'/.modpacks-plus/preserved-1/world/level.dat'] ?? null);
        $this->assertSame(['.modpacks-plus'], $install->progressValue('preserved_unexpected'));
        $this->assertStringContainsString('.modpacks-plus', (string) InstallProgress::summary($install));
        $this->assertNotContains('.modpacks-plus', array_map(fn (array $args) => $args[0], $this->files->argsOf('movePreserved')));
        $this->assertNotSame([], $kept);

        $this->assertFalse($install->hasPreservedPending());
        $this->startSwitch($install->server ?? $this->fail('server'));
        $this->assertSame(2, PackInstall::query()->count());
    }

    public function test_a_planted_unexpected_file_is_left_in_place_and_listed(): void
    {
        $install = $this->failedSwitchAwaitingRestore();
        $kept = $this->filesUnder((string) $install->preserved_path);
        ksort($kept);
        $this->files->seed([$install->preserved_path.'/notes.txt' => 'mine']);

        $install = $this->restore($install);

        $this->assertNotNull($install->restored_at);
        $this->assertSame($kept, $this->keptFiles());
        $this->assertArrayNotHasKey('notes.txt', $this->files->files);
        $this->assertSame('mine', $this->files->files[$install->preserved_path.'/notes.txt'] ?? null);
        $this->assertSame(['notes.txt'], $install->progressValue('preserved_unexpected'));
    }

    public function test_a_world_blocked_by_a_conflict_keeps_the_install_pending_until_a_retry_restores_it(): void
    {
        $install = $this->failedSwitchAwaitingRestore();
        $server = $install->server ?? $this->fail('server');
        $replaced = '.modpacks-plus/replaced-test-'.$install->id;
        $install->setProgress('restore_replaced_path', $replaced);
        $install->save();
        // Something is in the way of the world, and its keep-place is taken too.
        $this->files->seed(['world/level.dat' => 'new world', "$replaced/world/level.dat" => 'older']);

        $install = $this->restore($install);

        // The others are back; the world is not, so nothing is "restored".
        $this->assertNull($install->restored_at);
        $this->assertNull($install->restore_requested_at);
        $this->assertSame('level', $this->files->files[$install->preserved_path.'/world/level.dat'] ?? null);
        $this->assertSame('new world', $this->files->files['world/level.dat'] ?? null);
        $this->assertSame('[{"name":"owner"}]', $this->files->files['ops.json'] ?? null);
        $this->assertSame(['restored' => 10, 'failed' => ['world']], $install->progressValue('restore_incomplete'));
        $this->assertStringContainsString(
            e(trans('modpacks-plus::strings.summary.restore_incomplete', ['restored' => 10, 'failed' => 1, 'paths' => 'world', 'path' => (string) $install->preserved_path])),
            (string) InstallProgress::summary($install),
        );
        $this->assertTrue($install->hasPreservedPending());
        $this->assertTrue($install->isRestorable());
        $this->assertFalse($install->isRestoring());
        $this->assertSame($install->id, PackInstall::restoreCandidate($server->id)?->id);
        try {
            $this->startSwitch($server);
            $this->fail('expected preserved_pending');
        } catch (InstallRefusedException $exception) {
            $this->assertSame('preserved_pending', $exception->reason);
        }

        // The conflict is cleared; asking again puts the world back.
        unset($this->files->files["$replaced/world/level.dat"]);
        unset($this->files->dirs["$replaced/world"]);
        $install = $this->restore($install);

        $this->assertNotNull($install->restored_at, (string) json_encode(array_slice($install->log ?? [], -4)));
        $this->assertSame('level', $this->files->files['world/level.dat'] ?? null);
        $this->assertSame('new world', $this->files->files["$replaced/world/level.dat"] ?? null);
        $this->assertNull($install->progressValue('restore_incomplete'));
        $this->assertFalse($install->hasPreservedPending());
        $this->assertSame([], $this->files->violations);
    }

    public function test_a_switch_that_fails_before_anything_moved_is_not_pending(): void
    {
        $server = $this->fabricServer();
        $before = $this->keptFiles();

        $this->startSwitch($server);
        try {
            $this->runSwitch($server, also: function (InstallStep $job) {
                if ($job instanceof PreserveStep) {
                    $this->files->beforeStat = fn (string $path) => $path === 'world' ? throw new RuntimeException('node down') : null;
                }
            });
            $this->fail('expected the node to fail');
        } catch (RuntimeException $exception) {
            $this->assertSame('node down', $exception->getMessage());
        }
        $this->files->beforeStat = null;

        $install = $this->latestInstall();
        (new PreserveStep($install->id, $install->seq))->failed(new RuntimeException('gave up'));
        $install->refresh();

        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertNull($install->preserved_path);
        $this->assertFalse($install->hasPreservedPending());
        $this->assertSame($before, $this->keptFiles());
        $this->assertSame([], $this->files->argsOf('movePreserved'));

        // Nothing blocks the next install.
        $this->startSwitch($server);
        $this->assertSame(2, PackInstall::query()->count());
    }
}
