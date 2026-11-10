<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Jobs\RestorePreviousFilesJob;
use DrMxrcy\ModpacksPlus\Jobs\Steps\DownloadStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\InstallStep;
use DrMxrcy\ModpacksPlus\Jobs\Steps\MoveAsideStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\PackVersions;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Plan 44: switching away from a pack with ~1200 files outside its folders. Move-aside and
 * "Restore previous files" rename them in batches (ServerFiles::RENAME_BATCH per request)
 * instead of one daemon request per file, with the same layout, summary and safety rules.
 */
class BatchRenameTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use PackVersions;
    use RefreshDatabase;

    private const COUNT = 1200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
    }

    /** @return array<string, string> path => contents of pack A's files outside its folders */
    private function bigFiles(): array
    {
        $files = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $files[sprintf('journeymap/data/%02d/f%04d.json', $i % 12, $i)] = "j$i";
        }

        return $files;
    }

    /** Pack A (1200 override files outside mods/config) installed with a world and player lists. */
    private function serverWithBigPack(): Server
    {
        [, $server] = $this->scenarioServer();
        $this->seedPlayerData();

        $overrides = ['overrides/config/a.toml' => 'a'];
        foreach ($this->bigFiles() as $path => $contents) {
            $overrides["overrides/$path"] = $contents;
        }
        $this->packVersion('AVERS001', '1.0.0', ['mods/a.jar' => 'a-mod'], $overrides, 'PACKAAAA');
        $this->packVersion('BVERS001', '1.0.0', ['mods/b.jar' => 'b-mod'], ['overrides/config/b.toml' => 'b'], 'PACKBBBB');

        $a = $this->runVersion($server, 'AVERS001', packId: 'PACKAAAA');
        $this->assertSame(PackInstall::STATUS_INSTALLED, $a->status, (string) json_encode($a->log));
        $this->files->seed(['user-notes.txt' => 'mine']);

        return $server;
    }

    /** @return array<int, array<string, mixed>> renameMany() calls since $from */
    private function batchesSince(int $from): array
    {
        return array_values(array_map(fn ($call) => $call[1][0], array_filter(array_slice($this->files->calls, $from), fn ($call) => $call[0] === 'renameMany')));
    }

    /** @return array<int, string> paths renamed one by one (rename()) since $from, staged pack files aside */
    private function singleRenamesSince(int $from): array
    {
        return array_values(array_filter(
            array_map(fn ($call) => (string) $call[1][0], array_filter(array_slice($this->files->calls, $from), fn ($call) => $call[0] === 'rename')),
            fn (string $path) => !str_starts_with($path, '.modpacks-plus/staging/'),
        ));
    }

    /**
     * What the per-file move-aside recorded: the folders, then the old manifest's other files in its order.
     *
     * @return array<int, string>
     */
    private function expectedMoved(PackInstall $install): array
    {
        $previous = PackInstall::query()->findOrFail($install->previous_install_id);
        $files = array_values(array_filter(array_map('strval', array_keys($previous->manifestEntries() ?? [])), fn (string $path) => !in_array(explode('/', $path)[0], MoveAsideStep::FOLDERS, true)));
        $this->assertEqualsCanonicalizing(array_keys($this->bigFiles()), $files);

        return ['mods', 'config', ...$files];
    }

    private function assertAllAside(PackInstall $install): void
    {
        $aside = (string) $install->moved_aside_path;
        foreach ($this->bigFiles() as $path => $contents) {
            $this->assertArrayNotHasKey($path, $this->files->files, $path);
            $this->assertSame($contents, $this->files->files["$aside/$path"] ?? null, $path);
        }
    }

    /** No batch ever held a world, player list, server.properties, eula.txt or the own folder outside the aside folder. */
    private function assertNoProtectedPathInABatch(): void
    {
        foreach ($this->batchesSince(0) as $batch) {
            $this->assertLessThanOrEqual(ServerFiles::RENAME_BATCH, count($batch));
            foreach ($batch as $pair) {
                foreach ([$pair['from'], $pair['to']] as $path) {
                    $path = (string) preg_replace('#^\.modpacks-plus/(previous|replaced)-[^/]+/#', '', (string) $path);
                    $this->assertDoesNotMatchRegularExpression('#^(world|world_nether|world_the_end|ops\.json|whitelist\.json|banned-players\.json|banned-ips\.json|usercache\.json|server\.properties|eula\.txt|\.modpacks-plus)(/|$)#', $path);
                }
            }
        }
        $this->assertSame([], $this->files->violations);
    }

    public function test_moving_aside_1200_old_pack_files_takes_a_few_rename_requests(): void
    {
        $server = $this->serverWithBigPack();
        $before = $this->playerData();
        $calls = count($this->files->calls);

        $install = $this->runVersion($server, 'BVERS001', packId: 'PACKBBBB');

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertAllAside($install);
        $batches = $this->batchesSince($calls);
        $this->assertCount((int) ceil(self::COUNT / ServerFiles::RENAME_BATCH), $batches);
        $this->assertSame(self::COUNT, array_sum(array_map('count', $batches)));
        // Only the folders moved whole still go one request each.
        $this->assertEqualsCanonicalizing(['mods', 'config'], $this->singleRenamesSince($calls));
        // The summary lists every moved file, in the old manifest's order after the folders.
        $this->assertSame($this->expectedMoved($install), $install->summary['moved'] ?? null);
        // One readable log line per batch.
        $this->assertSame(3, collect($install->log)->where('key', 'moved_aside_batch')->count());
        $this->assertSame(0, collect($install->log)->where('key', 'moved_aside')->whereNotIn('params.path', ['mods', 'config'])->count());
        $this->assertSame('b-mod', $this->files->files['mods/b.jar']);
        $this->assertSame('mine', $this->files->files['user-notes.txt']);
        $this->assertSame($before, $this->playerData());
        $this->assertNoProtectedPathInABatch();
    }

    public function test_restore_puts_1200_files_back_in_batches(): void
    {
        $server = $this->serverWithBigPack();
        $before = $this->playerData();

        $install = $this->runVersion($server, 'BVERS001', ['fresh_world' => true], function (InstallStep $job, PackInstall $install) {
            if ($job instanceof DownloadStep) {
                $install->forceFill(['cancel_requested_at' => now()])->save();
            }
        }, 'PACKBBBB');
        $this->assertSame(PackInstall::STATUS_CANCELLED, $install->status);
        $this->assertAllAside($install);
        $calls = count($this->files->calls);

        $install->forceFill(['restore_requested_at' => now()])->save();
        (new RestorePreviousFilesJob($install->id))->handle();

        $install->refresh();
        $this->assertNotNull($install->restored_at, (string) json_encode($install->log));
        foreach ($this->bigFiles() as $path => $contents) {
            $this->assertSame($contents, $this->files->files[$path] ?? null, $path);
        }
        $this->assertSame('a-mod', $this->files->files['mods/a.jar']);
        $this->assertSame($before, $this->playerData());
        // The 1200 files plus mods and config: 3 requests; the world goes back one by one (movePreserved).
        $batches = $this->batchesSince($calls);
        $this->assertCount(3, $batches);
        $this->assertSame(self::COUNT + 2, array_sum(array_map('count', $batches)));
        $this->assertSame(3, collect($install->log)->where('key', 'restored_batch')->count());
        $this->assertContains('world', array_map(fn (array $args) => (string) $args[1], array_slice($this->files->argsOf('movePreserved'), -3)));
        $this->assertNoProtectedPathInABatch();
    }

    public function test_a_worker_killed_mid_batch_resumes_without_moving_anything_twice(): void
    {
        $server = $this->serverWithBigPack();
        $moved = 0;
        $this->files->afterMove = function (string $from) use (&$moved) {
            if (str_starts_with($from, 'journeymap/') && ++$moved === 700) {
                $this->files->afterMove = null;

                // Not a daemon error (those are caught): the worker process is gone.
                throw new \LogicException('worker killed');
            }
        };

        try {
            $this->runVersion($server, 'BVERS001', packId: 'PACKBBBB');
            $this->fail('the worker should have died');
        } catch (\LogicException $exception) {
            $this->assertSame('worker killed', $exception->getMessage());
        }
        $install = $this->latestInstall();
        $this->assertSame(MoveAsideStep::KEY, $install->current_step);
        $calls = count($this->files->calls);

        // The queue runs the step again.
        $this->app->call([new MoveAsideStep($install->id, $install->seq), 'handle']);
        $this->runJobs();

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertAllAside($install);
        // Only what was left moves: the second batch's last 300 and the third batch's 200.
        $this->assertSame(self::COUNT - 700, array_sum(array_map('count', $this->batchesSince($calls))));
        $this->assertSame([], $this->singleRenamesSince($calls));
        $this->assertSame($this->expectedMoved($install), $install->summary['moved'] ?? null);
        $this->assertNoProtectedPathInABatch();
    }

    public function test_a_restore_killed_mid_batch_resumes_without_moving_anything_twice(): void
    {
        $server = $this->serverWithBigPack();
        $install = $this->runVersion($server, 'BVERS001', [], function (InstallStep $job, PackInstall $install) {
            if ($job instanceof DownloadStep) {
                $install->forceFill(['cancel_requested_at' => now()])->save();
            }
        }, 'PACKBBBB');
        $this->assertSame(PackInstall::STATUS_CANCELLED, $install->status);
        $moved = 0;
        $this->files->afterMove = function () use (&$moved) {
            if (++$moved === 800) {
                $this->files->afterMove = null;

                throw new \LogicException('worker killed');
            }
        };
        $install->forceFill(['restore_requested_at' => now()])->save();

        try {
            (new RestorePreviousFilesJob($install->id))->handle();
            $this->fail('the worker should have died');
        } catch (\LogicException $exception) {
            $this->assertSame('worker killed', $exception->getMessage());
        }
        $calls = count($this->files->calls);
        (new RestorePreviousFilesJob($install->id))->handle();

        $install->refresh();
        $this->assertNotNull($install->restored_at, (string) json_encode($install->log));
        foreach ($this->bigFiles() as $path => $contents) {
            $this->assertSame($contents, $this->files->files[$path] ?? null, $path);
        }
        // Only what was still aside moved; nothing was reported missing.
        $this->assertSame(self::COUNT + 2 - 800, array_sum(array_map('count', $this->batchesSince($calls))));
        $this->assertSame([], $this->files->argsOf('movePreserved'));
        $this->assertSame(0, collect($install->log)->where('key', 'restore_absent')->count());
        $this->assertNoProtectedPathInABatch();
    }

    public function test_a_batch_the_node_refuses_is_moved_file_by_file(): void
    {
        $server = $this->serverWithBigPack();
        $refused = 'journeymap/data/00/f0000.json';
        // The node refuses the first file once (the rest of that batch still moves).
        $this->files->failMoves = [$refused];
        $this->files->afterMove = function () {
            $this->files->failMoves = [];
        };
        $calls = count($this->files->calls);

        $install = $this->runVersion($server, 'BVERS001', packId: 'PACKBBBB');
        $this->files->afterMove = null;

        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        $this->assertAllAside($install);
        $this->assertCount(3, $this->batchesSince($calls));
        // Only the refused file went again, on its own, with its own log line.
        $this->assertEqualsCanonicalizing(['mods', 'config', $refused], $this->singleRenamesSince($calls));
        $this->assertSame(1, collect($install->log)->where('key', 'moved_aside')->where('params.path', $refused)->count());
        $this->assertSame(2, collect($install->log)->where('key', 'moved_aside_batch')->count());
        $this->assertNoProtectedPathInABatch();
    }

    public function test_a_restore_batch_the_node_refuses_goes_back_file_by_file(): void
    {
        $server = $this->serverWithBigPack();
        $install = $this->runVersion($server, 'BVERS001', [], function (InstallStep $job, PackInstall $install) {
            if ($job instanceof DownloadStep) {
                $install->forceFill(['cancel_requested_at' => now()])->save();
            }
        }, 'PACKBBBB');
        $this->assertSame(PackInstall::STATUS_CANCELLED, $install->status);
        $aside = (string) $install->moved_aside_path;
        $this->files->failMoves = ["$aside/mods"];
        $this->files->afterMove = function () {
            $this->files->failMoves = [];
        };

        $install->forceFill(['restore_requested_at' => now()])->save();
        (new RestorePreviousFilesJob($install->id))->handle();
        $this->files->afterMove = null;

        $install->refresh();
        $this->assertNotNull($install->restored_at, (string) json_encode($install->log));
        foreach ($this->bigFiles() as $path => $contents) {
            $this->assertSame($contents, $this->files->files[$path] ?? null, $path);
        }
        $this->assertSame('a-mod', $this->files->files['mods/a.jar']);
        // The refused one went back on its own; the ones the batch moved were found already back.
        $this->assertSame(1, collect($install->log)->where('key', 'restored')->where('params.path', 'mods')->count());
        $this->assertSame(0, collect($install->log)->where('key', 'restore_absent')->count());
        $this->assertNoProtectedPathInABatch();
    }
}
