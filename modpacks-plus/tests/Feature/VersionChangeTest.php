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
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Jobs\RestorePreviousFilesJob;
use DrMxrcy\ModpacksPlus\Jobs\Steps\AwaitReinstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\BackupStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\InstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\MoveAsideStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\VersionPrepareStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\ServerAvailability;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

/**
 * Plan 40 step 2: a version change without a pack (the "Minecraft version" picker) through
 * the loader-switch engine. Same loader: mods/ and config/ stay; another loader: they go
 * aside; worlds and player files always set aside and put back; a failed reinstall ends
 * failed and "Restore previous files" puts everything back. Daemon faked.
 */
class VersionChangeTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private const FABRIC_UUID = '78b02ebb-fec8-49c5-943c-ca4aa117b693';

    private Egg $fabric;

    private Egg $neoforge;

    private int $reinstalls = 0;

    private ?ServerState $reinstallOutcome = null;

    /** @var array<string, string> what a successful reinstall writes */
    private array $reinstallCreates = ['server.jar' => 'fabric launch'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();

        $this->reinstalls = 0;
        $reinstall = Mockery::mock(ReinstallServerService::class);
        $reinstall->shouldReceive('handle')->andReturnUsing(function (Server $server) {
            $this->reinstalls++;
            $server->forceFill(['status' => ServerState::Installing])->save();

            return $server;
        });
        $this->app->instance(ReinstallServerService::class, $reinstall);

        $this->fabric = self::makeFabricEgg();
        $this->neoforge = self::makeNeoForgeEgg();
    }

    public static function makeFabricEgg(): Egg
    {
        /** @var Egg $egg */
        $egg = Egg::factory()->create([
            'uuid' => self::FABRIC_UUID,
            'name' => 'Fabric',
            'tags' => ['minecraft'],
            'update_url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/fabric/egg-fabric.yaml',
            'startup_commands' => ['Default' => 'java -jar {{SERVER_JARFILE}}'],
            'docker_images' => ['Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21', 'Java 25' => 'ghcr.io/pelican-eggs/yolks:java_25'],
        ]);
        EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'MC_VERSION', 'default_value' => 'latest', 'user_viewable' => true, 'rules' => ['required', 'string', 'between:3,15']]);
        EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'LOADER_VERSION', 'default_value' => 'latest', 'user_viewable' => true, 'rules' => ['required', 'string', 'between:3,15']]);
        EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar', 'user_viewable' => true, 'rules' => ['required', 'regex:/^([\w\d._-]+)(\.jar)$/']]);

        return $egg->fresh() ?? $egg;
    }

    public static function makeNeoForgeEgg(): Egg
    {
        /** @var Egg $egg */
        $egg = Egg::factory()->create([
            'uuid' => LoaderEggResolverTest::NEOFORGE_UUID,
            'name' => 'NeoForge',
            'tags' => ['minecraft'],
            'update_url' => LoaderEggResolverTest::NEOFORGE_URL,
            'startup_commands' => ['Default' => 'java @unix_args.txt'],
            'docker_images' => ['Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17', 'Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21'],
        ]);
        EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'MC_VERSION', 'default_value' => 'latest', 'rules' => ['required', 'string', 'max:9']]);
        EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'NEOFORGE_VERSION', 'default_value' => '', 'rules' => ['nullable', 'regex:/^[0-9\.\-]+$/']]);

        return $egg->fresh() ?? $egg;
    }

    /** World, player lists, an old pack's mods and config, and launch files from an hour ago. */
    private function seedServerFiles(): void
    {
        $this->files->seed([
            'mods/old-mod.jar' => 'old',
            'config/old.toml' => 'old config',
            'world/level.dat' => 'level',
            'world/region/r.0.0.mca' => 'region',
            'world_nether/DIM-1/x' => 'nether',
            'ops.json' => '[{"name":"owner"}]',
            'whitelist.json' => '["friend"]',
            'banned-players.json' => '[{"name":"griefer"}]',
            'banned-ips.json' => '[]',
            'server.properties' => "motd=hi\nlevel-name=world\n",
            'eula.txt' => 'eula=true',
            'server.jar' => 'fabric 26.3 launch',
        ]);
        $hourAgo = now()->subHour()->getTimestamp();
        foreach (array_keys($this->files->files) as $path) {
            $this->files->modified[$path] = $hourAgo;
        }
    }

    /** A Fabric 26.3 server on the official Fabric egg. */
    private function fabricServerOnOfficialEgg(): Server
    {
        [, $server] = $this->scenarioServer();
        ServerVariable::query()->where('server_id', $server->id)->delete();
        $server->forceFill(['egg_id' => $this->fabric->id, 'image' => 'ghcr.io/pelican-eggs/yolks:java_25'])->save();
        foreach (['MC_VERSION' => '26.3', 'LOADER_VERSION' => '0.19.5', 'SERVER_JARFILE' => 'server.jar'] as $env => $value) {
            ServerVariable::query()->create(['server_id' => $server->id, 'variable_id' => $this->fabric->variables()->where('env_variable', $env)->value('id'), 'variable_value' => $value]);
        }
        $this->seedServerFiles();

        return $server->fresh() ?? $server;
    }

    /** A Fabric 1.20.1 server on a tagged (non-official) egg. */
    private function fabricServerOnTaggedEgg(): Server
    {
        [, $server] = $this->scenarioServer();
        $this->seedServerFiles();

        return $server;
    }

    private function start(Server $server, string $loader, string $minecraft, ?string $loaderVersion, Egg $egg): PackInstall
    {
        return app(InstallEngine::class)->startVersionChange($server, null, [
            'loader' => $loader,
            'minecraft' => $minecraft,
            'loader_version' => $loaderVersion,
            'egg_id' => $egg->id,
            'egg_name' => $egg->name,
        ]);
    }

    private function runChange(Server $server): void
    {
        $this->runJobs(300, function (InstallStep $job) use ($server) {
            $this->finishBackups($server);
            if ($job instanceof AwaitReinstallStep) {
                $fresh = $server->fresh();
                if ($fresh?->status === ServerState::Installing) {
                    $fresh->forceFill(['status' => $this->reinstallOutcome, 'installed_at' => now()])->save();
                    if ($this->reinstallOutcome === null) {
                        $this->files->seed($this->reinstallCreates);
                    }
                }
            }
        });
    }

    private function restore(PackInstall $install): void
    {
        PackInstall::query()->whereKey($install->id)->update(['restore_requested_at' => now()]);
        dispatch(new RestorePreviousFilesJob($install->id));
        $ran = [];
        for ($i = 0; $i < 50; $i++) {
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

    /** @return array<string, string> worlds, player lists, server.properties and eula.txt */
    private function keptFiles(): array
    {
        $kept = array_filter($this->files->files, fn (string $path) => preg_match('#^(world|world_nether|world_the_end)/|^(ops|whitelist|banned-players|banned-ips|usercache)\.json$|^server\.properties$|^eula\.txt$#', $path) === 1, ARRAY_FILTER_USE_KEY);
        ksort($kept);

        return $kept;
    }

    private function variable(Server $server, string $env): ?string
    {
        return $server->variables()->where('env_variable', $env)->first()?->server_value;
    }

    private function failure(PackInstall $install): string
    {
        return (string) $install->error.' '.$install->progressValue('error_detail').' '.json_encode(array_column($install->log ?? [], 'key'));
    }

    public function test_another_version_of_the_same_loader_sets_the_variables_keeps_mods_and_worlds_and_ends_installed(): void
    {
        $server = $this->fabricServerOnOfficialEgg();
        $kept = $this->keptFiles();
        $this->reinstallCreates = ['server.jar' => 'fabric 26.2 launch'];

        $this->start($server, 'fabric', '26.2', '0.20.1', $this->fabric);
        $this->runChange($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, $this->failure($install));
        $this->assertSame(PackInstall::MODE_VERSION, $install->mode);
        $this->assertSame(PackInstall::VERSION_PROVIDER, $install->provider);
        $this->assertTrue($install->isVersionChange());
        $this->assertSame(array_values(array_diff(InstallEngine::VERSION_ORDER, [MoveAsideStep::KEY])), array_column($install->steps ?? [], 'key'));

        $server = $server->fresh() ?? $server;
        $this->assertSame($this->fabric->id, $server->egg_id);
        $this->assertSame('26.2', $this->variable($server, 'MC_VERSION'));
        $this->assertSame('0.20.1', $this->variable($server, 'LOADER_VERSION'));
        $this->assertSame('server.jar', $this->variable($server, 'SERVER_JARFILE'));
        $this->assertSame('26.2', ServerTarget::minecraftVersion($server));

        // mods/ and config/ stay where they are; worlds and player files are back, byte for byte.
        $this->assertSame('old', $this->files->files['mods/old-mod.jar'] ?? null);
        $this->assertSame('old config', $this->files->files['config/old.toml'] ?? null);
        $this->assertSame($kept, $this->keptFiles());
        $this->assertNull($install->moved_aside_path);
        $this->assertSame([], $this->files->violations);
        $this->assertSame([], $this->files->callsOf('pull'));
        $this->assertSame(1, $this->reinstalls);
        $this->assertSame(1, $this->backupsCreated);
        $this->assertContains('version_mods_kept', array_column($install->log ?? [], 'key'));

        $this->assertSame([
            'from' => ['loader' => 'fabric', 'minecraft' => '26.3'],
            'to' => ['loader' => 'fabric', 'minecraft' => '26.2', 'loader_version' => '0.20.1'],
            'mods_kept' => true,
        ], $install->summary['version_change'] ?? null);
        $summary = (string) InstallProgress::summary($install, true);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.version_changed', ['from' => 'Fabric 26.3', 'to' => 'Fabric 26.2', 'loader' => '0.20.1'])), $summary);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.version_mods_kept')), $summary);
    }

    public function test_another_loader_moves_mods_and_config_aside_switches_the_egg_and_keeps_worlds(): void
    {
        $server = $this->fabricServerOnTaggedEgg();
        $kept = $this->keptFiles();
        $this->reinstallCreates = LoaderSwitchTest::NEOFORGE_LAUNCH_FILES;

        $this->start($server, 'neoforge', '1.21.1', '21.1.77', $this->neoforge);
        $this->runChange($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, $this->failure($install));
        $this->assertSame(InstallEngine::VERSION_ORDER, array_column($install->steps ?? [], 'key'));

        $server = $server->fresh() ?? $server;
        $this->assertSame($this->neoforge->id, $server->egg_id);
        $this->assertSame('1.21.1', $this->variable($server, 'MC_VERSION'));
        $this->assertSame('21.1.77', $this->variable($server, 'NEOFORGE_VERSION'));
        $this->assertSame('ghcr.io/pelican-eggs/yolks:java_21', $server->image);

        $aside = (string) $install->moved_aside_path;
        $this->assertStringStartsWith('.modpacks-plus/previous-', $aside);
        $this->assertArrayNotHasKey('mods/old-mod.jar', $this->files->files);
        $this->assertSame('old', $this->files->files["$aside/mods/old-mod.jar"] ?? null);
        $this->assertSame('old config', $this->files->files["$aside/config/old.toml"] ?? null);
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame([], $this->files->violations);
        $this->assertFalse($install->summary['version_change']['mods_kept'] ?? true);
        $this->assertSame(['loader' => 'fabric', 'minecraft' => '1.20.1'], $install->summary['version_change']['from'] ?? null);
    }

    public function test_a_failed_reinstall_after_a_loader_change_ends_failed_and_restore_puts_everything_back(): void
    {
        $server = $this->fabricServerOnTaggedEgg();
        $kept = $this->keptFiles();
        $this->reinstallOutcome = ServerState::ReinstallFailed;

        $this->start($server, 'neoforge', '1.21.1', '21.1.77', $this->neoforge);
        $this->runChange($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('reinstall_failed', $install->error);
        $this->assertSame([], $this->keptFiles());
        foreach ($kept as $path => $contents) {
            $this->assertSame($contents, $this->files->files[$install->preserved_path."/$path"] ?? null, $path);
        }
        $this->assertTrue($install->isRestorable());
        $this->assertTrue(PackInstall::restoreCandidate($server->id)?->is($install) ?? false);

        $this->restore($install);

        $install->refresh();
        $this->assertNotNull($install->restored_at, (string) json_encode($install->log));
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame('old', $this->files->files['mods/old-mod.jar'] ?? null);
        $this->assertSame('old config', $this->files->files['config/old.toml'] ?? null);
        $this->assertSame([], $this->files->violations);
    }

    public function test_a_failed_reinstall_of_the_same_loader_keeps_mods_and_restore_puts_worlds_back(): void
    {
        $server = $this->fabricServerOnOfficialEgg();
        $kept = $this->keptFiles();
        $this->reinstallOutcome = ServerState::InstallFailed;

        $this->start($server, 'fabric', '26.2', '0.20.1', $this->fabric);
        $this->runChange($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('reinstall_failed', $install->error);
        $this->assertSame('old', $this->files->files['mods/old-mod.jar'] ?? null);
        $this->assertTrue($install->isRestorable());

        $this->restore($install);

        $this->assertNotNull($install->fresh()?->restored_at);
        $this->assertSame($kept, $this->keptFiles());
        $this->assertSame('old', $this->files->files['mods/old-mod.jar'] ?? null);
        $this->assertSame([], $this->files->violations);
    }

    public function test_a_script_that_left_only_the_old_launch_jar_fails_reinstall_incomplete(): void
    {
        $server = $this->fabricServerOnOfficialEgg();
        $this->reinstallCreates = [];

        $this->start($server, 'fabric', '26.2', '0.20.1', $this->fabric);
        $this->runChange($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('reinstall_incomplete', $install->error);
        $this->assertTrue($install->isRestorable());
    }

    public function test_a_failed_install_is_repaired_by_a_version_change_and_a_suspended_server_is_refused(): void
    {
        $server = $this->fabricServerOnOfficialEgg();
        $server->forceFill(['status' => ServerState::InstallFailed])->save();

        $this->start($server, 'fabric', '26.2', '0.20.1', $this->fabric);
        $this->runChange($server);
        $this->assertSame(PackInstall::STATUS_INSTALLED, $this->latestInstall()->status, $this->failure($this->latestInstall()));

        $server = $server->fresh() ?? $server;
        $server->forceFill(['status' => ServerState::Suspended])->save();
        try {
            $this->start($server, 'fabric', '26.3', '0.20.1', $this->fabric);
            $this->fail('expected server_unavailable');
        } catch (InstallRefusedException $exception) {
            $this->assertSame('server_unavailable', $exception->reason);
            $this->assertSame(ServerAvailability::SUSPENDED, $exception->detail);
        }
    }

    public function test_a_version_the_egg_does_not_take_fails_before_the_backup(): void
    {
        $server = $this->fabricServerOnOfficialEgg();
        $before = $this->files->files;

        $this->start($server, 'fabric', '26.2;id', '0.20.1', $this->fabric);
        $this->runChange($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('version_unsupported', $install->error);
        $this->assertSame(PackInstall::STEP_FAILED, $install->stepStatus(VersionPrepareStep::KEY));
        $this->assertSame(PackInstall::STEP_PENDING, $install->stepStatus(BackupStep::KEY));
        $this->assertSame(0, $this->backupsCreated);
        $this->assertSame($before, $this->files->files);
        $this->assertSame('26.3', $this->variable($server->fresh() ?? $server, 'MC_VERSION'));
    }

    public function test_the_engine_never_runs_a_version_change_without_its_target(): void
    {
        [, $server] = $this->scenarioServer();

        $this->expectException(InstallRefusedException::class);
        app(InstallEngine::class)->start($server, null, PackInstall::VERSION_PROVIDER, 'fabric', '26.2', 'x');
    }

    /** Review fix: a loader change moves only the pack folders; the pack's other files stay put. */
    public function test_a_loader_change_leaves_the_installed_packs_other_files_in_place_and_clears_its_update_flags(): void
    {
        $server = $this->fabricServerOnTaggedEgg();
        $this->files->seed(['server-icon.png' => 'icon']);
        $pack = PackInstall::query()->create([
            'server_id' => $server->id, 'provider' => 'modrinth', 'pack_id' => 'AABBCCDD', 'version_id' => 'VERSID01',
            'name' => 'Test Pack', 'version_number' => '1.0.0', 'status' => PackInstall::STATUS_INSTALLED, 'seq' => 2,
            'manifest' => [
                ['path' => 'server-icon.png', 'size' => 4, 'hash' => 'sha1:'.sha1('icon'), 'kept' => false],
                ['path' => 'mods/old-mod.jar', 'size' => 3, 'hash' => 'sha1:'.sha1('old'), 'kept' => false],
            ],
            'update_available' => true, 'latest_version_id' => 'VERSID02', 'latest_version_number' => '2.0.0', 'notified_version' => 'VERSID02', 'finished_at' => now(),
        ]);
        $this->reinstallCreates = LoaderSwitchTest::NEOFORGE_LAUNCH_FILES;

        $this->start($server, 'neoforge', '1.21.1', '21.1.77', $this->neoforge);
        $this->runChange($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, $this->failure($install));
        $this->assertSame($pack->id, $install->previous_install_id);
        $this->assertSame('icon', $this->files->files['server-icon.png'] ?? null);
        $this->assertArrayNotHasKey('mods/old-mod.jar', $this->files->files);

        $pack->refresh();
        $this->assertFalse($pack->update_available);
        $this->assertNull($pack->latest_version_id);
        $this->assertNull($pack->notified_version);
        $this->assertSame($pack->id, PackInstall::installedPack($server->id)?->id);
    }

    public function test_a_failed_version_change_keeps_the_packs_update_flags(): void
    {
        $server = $this->fabricServerOnOfficialEgg();
        $pack = PackInstall::query()->create([
            'server_id' => $server->id, 'provider' => 'modrinth', 'pack_id' => 'AABBCCDD', 'version_id' => 'VERSID01',
            'name' => 'Test Pack', 'status' => PackInstall::STATUS_INSTALLED, 'seq' => 2, 'update_available' => true, 'latest_version_id' => 'VERSID02',
        ]);
        $this->reinstallOutcome = ServerState::ReinstallFailed;

        $this->start($server, 'fabric', '26.2', '0.20.1', $this->fabric);
        $this->runChange($server);

        $this->assertSame(PackInstall::STATUS_FAILED, $this->latestInstall()->status);
        $this->assertTrue($pack->fresh()?->update_available);
    }
}
