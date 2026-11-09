<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Enums\ServerState;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Services\Servers\ReinstallServerService;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
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
        return array_filter($this->files->files, fn (string $path) => preg_match('#^(world|world_nether|world_the_end)/|^(ops|whitelist|banned-players|banned-ips|usercache)\.json$|^server\.properties$|^eula\.txt$#', $path) === 1, ARRAY_FILTER_USE_KEY);
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
        $this->assertSame(1, $this->power->stops);
        $this->assertSame([], $this->files->argsOf('movePreserved'));
        $this->assertNull($install->preserved_path);

        $this->power->state = 'offline';
        $this->runSwitch($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $this->latestInstall()->status);
        $this->assertSame(1, $this->power->stops);
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
}
