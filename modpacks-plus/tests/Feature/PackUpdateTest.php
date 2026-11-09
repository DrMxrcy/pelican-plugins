<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Jobs\RestorePreviousFilesJob;
use DrMxrcy\ModpacksPlus\Jobs\Steps\DownloadStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\InstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\PropertiesStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\UpdateApplyStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\UpdateDiffStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\Support\PackVersions;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 36 step 3: updating the installed pack to a newer version. Files the new version
 * drops are moved aside, new and changed ones written, unchanged ones left alone, files the
 * user changed kept (or moved aside with "overwrite my changes"). Worlds, ops, whitelist and
 * bans are never touched, on success or failure.
 */
class PackUpdateTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use PackVersions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
    }

    /** Installs version 1, then makes version 2 available. */
    private function installedV1(): Server
    {
        [, $server] = $this->scenarioServer();
        $this->seedPlayerData();

        $this->packVersion('VERSID01', '1.0.0', [
            'mods/kept.jar' => 'kept-1',
            'mods/dropped.jar' => 'dropped-1',
            'mods/changed.jar' => 'changed-1',
        ], [
            'overrides/config/a.toml' => 'a=1',
            'overrides/config/edited.toml' => 'e=1',
            'overrides/config/same.toml' => 'same',
            'overrides/config/edited-gone.toml' => 'g=1',
        ]);
        $this->packVersion('VERSID02', '2.0.0', [
            'mods/kept.jar' => 'kept-1',
            'mods/changed.jar' => 'changed-2',
            'mods/new.jar' => 'new-2',
        ], [
            'overrides/config/a.toml' => 'a=2',
            'overrides/config/edited.toml' => 'e=2',
            'overrides/config/same.toml' => 'same',
            'overrides/config/new.toml' => 'n=2',
        ]);

        $v1 = $this->runVersion($server, 'VERSID01');
        $this->assertSame(PackInstall::STATUS_INSTALLED, $v1->status, (string) json_encode($v1->log));
        $this->assertNotNull($v1->manifest);

        // The admin edits two configs after installing.
        $this->files->files['config/edited.toml'] = 'e=mine';
        $this->files->files['config/edited-gone.toml'] = 'g=9';

        return $server;
    }

    /** @param  array<string, mixed>  $options */
    private function update(Server $server, array $options = [], ?\Closure $between = null): PackInstall
    {
        return $this->runVersion($server, 'VERSID02', ['update' => true] + $options, $between);
    }

    public function test_update_removes_dropped_files_adds_new_ones_and_keeps_the_users_changes(): void
    {
        $server = $this->installedV1();
        $before = $this->playerData();
        $this->files->calls = [];

        $install = $this->update($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(PackInstall::MODE_UPDATE, $install->mode);
        $this->assertSame(['prepare', 'backup', 'update_diff', 'update_apply', 'download', 'overrides', 'properties', 'finalize'], array_column($install->steps ?? [], 'key'));
        $aside = (string) $install->moved_aside_path;
        $this->assertMatchesRegularExpression('#^\.modpacks-plus/previous-\d{8}-\d{6}-\d+$#', $aside);

        // Dropped by the new version: moved aside, not deleted.
        $this->assertArrayNotHasKey('mods/dropped.jar', $this->files->files);
        $this->assertSame('dropped-1', $this->files->files["$aside/mods/dropped.jar"] ?? null);
        // Changed: the old one aside, the new one in place.
        $this->assertSame('changed-2', $this->files->files['mods/changed.jar']);
        $this->assertSame('changed-1', $this->files->files["$aside/mods/changed.jar"] ?? null);
        $this->assertSame('a=2', $this->files->files['config/a.toml']);
        $this->assertSame('a=1', $this->files->files["$aside/config/a.toml"] ?? null);
        // Added.
        $this->assertSame('new-2', $this->files->files['mods/new.jar']);
        $this->assertSame('n=2', $this->files->files['config/new.toml']);
        // Unchanged: never pulled, written or moved again.
        $this->assertSame('kept-1', $this->files->files['mods/kept.jar']);
        $this->assertSame('same', $this->files->files['config/same.toml']);
        foreach (['mods/kept.jar', 'config/same.toml'] as $path) {
            $this->assertNotContains($path, $this->files->callsOf('write'));
            $this->assertNotContains($path, $this->files->callsOf('rename'));
        }
        $this->assertEqualsCanonicalizing(['mods/changed.jar', 'mods/new.jar'], array_map(fn ($args) => $this->pulledPath((string) $args[0]), $this->files->argsOf('pull')));

        // The user's edits are kept and reported: one the new version changes, one it drops.
        $this->assertSame('e=mine', $this->files->files['config/edited.toml']);
        $this->assertSame('g=9', $this->files->files['config/edited-gone.toml']);
        $summary = $install->summary['update'] ?? [];
        $this->assertSame(['config/edited-gone.toml', 'config/edited.toml'], $summary['kept_modified']);
        $this->assertSame([], $summary['overwritten']);
        $this->assertSame(['mods/dropped.jar'], $summary['removed_paths']);
        $this->assertSame(2, $summary['added']);
        $this->assertSame(['config/edited-gone.toml', 'config/edited.toml'], $install->progressValue('kept_modified'));

        // The new manifest marks the kept file with the pack's hash, so it stays "changed by you".
        $manifest = $install->manifestEntries() ?? [];
        $this->assertTrue($manifest['config/edited.toml']['kept']);
        $this->assertSame('sha512:'.hash('sha512', 'e=2'), $manifest['config/edited.toml']['hash']);
        $this->assertArrayNotHasKey('mods/dropped.jar', $manifest);
        $this->assertArrayNotHasKey('config/edited-gone.toml', $manifest);

        // The summary on the page names the kept and the dropped files.
        $html = (string) InstallProgress::summary($install);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.update_kept', ['paths' => 'config/edited-gone.toml, config/edited.toml'])), $html);
        $this->assertStringContainsString(e(trans('modpacks-plus::strings.summary.update_removed', ['paths' => 'mods/dropped.jar', 'path' => $aside])), $html);

        // Worlds, ops, whitelist, bans and server.properties untouched.
        $this->assertSame($before, $this->playerData());
        $this->assertSame([], $this->files->violations);
    }

    private function pulledPath(string $url): string
    {
        return (string) ($this->indexFiles[$url]['path'] ?? $url);
    }

    public function test_overwrite_my_changes_moves_the_users_files_aside_and_lists_them(): void
    {
        $server = $this->installedV1();
        $before = $this->playerData();

        $install = $this->update($server, ['overwrite_changes' => true]);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $aside = (string) $install->moved_aside_path;
        $this->assertSame('e=2', $this->files->files['config/edited.toml']);
        $this->assertSame('e=mine', $this->files->files["$aside/config/edited.toml"] ?? null);
        $this->assertArrayNotHasKey('config/edited-gone.toml', $this->files->files);
        $this->assertSame('g=9', $this->files->files["$aside/config/edited-gone.toml"] ?? null);
        $this->assertSame(['config/edited-gone.toml', 'config/edited.toml'], $install->summary['update']['overwritten'] ?? null);
        $this->assertSame([], $install->summary['update']['kept_modified'] ?? null);
        $this->assertFalse(($install->manifestEntries() ?? [])['config/edited.toml']['kept']);
        $this->assertSame($before, $this->playerData());
    }

    public function test_a_same_size_edit_is_found_by_its_hash(): void
    {
        $server = $this->installedV1();
        $this->files->files['config/a.toml'] = 'a=7';

        $install = $this->update($server);

        $this->assertSame('a=7', $this->files->files['config/a.toml']);
        $this->assertContains('config/a.toml', $install->summary['update']['kept_modified'] ?? []);
        $this->assertContains('config/a.toml', $this->files->callsOf('read'));
    }

    public function test_a_file_missing_from_the_server_is_written_again(): void
    {
        $server = $this->installedV1();
        unset($this->files->files['mods/kept.jar'], $this->files->files['config/same.toml']);

        $install = $this->update($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertSame('kept-1', $this->files->files['mods/kept.jar']);
        $this->assertSame('same', $this->files->files['config/same.toml']);
    }

    public function test_a_file_the_pack_did_not_install_at_a_new_path_is_moved_aside(): void
    {
        $server = $this->installedV1();
        $this->files->files['config/new.toml'] = 'made by hand';

        $install = $this->update($server);

        $aside = (string) $install->moved_aside_path;
        $this->assertSame('n=2', $this->files->files['config/new.toml']);
        $this->assertSame('made by hand', $this->files->files["$aside/config/new.toml"] ?? null);
        $this->assertSame(['config/new.toml'], $install->summary['update']['not_ours'] ?? null);
    }

    public function test_update_is_refused_without_a_manifest_or_for_another_pack(): void
    {
        $server = $this->installedV1();
        $engine = app(InstallEngine::class);

        PackInstall::query()->where('status', PackInstall::STATUS_INSTALLED)->update(['manifest' => null]);
        try {
            $engine->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, 'VERSID02', 'P', '2.0.0', null, null, ['update' => true]);
            $this->fail('An update without a manifest must be refused');
        } catch (InstallRefusedException $exception) {
            $this->assertSame('update_no_manifest', $exception->reason);
        }

        try {
            $engine->start($server, null, ModrinthPackProvider::ID, 'OTHERPK1', 'VERSID02', 'P', '2.0.0', null, null, ['update' => true]);
            $this->fail('An update of another pack must be refused');
        } catch (InstallRefusedException $exception) {
            $this->assertSame('update_no_manifest', $exception->reason);
        }

        try {
            $engine->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, 'VERSID02', 'P', '2.0.0', null, ['loader' => 'forge', 'minecraft' => '1.20.1', 'egg_id' => 1, 'egg_name' => 'Forge'], ['update' => true]);
            $this->fail('An update never switches the loader');
        } catch (InstallRefusedException $exception) {
            $this->assertSame('update_needs_switch', $exception->reason);
        }

        $this->assertSame(1, PackInstall::query()->count());
    }

    public function test_the_update_waits_for_the_server_to_stop_before_reading_or_moving(): void
    {
        $server = $this->installedV1();
        $this->power->state = 'running';
        $this->power->afterStop = 'stopping';

        $install = $this->update($server, between: function (InstallStep $job) {
            // Nothing is read or moved while the server still runs.
            if ($this->power->state !== 'offline') {
                $this->assertNotContains('config/edited.toml', $this->files->callsOf('read'));
            }
            if ($job instanceof UpdateDiffStep && $this->power->stops > 0) {
                $this->power->state = 'offline';
            }
        });

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame(1, $this->power->stops);
    }

    public function test_a_failed_update_keeps_player_data_and_restore_puts_old_files_back(): void
    {
        $server = $this->installedV1();
        $before = $this->playerData();
        // The node gets a broken copy of the new mod (wrong size): the download step fails.
        foreach ($this->indexFiles as $url => $entry) {
            if ($entry['path'] === 'mods/new.jar') {
                $this->files->remote[$url] = 'truncated';
            }
        }

        $install = $this->update($server);

        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame($before, $this->playerData());
        $aside = (string) $install->moved_aside_path;
        $this->assertSame('dropped-1', $this->files->files["$aside/mods/dropped.jar"] ?? null);
        $this->assertTrue($install->isRestorable());

        // "Restore previous files": the moved files go back, what replaced them is kept aside.
        $install->forceFill(['restore_requested_at' => now()])->save();
        (new RestorePreviousFilesJob($install->id))->handle();

        $install->refresh();
        $this->assertNotNull($install->restored_at, (string) json_encode($install->log));
        $this->assertSame('dropped-1', $this->files->files['mods/dropped.jar']);
        $this->assertSame('changed-1', $this->files->files['mods/changed.jar']);
        $this->assertSame('a=1', $this->files->files['config/a.toml']);
        $this->assertSame('e=mine', $this->files->files['config/edited.toml']);
        $this->assertSame($before, $this->playerData());
        $this->assertSame([], $this->files->violations);
    }

    /** @return array<string, array{string, class-string<InstallStep>}> */
    public static function interruptions(): array
    {
        return [
            'cancel while downloading' => ['cancel', DownloadStep::class],
            'deadline while downloading' => ['deadline', DownloadStep::class],
            'cancel while moving' => ['cancel', UpdateApplyStep::class],
            'deadline while moving' => ['deadline', UpdateApplyStep::class],
        ];
    }

    /** @param  class-string<InstallStep>  $at */
    #[DataProvider('interruptions')]
    public function test_cancel_and_deadline_during_the_update_never_touch_player_data(string $how, string $at): void
    {
        $server = $this->installedV1();
        $before = $this->playerData();

        $install = $this->update($server, between: function (InstallStep $job, PackInstall $install) use ($how, $at) {
            if ($job instanceof $at) {
                $how === 'cancel'
                    ? $install->forceFill(['cancel_requested_at' => now()])->save()
                    : $install->forceFill(['step_deadline_at' => now()->subMinute()])->save();
            }
        });

        $this->assertSame($how === 'cancel' ? PackInstall::STATUS_CANCELLED : PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame($before, $this->playerData());
        $this->assertSame('e=mine', $this->files->files['config/edited.toml']);
        $this->assertSame([], $this->files->violations);
    }

    public function test_a_tampered_manifest_can_never_move_a_world_or_player_file(): void
    {
        $server = $this->installedV1();
        $before = $this->playerData();
        $v1 = PackInstall::query()->where('version_id', 'VERSID01')->firstOrFail();
        $manifest = $v1->manifest ?? [];
        foreach (['world/level.dat', 'ops.json', 'whitelist.json', 'banned-players.json', 'server.properties', 'world_nether/DIM-1/x'] as $path) {
            $manifest[] = ['path' => $path, 'size' => 1, 'hash' => 'sha1:'.sha1('x'), 'kept' => false];
        }
        $v1->forceFill(['manifest' => $manifest])->save();

        $install = $this->update($server, ['overwrite_changes' => true]);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame($before, $this->playerData());
        $this->assertSame([], $this->files->violations);
    }

    public function test_an_update_shipping_server_properties_merges_it(): void
    {
        [, $server] = $this->scenarioServer();
        $server->allocation->forceFill(['port' => 25600])->save();
        $this->seedPlayerData();
        $this->files->files['server.properties'] = "# mine\nmotd=hi\nlevel-name=world\nallow-flight=false\nserver-port=25565\n";

        $this->packVersion('VERSID01', '1.0.0', ['mods/a.jar' => 'a'], ['overrides/config/x.toml' => 'x']);
        $this->packVersion('VERSID02', '2.0.0', ['mods/a.jar' => 'a'], [
            'overrides/config/x.toml' => 'x',
            'overrides/server.properties' => "motd=Pack\nallow-flight=true\nlevel-name=elsewhere\nlevel-type=skyblock\n",
        ]);
        $this->runVersion($server, 'VERSID01');
        $before = array_diff_key($this->playerData(), ['server.properties' => true]);

        $install = $this->update($server);

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame("# mine\nmotd=hi\nlevel-name=world\nallow-flight=true\nserver-port=25600\nlevel-type=skyblock\nquery.port=25600\n", $this->files->files['server.properties']);
        $aside = (string) $install->moved_aside_path;
        $this->assertSame("# mine\nmotd=hi\nlevel-name=world\nallow-flight=false\nserver-port=25565\n", $this->files->files["$aside/server.properties"] ?? null);
        $this->assertSame('merged', $install->summary['properties'] ?? null);
        $this->assertContains('server.properties', $install->movedFolders());
        $this->assertSame($before, array_diff_key($this->playerData(), ['server.properties' => true]));
        // Only ever moved, never written in place.
        $this->assertNotContains('server.properties', $this->files->callsOf('write'));
        $this->assertSame([], $this->files->violations);
    }

    public function test_the_properties_swap_resumes_after_a_crash_and_survives_a_server_start(): void
    {
        [, $server] = $this->scenarioServer();
        $server->allocation->forceFill(['port' => 25565])->save();
        $this->seedPlayerData();
        $this->packVersion('VERSID01', '1.0.0', ['mods/a.jar' => 'a'], ['overrides/server.properties' => "pvp=false\n"]);

        // The worker dies right after the server's own file was set aside; then the server is
        // started (it writes a default file) before the job runs again.
        $crashed = false;
        $this->files->afterMove = function (string $from) use (&$crashed) {
            if ($from === 'server.properties' && !$crashed) {
                $crashed = true;

                throw new \RuntimeException('worker killed');
            }
        };

        try {
            $this->runVersion($server, 'VERSID01');
        } catch (\RuntimeException $exception) {
            $this->assertSame('worker killed', $exception->getMessage());
        }
        $this->files->afterMove = null;
        $install = $this->latestInstall();
        $this->assertTrue($install->isActive());
        $this->assertFalse(PropertiesStep::cancellableAt($install));
        $this->files->files['server.properties'] = "level-name=world\nmotd=A Minecraft Server\n";

        // The retry (same job, the queue's next try) finishes the swap.
        $job = new PropertiesStep($install->id, $install->seq);
        $this->app->call([$job, 'handle']);
        $this->runJobs();

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $aside = (string) $install->moved_aside_path;
        $this->assertSame("motd=hi\nlevel-name=world\n", $this->files->files["$aside/server.properties"]);
        $this->assertSame("motd=hi\nlevel-name=world\npvp=false\nserver-port=25565\nquery.port=25565\n", $this->files->files['server.properties']);
        $replaced = array_values(array_filter(array_keys($this->files->files), fn (string $path) => str_starts_with($path, '.modpacks-plus/replaced-') && str_ends_with($path, '/server.properties')));
        $this->assertCount(1, $replaced);
        $this->assertSame("level-name=world\nmotd=A Minecraft Server\n", $this->files->files[$replaced[0]]);
    }

    public function test_an_unmergeable_pack_properties_file_leaves_the_servers_untouched(): void
    {
        [, $server] = $this->scenarioServer();
        $this->seedPlayerData();
        $this->packVersion('VERSID01', '1.0.0', ['mods/a.jar' => 'a'], ['overrides/server.properties' => "level\\-name=evil\n"]);

        $install = $this->runVersion($server, 'VERSID01');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status);
        $this->assertSame("motd=hi\nlevel-name=world\n", $this->files->files['server.properties']);
        $this->assertSame('unmergeable', $install->summary['properties'] ?? null);
        $this->assertSame([], array_values(array_filter($this->files->argsOf('movePreserved'), fn (array $args) => $args[0] === 'server.properties')));
    }

    public function test_a_pack_can_not_name_the_world_or_set_the_port_through_server_properties(): void
    {
        [, $server] = $this->scenarioServer();
        $server->allocation->forceFill(['port' => 25565])->save();
        $this->seedPlayerData();
        // The server's file names no world: the pack tries to make mods/ the world.
        $this->files->files['server.properties'] = "motd=hi\n";
        $this->packVersion('VERSID01', '1.0.0', ['mods/a.jar' => 'a'], ['overrides/server.properties' => "level-name=mods\nserver-port=1\nquery.port=2\npvp=false\n"]);

        $install = $this->runVersion($server, 'VERSID01');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame("motd=hi\nserver-port=25565\nquery.port=25565\npvp=false\n", $this->files->files['server.properties']);
    }

    public function test_an_unsafe_level_name_on_the_server_leaves_its_file_untouched(): void
    {
        [, $server] = $this->scenarioServer();
        $this->files->seed(['server.properties' => "level-name=config/world\n", 'config/world/level.dat' => 'level']);
        $this->packVersion('VERSID01', '1.0.0', ['mods/a.jar' => 'a'], ['overrides/server.properties' => "pvp=false\n"]);

        $install = $this->runVersion($server, 'VERSID01');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertSame("level-name=config/world\n", $this->files->files['server.properties']);
        $this->assertSame('unmergeable', $install->summary['properties'] ?? null);
        $this->assertSame('level', $this->files->files['config/world/level.dat']);
    }

    public function test_a_pack_without_server_properties_skips_the_merge(): void
    {
        $server = $this->installedV1();

        $install = $this->update($server);

        $this->assertSame(PackInstall::STEP_SKIPPED, $install->stepStatus(PropertiesStep::KEY));
        $this->assertNull($install->summary['properties'] ?? null);
        $this->assertSame([], array_values(array_filter($this->files->argsOf('movePreserved'), fn (array $args) => in_array('server.properties', [basename((string) $args[0]), basename((string) $args[1])], true))));
    }
}
