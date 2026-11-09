<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Filament\Server\Components\InstallProgress;
use DrMxrcy\ModpacksPlus\Jobs\Steps\BackupStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Services\PackSizes;
use DrMxrcy\ModpacksPlus\Tests\Support\InstallScenario;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * Plan 38: the whole-pack limit follows the server's disk. Checked while preparing, before
 * the backup and before any file on the server changes; the daemon's usage report is faked.
 */
class DiskBudgetInstallTest extends TestCase
{
    use CreatesTestModels;
    use InstallScenario;
    use RefreshDatabase;

    private const GIB = 1024 * 1024 * 1024;

    /** The largest single file the caps allow; packs are built from these. */
    private const FILE = 256 * 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario();
    }

    /** A Modrinth pack whose index declares $count server mods of 256 MB each (the bodies are never pulled here). */
    private function bigPack(int $count): void
    {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $file = Mrpack::file("mods/big-$i.jar", "big-$i", "https://cdn.modrinth.com/data/AANobbMI/versions/v$i/big-$i.jar");
            $file['fileSize'] = self::FILE;
            $files[] = $file;
        }

        $archive = Mrpack::zip(['modrinth.index.json' => Mrpack::index($files), 'overrides/config/a.toml' => 'a']);

        Http::swap(new HttpFactory());
        Http::fake([
            'api.modrinth.com/v2/version/'.Mrpack::VERSION_ID => Http::response(Mrpack::version($archive)),
            'api.modrinth.com/v2/version_files' => Http::response(Mrpack::lookup($files)),
            Mrpack::ARCHIVE_URL => Http::response($archive),
        ]);
    }

    private function serverWithDisk(int $diskMb): Server
    {
        [, $server] = $this->scenarioServer();
        $server->forceFill(['disk' => $diskMb])->save();
        $this->files->seed([
            'mods/old-mod.jar' => 'old',
            'config/old.toml' => 'old config',
            'world/level.dat' => 'level',
            'server.properties' => "level-name=world\n",
        ]);

        return $server->fresh();
    }

    /** Starts an install and runs only the prepare job. */
    private function prepare(Server $server): PackInstall
    {
        app(InstallEngine::class)->start($server, null, ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID, 'Big Pack', '1.0.0');
        $this->runJobs(1);

        return $this->latestInstall();
    }

    /** @return array<int, string> */
    private function logKeys(PackInstall $install): array
    {
        return array_column($install->log ?? [], 'key');
    }

    /** @param  array<string, string>  $before */
    private function assertNothingTouched(PackInstall $install, array $before): void
    {
        $this->assertSame(0, $this->backupsCreated, 'no backup before the budget check');
        $this->assertSame(0, $this->files->writeCalls(), 'no write before the budget check');
        $this->assertSame($before, $this->files->files, 'no file moved or changed');
        $this->assertNull($install->plan);
    }

    public function test_a_5_gb_pack_fits_a_10_gb_disk_with_3_gb_used(): void
    {
        $server = $this->serverWithDisk(10 * 1024);
        $this->disk->used = 3 * self::GIB;
        $this->bigPack(20); // 5 GB

        $install = $this->prepare($server);

        $this->assertSame(PackInstall::STATUS_INSTALLING, $install->status, (string) $install->error);
        $this->assertSame(BackupStep::KEY, $install->current_step);
        $this->assertNotNull($install->plan);
        $this->assertSame(1, $this->disk->calls);
        $this->assertContains('disk_budget', $this->logKeys($install));
        $this->assertStringContainsString('7 GB free of 10 GB; packs up to 6 GB fit (1 GB kept free)', $this->logText($install, 'disk_budget'));
        $this->assertSame(5 * self::GIB + 1, PackSizes::known(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID));
    }

    public function test_a_7_gb_pack_fails_not_enough_disk_before_any_backup_or_file_change(): void
    {
        $server = $this->serverWithDisk(10 * 1024);
        $this->disk->used = 3 * self::GIB;
        $this->bigPack(28); // 7 GB
        $before = $this->files->files;

        $install = $this->prepare($server);

        $this->assertSame(PackInstall::STATUS_FAILED, $install->status);
        $this->assertSame('not_enough_disk', $install->error);
        $this->assertNothingTouched($install, $before);
        $this->assertStringContainsString('it needs 8 GB, 7 GB free of 10 GB', (string) InstallProgress::error($install));
        $this->assertStringContainsString('8 GB, 7 GB free of 10 GB', (string) InstallProgress::log($install));
        // The page can explain this version won't fit next time.
        $this->assertSame(7 * self::GIB + 1, PackSizes::known(ModrinthPackProvider::ID, Mrpack::PACK_ID, Mrpack::VERSION_ID));
    }

    public function test_the_german_message_names_the_sizes_too(): void
    {
        $server = $this->serverWithDisk(10 * 1024);
        $this->disk->used = 3 * self::GIB;
        $this->bigPack(28);

        $install = $this->prepare($server);
        app()->setLocale('de');

        $this->assertStringContainsString('Es braucht 8 GB, frei sind 7 GB von 10 GB', (string) InstallProgress::error($install));
    }

    public function test_unlimited_disk_gets_the_configured_cap_and_never_asks_the_node(): void
    {
        $server = $this->serverWithDisk(0);
        $this->bigPack(40); // 10 GB: over the old 4 GB cap, under the new 20 GB default

        $install = $this->prepare($server);

        $this->assertSame(BackupStep::KEY, $install->current_step, (string) $install->error);
        $this->assertSame(0, $this->disk->calls);
        $this->assertContains('disk_unlimited', $this->logKeys($install));
        $this->assertStringContainsString('20 GB', $this->logText($install, 'disk_unlimited'));
    }

    public function test_unlimited_disk_still_refuses_a_pack_over_the_configured_cap(): void
    {
        config()->set('modpacks-plus.caps.max_total_bytes', 4 * self::GIB);
        $server = $this->serverWithDisk(0);
        $this->bigPack(20); // 5 GB
        $before = $this->files->files;

        $install = $this->prepare($server);

        $this->assertSame('total_too_large', $install->error);
        $this->assertNothingTouched($install, $before);
    }

    public function test_unknown_usage_falls_back_to_the_configured_cap_and_logs_why(): void
    {
        config()->set('modpacks-plus.caps.max_total_bytes', 4 * self::GIB);
        $server = $this->serverWithDisk(10 * 1024);
        $this->disk->used = null;

        // Fits the cap: installs even though the disk couldn't be checked.
        $this->bigPack(12); // 3 GB
        $install = $this->prepare($server);
        $this->assertSame(BackupStep::KEY, $install->current_step, (string) $install->error);
        $this->assertContains('disk_usage_unknown', $this->logKeys($install));
        $this->assertStringContainsString('did not report', $this->logText($install, 'disk_usage_unknown'));
    }

    public function test_unknown_usage_never_means_unlimited(): void
    {
        config()->set('modpacks-plus.caps.max_total_bytes', 4 * self::GIB);
        $server = $this->serverWithDisk(10 * 1024);
        $this->disk->used = null;
        $this->bigPack(20); // 5 GB, over the cap
        $before = $this->files->files;

        $install = $this->prepare($server);

        $this->assertSame('total_too_large', $install->error);
        $this->assertNothingTouched($install, $before);
    }

    public function test_a_server_already_over_its_limit_installs_nothing(): void
    {
        $server = $this->serverWithDisk(1024);
        $this->disk->used = 2 * self::GIB;
        $this->bigPack(1);
        $before = $this->files->files;

        $install = $this->prepare($server);

        $this->assertSame('not_enough_disk', $install->error);
        $this->assertNothingTouched($install, $before);
    }

    private function logText(PackInstall $install, string $key): string
    {
        foreach ($install->log ?? [] as $line) {
            if ($line['key'] === $key) {
                return trans('modpacks-plus::strings.log.'.$key, array_map(fn ($value) => (string) $value, $line['params']));
            }
        }

        return '';
    }
}
