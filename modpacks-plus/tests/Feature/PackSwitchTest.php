<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Enums\ServerState;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Server;
use App\Services\Servers\ReinstallServerService;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Jobs\RestorePreviousFilesJob;
use DrMxrcy\ModpacksPlus\Jobs\Steps\AwaitReinstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\DownloadStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\InstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\MoveAsideStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\PackVersions;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

/**
 * Plan 36 step 4: switching to another pack. The old pack's files (its folders and every file
 * its manifest lists) go to `.modpacks-plus/previous-<date>/`; worlds, ops, whitelist and bans
 * stay, also across a loader switch. "Start a fresh world" moves the world aside, never deletes it.
 */
class PackSwitchTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use PackVersions;
    use RefreshDatabase;

    private Egg $neoforge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();

        $reinstall = Mockery::mock(ReinstallServerService::class);
        $reinstall->shouldReceive('handle')->andReturnUsing(function (Server $server) {
            $server->forceFill(['status' => ServerState::Installing])->save();

            return $server;
        });
        $this->app->instance(ReinstallServerService::class, $reinstall);

        /** @var Egg $egg */
        $egg = Egg::factory()->create([
            'uuid' => LoaderEggResolverTest::NEOFORGE_UUID,
            'name' => 'NeoForge',
            'tags' => ['minecraft'],
            'update_url' => LoaderEggResolverTest::NEOFORGE_URL,
            'startup_commands' => ['Default' => 'java @unix_args.txt'],
            'docker_images' => ['Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21'],
        ]);
        EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'MC_VERSION', 'default_value' => 'latest', 'rules' => ['required', 'string', 'max:9']]);
        EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'NEOFORGE_VERSION', 'default_value' => '', 'rules' => ['nullable', 'regex:/^[0-9\.\-]+$/']]);
        $this->neoforge = $egg->fresh() ?? $egg;
    }

    /** Pack A (Fabric 1.20.1) installed with a world and player lists; packs B (Fabric) and C (NeoForge 1.21.1) on offer. */
    private function serverWithPackA(): Server
    {
        [, $server] = $this->scenarioServer();
        $this->seedPlayerData();

        $this->packVersion('AVERS001', '1.0.0', ['mods/a.jar' => 'a-mod'], [
            'overrides/config/a.toml' => 'a',
            'overrides/server-icon.png' => 'icon-a',
            'overrides/packmenu/menu.json' => '{}',
        ], 'PACKAAAA');
        $this->packVersion('BVERS001', '1.0.0', ['mods/b.jar' => 'b-mod'], ['overrides/config/b.toml' => 'b'], 'PACKBBBB');
        $this->packVersion('CVERS001', '1.0.0', ['mods/c.jar' => 'c-mod'], ['overrides/config/c.toml' => 'c'], 'PACKCCCC', ['minecraft' => '1.21.1', 'neoforge' => '21.1.77']);

        $a = $this->runVersion($server, 'AVERS001', packId: 'PACKAAAA');
        $this->assertSame(PackInstall::STATUS_INSTALLED, $a->status, (string) json_encode($a->log));
        $this->files->seed(['user-notes.txt' => 'mine']);

        return $server;
    }

    /** @param  array<string, mixed>  $options */
    private function switchLoader(Server $server, array $options = [], ?\Closure $between = null): PackInstall
    {
        return $this->runVersion($server, 'CVERS001', $options, function (InstallStep $job, PackInstall $install) use ($server, $between) {
            if ($job instanceof AwaitReinstallStep) {
                $fresh = $server->fresh();
                if ($fresh?->status === ServerState::Installing) {
                    $fresh->forceFill(['status' => null, 'installed_at' => now()])->save();
                    $this->files->seed(LoaderSwitchTest::NEOFORGE_LAUNCH_FILES);
                }
            }
            if ($between !== null) {
                $between($job, $install);
            }
        }, 'PACKCCCC', ['loader' => 'neoforge', 'minecraft' => '1.21.1', 'egg_id' => $this->neoforge->id, 'egg_name' => 'NeoForge']);
    }

    /** Plan 40: a version change (no pack) recorded after pack A, as the picker leaves it. */
    private function versionChangeAfter(Server $server): PackInstall
    {
        return PackInstall::query()->create([
            'server_id' => $server->id, 'provider' => PackInstall::VERSION_PROVIDER, 'pack_id' => 'fabric', 'version_id' => '1.20.1',
            'name' => 'Fabric 1.20.1', 'status' => PackInstall::STATUS_INSTALLED, 'mode' => PackInstall::MODE_VERSION, 'seq' => 2,
            'steps' => [], 'log' => [], 'progress' => [], 'loader_switch' => ['loader' => 'fabric', 'minecraft' => '1.20.1'],
        ]);
    }

    public function test_after_a_version_change_a_switch_still_replaces_the_old_pack_and_moves_its_files_aside(): void
    {
        $server = $this->serverWithPackA();
        $a = $this->latestInstall();
        $this->versionChangeAfter($server);
        $this->assertSame($a->id, PackInstall::installedPack($server->id)?->id);
        // A loader change moved mods/ aside already: a listed file that is gone is skipped.
        unset($this->files->files['mods/a.jar']);

        $install = $this->runVersion($server, 'BVERS001', packId: 'PACKBBBB');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(PackInstall::MODE_SWITCH, $install->mode);
        $this->assertSame($a->id, $install->previous_install_id);
        $aside = (string) $install->moved_aside_path;
        foreach (['server-icon.png' => 'icon-a', 'packmenu/menu.json' => '{}', 'config/a.toml' => 'a'] as $path => $contents) {
            $this->assertArrayNotHasKey($path, $this->files->files, $path);
            $this->assertSame($contents, $this->files->files["$aside/$path"] ?? null, $path);
        }
        $this->assertSame('b-mod', $this->files->files['mods/b.jar']);
        $this->assertSame([], $this->files->violations);
    }

    public function test_after_a_version_change_the_installed_pack_can_still_be_updated(): void
    {
        $server = $this->serverWithPackA();
        $a = $this->latestInstall();
        $this->packVersion('AVERS002', '2.0.0', ['mods/a.jar' => 'a-mod-2'], ['overrides/config/a.toml' => 'a2'], 'PACKAAAA');
        $this->versionChangeAfter($server);

        $install = $this->runVersion($server, 'AVERS002', ['update' => true], packId: 'PACKAAAA');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(PackInstall::MODE_UPDATE, $install->mode);
        $this->assertSame($a->id, $install->previous_install_id);
        $this->assertSame('a-mod-2', $this->files->files['mods/a.jar'] ?? null);
    }

    public function test_switching_packs_keeps_player_data_and_moves_every_old_pack_file_aside(): void
    {
        $server = $this->serverWithPackA();
        $before = $this->playerData();
        $a = $this->latestInstall();

        $install = $this->runVersion($server, 'BVERS001', packId: 'PACKBBBB');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(PackInstall::MODE_SWITCH, $install->mode);
        $this->assertSame($a->id, $install->previous_install_id);
        $aside = (string) $install->moved_aside_path;
        $this->assertMatchesRegularExpression('#^\.modpacks-plus/previous-\d{8}-\d{6}-\d+$#', $aside);

        // Pack A's folders and its files elsewhere (from its manifest) are all aside.
        foreach (['mods/a.jar' => 'a-mod', 'config/a.toml' => 'a', 'server-icon.png' => 'icon-a', 'packmenu/menu.json' => '{}'] as $path => $contents) {
            $this->assertArrayNotHasKey($path, $this->files->files, $path);
            $this->assertSame($contents, $this->files->files["$aside/$path"] ?? null, $path);
        }
        // Pack B is in place, the user's own file untouched.
        $this->assertSame('b-mod', $this->files->files['mods/b.jar']);
        $this->assertSame('mine', $this->files->files['user-notes.txt']);
        // World, ops, whitelist, bans, server.properties: exactly as before.
        $this->assertSame($before, $this->playerData());
        $this->assertSame([], $this->files->violations);
        $this->assertNull($install->summary['fresh_world'] ?? null);
    }

    public function test_a_pack_switch_stops_a_running_server_before_the_old_pack_moves(): void
    {
        $server = $this->serverWithPackA();
        // Plan 47: same loader, no fresh world, the old pack still running.
        $this->power->state = 'running';
        $this->power->afterStop = 'offline';
        $stops = $this->power->stops;

        $install = $this->runVersion($server, 'BVERS001', packId: 'PACKBBBB');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame($stops + 1, $this->power->stops);
        $this->assertCount(1, array_filter($install->log ?? [], fn (array $line) => $line['key'] === 'stopping_server'));
        $this->assertSame('a-mod', $this->files->files[$install->moved_aside_path.'/mods/a.jar'] ?? null);
        $this->assertSame('b-mod', $this->files->files['mods/b.jar']);
    }

    public function test_a_folder_where_the_old_pack_had_a_file_is_never_moved_on_a_switch(): void
    {
        $server = $this->serverWithPackA();
        // server-icon.png (a file of pack A) is a folder of the user's now.
        unset($this->files->files['server-icon.png']);
        $this->files->seed(['server-icon.png/notes.txt' => 'mine']);

        $install = $this->runVersion($server, 'BVERS001', packId: 'PACKBBBB');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame('mine', $this->files->files['server-icon.png/notes.txt']);
        $this->assertNotContains('server-icon.png', $install->movedFolders());
        $this->assertSame(['server-icon.png'], $install->summary['kept_folders'] ?? null);
        $this->assertSame(1, collect($install->log)->where('key', 'kept_folder')->count());
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.kept_folders', ['paths' => 'server-icon.png'])), (string) InstallProgress::summary($install));
        // Pack A's other recorded file still went aside.
        $this->assertSame('{}', $this->files->files[$install->moved_aside_path.'/packmenu/menu.json'] ?? null);
    }

    public function test_switching_to_a_pack_for_another_loader_keeps_world_ops_whitelist_and_bans(): void
    {
        $server = $this->serverWithPackA();
        $before = $this->playerData();

        $install = $this->switchLoader($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(PackInstall::MODE_SWITCH, $install->mode);
        $this->assertSame($this->neoforge->id, $server->fresh()?->egg_id);
        $this->assertSame($before, $this->playerData());
        $aside = (string) $install->moved_aside_path;
        $this->assertSame('a-mod', $this->files->files["$aside/mods/a.jar"] ?? null);
        $this->assertSame('icon-a', $this->files->files["$aside/server-icon.png"] ?? null);
        $this->assertSame('c-mod', $this->files->files['mods/c.jar']);
        $this->assertSame([], $this->files->violations);
    }

    public function test_a_fresh_world_moves_the_world_aside_and_keeps_the_player_lists(): void
    {
        $server = $this->serverWithPackA();
        $before = $this->playerData();
        $this->power->state = 'running';
        $this->power->afterStop = 'offline';

        $install = $this->runVersion($server, 'BVERS001', ['fresh_world' => true], packId: 'PACKBBBB');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(1, $this->power->stops);
        $aside = (string) $install->moved_aside_path;
        foreach (['world/level.dat', 'world/region/r.0.0.mca', 'world_nether/DIM-1/x', 'world_the_end/DIM1/y'] as $path) {
            $this->assertArrayNotHasKey($path, $this->files->files, $path);
            $this->assertSame($before[$path], $this->files->files["$aside/$path"] ?? null, $path);
        }
        foreach (['ops.json', 'whitelist.json', 'banned-players.json', 'banned-ips.json', 'usercache.json', 'server.properties'] as $path) {
            $this->assertSame($before[$path], $this->files->files[$path], $path);
        }
        // The summary says where the old world is.
        $this->assertSame(['world', 'world_nether', 'world_the_end'], $install->summary['fresh_world'] ?? null);
        $this->assertSame($aside, $install->summary['moved_aside_path'] ?? null);
        $this->assertSame([], $this->files->violations);
    }

    public function test_a_fresh_world_switch_that_is_cancelled_can_put_the_world_back(): void
    {
        $server = $this->serverWithPackA();
        $before = $this->playerData();

        $install = $this->runVersion($server, 'BVERS001', ['fresh_world' => true], function (InstallStep $job, PackInstall $install) {
            if ($job instanceof DownloadStep) {
                $install->forceFill(['cancel_requested_at' => now()])->save();
                // The server is started meanwhile and makes a new world.
                $this->files->seed(['world/level.dat' => 'new world']);
            }
        }, 'PACKBBBB');

        $this->assertSame(PackInstall::STATUS_CANCELLED, $install->status);
        $this->assertTrue($install->isRestorable());

        $install->forceFill(['restore_requested_at' => now()])->save();
        (new RestorePreviousFilesJob($install->id))->handle();

        $install->refresh();
        $this->assertNotNull($install->restored_at, (string) json_encode($install->log));
        $this->assertSame($before, $this->playerData());
        $replaced = (string) $install->progressValue('restore_replaced_path');
        $this->assertSame('new world', $this->files->files["$replaced/world/level.dat"] ?? null);
        $this->assertSame([], $this->files->violations);
    }

    public function test_a_crash_right_after_the_world_moved_still_restores_it(): void
    {
        $server = $this->serverWithPackA();
        $before = $this->playerData();
        $this->files->afterMove = function (string $from) {
            if ($from === 'world') {
                $this->files->afterMove = null;

                throw new \RuntimeException('worker killed');
            }
        };

        try {
            $this->runVersion($server, 'BVERS001', ['fresh_world' => true], packId: 'PACKBBBB');
            $this->fail('the worker should have died');
        } catch (\RuntimeException $exception) {
            $this->assertSame('worker killed', $exception->getMessage());
        }

        // The queue gives up on the job: the install fails, and the world it recorded comes back.
        $install = $this->latestInstall();
        (new MoveAsideStep($install->id, $install->seq))->failed(new \RuntimeException('worker killed'));
        $install->refresh();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertContains('world', $install->movedFolders());

        $install->forceFill(['restore_requested_at' => now()])->save();
        (new RestorePreviousFilesJob($install->id))->handle();

        $this->assertNotNull($install->fresh()?->restored_at);
        $this->assertSame($before, $this->playerData());
    }

    public function test_a_fresh_world_with_a_loader_switch_keeps_the_player_lists(): void
    {
        $server = $this->serverWithPackA();
        $before = $this->playerData();

        $install = $this->switchLoader($server, ['fresh_world' => true]);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $aside = (string) $install->moved_aside_path;
        $this->assertSame($before['world/level.dat'], $this->files->files["$aside/world/level.dat"] ?? null);
        $this->assertArrayNotHasKey('world/level.dat', $this->files->files);
        foreach (['ops.json', 'whitelist.json', 'banned-players.json', 'banned-ips.json', 'server.properties'] as $path) {
            $this->assertSame($before[$path], $this->files->files[$path], $path);
        }
        $this->assertSame([], $this->files->violations);
    }

    public function test_an_update_ignores_the_fresh_world_option(): void
    {
        $server = $this->serverWithPackA();
        $this->packVersion('AVERS002', '2.0.0', ['mods/a.jar' => 'a-mod-2'], ['overrides/config/a.toml' => 'a2'], 'PACKAAAA');
        $before = $this->playerData();

        $install = $this->runVersion($server, 'AVERS002', ['update' => true, 'fresh_world' => true], packId: 'PACKAAAA');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertFalse($install->option('fresh_world'));
        $this->assertSame($before, $this->playerData());
    }
}
