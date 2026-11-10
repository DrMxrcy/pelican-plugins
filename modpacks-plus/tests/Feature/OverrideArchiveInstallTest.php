<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Jobs\Steps\OverridesStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Tests\Support\FakeServerFiles;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Plan 42: big override sets go to the node as one archive the panel built from the
 * validated entries. Same result on disk as the per-file path, O(1) daemon writes,
 * hostile entries never in the archive nor on disk, resumable at every point, no
 * staged archive left behind.
 */
class OverrideArchiveInstallTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    /** Pack entries that must never reach the server (all dropped by PlanValidator). */
    private const HOSTILE = [
        'overrides/ops.json' => '[{"name":"attacker"}]',
        'overrides/world/level.dat' => 'evil level',
        'overrides/world_nether/DIM-1/x' => 'evil nether',
        'overrides/server.properties' => 'online-mode=false',
        'overrides/.modpacks-plus/staging/overrides-1-0-1.zip' => 'planted archive',
        'overrides/.modpacks-plus/previous-x/mods/a.jar' => 'planted',
        'overrides/mods/.modrinth-metadata.json' => '{"fake":true}',
    ];

    /** What the server holds before the install (worlds, player data, someone else's files). */
    private const SEED = [
        'world/level.dat' => 'level',
        'world_nether/DIM-1/x' => 'nether',
        'ops.json' => '[{"name":"owner"}]',
        'server.properties' => "motd=hi\nlevel-name=world\n",
        'serverconfig/foreign.toml' => 'mine',
        'server-icon.png' => 'my icon',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
    }

    /**
     * $count override files over 12 folders, plus a server-override, two files in the way and
     * the hostile entries.
     *
     * @return array<string, string>
     */
    private function overrides(int $count, bool $extras = true): array
    {
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $entries['overrides/config/pack'.($i % 12).'/file'.$i.'.json'] = str_repeat('x', $i % 7).'{"i":'.$i.'}';
        }

        if ($extras) {
            $entries['server-overrides/config/pack0/file0.json'] = 'server wins';
            $entries['overrides/serverconfig/foreign.toml'] = 'pack version';
            $entries['overrides/server-icon.png'] = 'pack icon';
            $entries += self::HOSTILE;
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $entries
     * @return array<string, string> target => contents the pack must leave on the server
     */
    private static function expected(array $entries): array
    {
        $expected = [];
        foreach (['overrides/', 'server-overrides/'] as $prefix) {
            foreach ($entries as $name => $contents) {
                if (str_starts_with($name, $prefix) && !array_key_exists($name, self::HOSTILE)) {
                    $expected[substr($name, strlen($prefix))] = $contents;
                }
            }
        }

        return $expected;
    }

    private function start(Server $server): PackInstall
    {
        return app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Test Pack', '1.0.0');
    }

    /**
     * Runs the install and returns the daemon calls made by the overrides step.
     *
     * @return array<int, array{string, array<int, mixed>}>
     */
    private function runRecordingOverrides(Server $server): array
    {
        $start = null;
        $end = null;
        $this->runJobs(between: function ($job) use (&$start, &$end, $server) {
            $this->finishBackups($server);
            if ($job instanceof OverridesStep && $start === null) {
                $start = count($this->files->calls);
            }
            if (!$job instanceof OverridesStep && $start !== null && $end === null) {
                $end = count($this->files->calls);
            }
        });

        $this->assertNotNull($start, 'The overrides step never ran');

        return array_slice($this->files->calls, $start, ($end ?? count($this->files->calls)) - $start);
    }

    /** @param  array<int, array{string, array<int, mixed>}>  $calls */
    private static function callCount(array $calls, string $operation): int
    {
        return count(array_filter($calls, fn (array $call) => $call[0] === $operation));
    }

    /** @param  array<int, array{string, array<int, mixed>}>  $calls */
    private static function writeCount(array $calls): int
    {
        return count(array_filter($calls, fn (array $call) => in_array($call[0], ['createDirectory', 'rename', 'movePreserved', 'pull', 'write', 'delete', 'decompress'], true)));
    }

    /** @return array<int, string> */
    private function stagedArchives(): array
    {
        return array_values(array_filter(array_keys($this->files->files), fn ($path) => str_starts_with((string) $path, '.modpacks-plus/staging/overrides-')));
    }

    /** @param  array<string, string>  $entries */
    private function assertApplied(array $entries): void
    {
        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));

        foreach (self::expected($entries) as $path => $contents) {
            $this->assertSame($contents, $this->files->files[$path] ?? null, $path);
        }

        // Worlds and player data untouched; hostile entries nowhere.
        foreach (['world/level.dat', 'world_nether/DIM-1/x', 'ops.json'] as $path) {
            $this->assertSame(self::SEED[$path], $this->files->files[$path]);
        }
        $this->assertStringStartsWith('motd=hi', $this->files->files['server.properties']);
        $this->assertArrayNotHasKey('mods/.modrinth-metadata.json', $this->files->files);
        $this->assertSame([], array_values(array_filter(array_keys($this->files->files), fn ($path) => str_starts_with((string) $path, '.modpacks-plus/previous-x'))));
        foreach ($this->files->extracted as $names) {
            foreach ($names as $name) {
                $this->assertArrayNotHasKey('overrides/'.$name, self::HOSTILE, "hostile entry $name was unpacked");
            }
        }

        // Someone else's files in the way were moved aside, not overwritten.
        $aside = (string) $install->moved_aside_path;
        $this->assertSame('mine', $this->files->files["$aside/serverconfig/foreign.toml"] ?? null);
        $this->assertSame('my icon', $this->files->files["$aside/server-icon.png"] ?? null);
        // ... and none of the pack's own override files.
        $this->assertSame([], array_values(array_filter(array_keys($this->files->files), fn ($path) => str_starts_with((string) $path, "$aside/config/pack"))));

        $this->assertSame([], $this->stagedArchives());
        $this->assertSame([], $this->files->violations);
        $this->assertSame(count(array_filter(array_keys($entries), fn ($name) => str_starts_with((string) $name, 'overrides/') && !array_key_exists($name, self::HOSTILE))), $install->summary['overrides_applied'] ?? null);
    }

    public function test_936_overrides_go_as_one_archive_with_constant_daemon_writes(): void
    {
        [, $server] = $this->scenarioServer();
        $entries = $this->overrides(936);
        $this->scenarioPack($entries);
        $this->files->seed(self::SEED);

        $this->start($server);
        $calls = $this->runRecordingOverrides($server);

        $this->assertApplied($entries);

        // One archive write, one unpack, one delete of it, plus the staging folder and the two
        // files in the way moved aside (2 renames + their aside folders): never one write per file.
        $this->assertSame(1, self::callCount($calls, 'decompress'));
        $this->assertSame(1, self::callCount($calls, 'write'));
        $this->assertSame(1, self::callCount($calls, 'delete'));
        $this->assertSame(2, self::callCount($calls, 'rename'));
        $this->assertLessThanOrEqual(8, self::writeCount($calls), (string) json_encode($calls));
        // Listings are per folder (14 folders: scan + verify), not per file.
        $this->assertLessThanOrEqual(2 * 14 + 4, self::callCount($calls, 'list'));

        // The archive held exactly the 938 files written, under their target paths.
        $this->assertCount(1, $this->files->extracted);
        $this->assertEqualsCanonicalizing(array_keys(self::expected($entries)), $this->files->extracted[0]);
        [$archive] = $this->files->callsOf('decompress');
        $this->assertSame('.modpacks-plus/staging/overrides-'.$this->latestInstall()->id.'-0-1.zip', $archive);
        $this->assertContains('overrides_batched', array_column($this->latestInstall()->log ?? [], 'key'));
    }

    /**
     * @param  array<string, string>  $entries
     * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>} files, dirs, summary of one install
     */
    private function installWith(array $entries, int $minimum): array
    {
        config()->set('modpacks-plus.override_archive_min', $minimum);
        $this->files = new FakeServerFiles();
        $this->app->instance(ServerFiles::class, $this->files);

        [, $server] = $this->scenarioServer();
        $this->scenarioPack($entries);
        $this->files->seed(self::SEED);

        $this->start($server);
        $this->runAll($server);
        $this->assertApplied($entries);

        $install = $this->latestInstall();
        $aside = (string) $install->moved_aside_path;
        $normalise = function (array $map) use ($aside, $install): array {
            $out = [];
            foreach ($map as $path => $value) {
                $path = (string) $path;
                if (str_starts_with($path, '.modpacks-plus/staging/'.$install->id)) {
                    continue;
                }
                // Each install's server has its own allocation (PropertiesStep writes its port).
                if ($path === 'server.properties' && is_string($value)) {
                    $value = (string) preg_replace('/^(server-port|query\.port)=\d+$/m', '$1=PORT', $value);
                }
                $out[str_starts_with($path, $aside) ? 'ASIDE'.substr($path, strlen($aside)) : $path] = $value;
            }
            ksort($out);

            return $out;
        };

        $summary = array_intersect_key($install->summary ?? [], array_flip(['overrides_applied', 'overrides_total', 'skipped_overrides', 'skipped_client_overrides', 'downloaded']));

        return [$normalise($this->files->files), $normalise($this->files->dirs), $summary];
    }

    private function runAll(Server $server): void
    {
        $this->runJobs(between: fn () => $this->finishBackups($server));
    }

    public function test_the_archive_leaves_the_same_server_as_one_write_per_file(): void
    {
        $entries = $this->overrides(60);

        [$perFile, $perFileDirs, $perFileSummary] = $this->installWith($entries, 0);
        $this->assertSame([], $this->files->callsOf('decompress'));
        $perFileWrites = count(array_filter($this->files->callsOf('write'), fn ($path) => isset(self::expected($entries)[$path])));
        $this->assertSame(count(self::expected($entries)), $perFileWrites);

        [$batched, $batchedDirs, $batchedSummary] = $this->installWith($entries, 50);
        $this->assertCount(1, $this->files->callsOf('decompress'));
        $this->assertSame([], array_values(array_filter($this->files->callsOf('write'), fn ($path) => isset(self::expected($entries)[$path]))));

        $this->assertSame($perFile, $batched);
        $this->assertSame($perFileDirs, $batchedDirs);
        $this->assertSame($perFileSummary, $batchedSummary);
        $this->assertEqualsCanonicalizing(['ops.json', 'world/level.dat', 'world_nether/DIM-1/x', 'server.properties', '.modpacks-plus/staging/overrides-1-0-1.zip', '.modpacks-plus/previous-x/mods/a.jar', 'mods/.modrinth-metadata.json'], $batchedSummary['skipped_overrides']);
    }

    /** @return array<string, array{int, int, bool}> override files, config minimum, batched */
    public static function thresholds(): array
    {
        return [
            '49 files' => [49, 50, false],
            '50 files' => [50, 50, true],
            'turned off' => [200, 0, false],
            'lower minimum' => [10, 5, true],
        ];
    }

    #[DataProvider('thresholds')]
    public function test_small_sets_keep_one_write_per_file(int $count, int $minimum, bool $batched): void
    {
        config()->set('modpacks-plus.override_archive_min', $minimum);
        [, $server] = $this->scenarioServer();
        $entries = $this->overrides($count, extras: false);
        $this->scenarioPack($entries);

        $this->start($server);
        $calls = $this->runRecordingOverrides($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_INSTALLED, $install->status, (string) json_encode($install->log));
        foreach (self::expected($entries) as $path => $contents) {
            $this->assertSame($contents, $this->files->files[$path] ?? null, $path);
        }
        $this->assertSame($batched ? 1 : 0, self::callCount($calls, 'decompress'));
        $this->assertSame($batched ? 1 : $count, self::callCount($calls, 'write'));
        $this->assertSame([], $this->stagedArchives());
        $this->assertSame([], $this->files->violations);
    }

    /** @return array<string, array{string, int}> kill point => unpacks expected in total */
    public static function killPoints(): array
    {
        return [
            'after the archive write, before the unpack' => ['before_unpack', 1],
            'after the unpack, before it is recorded' => ['after_unpack', 2],
            'while removing the staged archive' => ['cleanup', 1],
            'while verifying' => ['verify', 1],
        ];
    }

    #[DataProvider('killPoints')]
    public function test_a_worker_killed_mid_batch_resumes_without_duplicates_or_leftovers(string $point, int $unpacks): void
    {
        [, $server] = $this->scenarioServer();
        $entries = $this->overrides(80);
        $this->scenarioPack($entries);
        $this->files->seed(self::SEED);

        $killed = false;
        $kill = function () use (&$killed) {
            if (!$killed) {
                $killed = true;

                throw new RuntimeException('worker killed');
            }
        };
        match ($point) {
            'before_unpack' => $this->files->beforeDecompress = $kill,
            'after_unpack' => $this->files->afterDecompress = $kill,
            'cleanup' => $this->files->beforeDelete = function (string $path) use ($kill) {
                if (str_starts_with($path, '.modpacks-plus/staging/overrides-')) {
                    $kill();
                }
            },
            'verify' => $this->files->beforeList = function (string $directory) use ($kill) {
                if ($this->files->extracted !== [] && str_starts_with($directory, 'config/pack')) {
                    $kill();
                }
            },
            default => $this->fail("unknown kill point $point"),
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
        $this->assertSame('overrides', $install->current_step);
        if ($point !== 'after_unpack') {
            // Before the kill: one staged archive at most, never unpacked twice by then.
            $this->assertLessThanOrEqual(1, count($this->stagedArchives()));
        }

        $this->app->call([new OverridesStep($install->id, $install->seq), 'handle']);
        $this->runAll($server);

        $this->assertApplied($entries);
        $this->assertCount($unpacks, $this->files->extracted);
        // A re-run unpacks a freshly staged archive (never the one a killed job may have left).
        $archives = $this->files->callsOf('decompress');
        $this->assertSame(count($archives), count(array_unique($archives)));
        // The two files in the way were moved aside exactly once.
        $this->assertCount(2, array_filter($this->files->argsOf('rename'), fn (array $args) => in_array($args[0], ['serverconfig/foreign.toml', 'server-icon.png'], true)));
    }

    public function test_a_file_the_node_skips_fails_the_install_and_removes_the_archive(): void
    {
        [, $server] = $this->scenarioServer();
        $entries = $this->overrides(60, extras: false);
        $this->scenarioPack($entries);
        // Wings' egg file denylist: the unpack silently leaves this one out.
        $this->files->ignored = ['config/pack3/file3.json'];

        $this->start($server);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('override_incomplete', $install->error);
        $this->assertArrayNotHasKey('config/pack3/file3.json', $this->files->files);
        $this->assertSame([], $this->stagedArchives());
    }

    public function test_an_unpack_that_keeps_failing_ends_after_three_fresh_archives(): void
    {
        [, $server] = $this->scenarioServer();
        $entries = $this->overrides(60, extras: false);
        $this->scenarioPack($entries);
        $this->files->beforeDecompress = function () {
            throw new RequestException(new Response(new Psr7Response(500)));
        };

        $this->start($server);
        $this->runAll($server);

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status, (string) json_encode($install->log));
        $this->assertSame('override_archive_failed', $install->error);
        $archives = $this->files->callsOf('decompress');
        $this->assertCount(OverridesStep::MAX_APPLY_ATTEMPTS, $archives);
        $this->assertSame($archives, array_values(array_unique($archives)));
        $this->assertSame([], $this->stagedArchives());
        $this->assertContains('override_archive_retry', array_column($install->log ?? [], 'key'));
    }

    public function test_a_tampered_stored_plan_is_refused_before_the_archive_is_built(): void
    {
        [, $server] = $this->scenarioServer();
        $entries = $this->overrides(60, extras: false);
        $this->scenarioPack($entries);
        $this->files->seed(self::SEED);

        $this->start($server);
        $this->runJobs(between: function ($job, PackInstall $install) use ($server) {
            $this->finishBackups($server);
            if ($install->current_step === 'overrides' && is_array($install->plan)) {
                $plan = $install->plan;
                // Same entry, aimed at a player list.
                $plan['overrides'][0]['path'] = 'ops.json';
                PackInstall::query()->whereKey($install->id)->update(['plan' => json_encode($plan)]);
            }
        });

        $install = $this->latestInstall();
        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('unsafe_path', $install->error);
        $this->assertSame([], $this->files->callsOf('decompress'));
        $this->assertSame(self::SEED['ops.json'], $this->files->files['ops.json']);
        $this->assertSame([], $this->stagedArchives());
    }
}
