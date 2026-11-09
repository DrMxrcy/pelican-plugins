<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use App\Enums\SubuserPermission;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Models\User;
use App\Services\Backups\InitiateBackupService;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Jobs\Steps\InstallStep;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;

/**
 * A Fabric 1.20.1 server with a fake daemon, a fake Modrinth and a fake backup service,
 * plus a runner that executes queued install jobs one hop at a time.
 */
trait InstallScenario
{
    protected FakeServerFiles $files;

    protected MockInterface $backups;

    protected int $backupsCreated = 0;

    /** @var array<string, array<string, mixed>> Index entries of the default pack, by role. */
    protected array $pack = [];

    protected function setUpScenario(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();

        $this->files = new FakeServerFiles();
        $this->app->instance(ServerFiles::class, $this->files);

        $this->backupsCreated = 0;
        $this->backups = Mockery::mock(InitiateBackupService::class);
        $this->backups->shouldReceive('setIsLocked')->with(true)->andReturnSelf();
        $this->backups->shouldReceive('handle')->andReturnUsing(function (Server $server, ?string $name = null, bool $override = false) {
            $this->assertFalse($override, 'Backups must never be created with override');
            $this->backupsCreated++;

            return Backup::factory()->create([
                'server_id' => $server->id,
                'backup_host_id' => BackupHost::query()->value('id') ?? BackupHost::factory()->create()->getKey(),
                'name' => $name,
                'is_locked' => true,
                'is_successful' => false,
                'completed_at' => null,
            ]);
        });
        $this->app->instance(InitiateBackupService::class, $this->backups);
    }

    /**
     * @param  array<int, SubuserPermission>  $permissions  empty = the owner
     * @param  array<int, string>  $tags
     * @return array{User, Server}
     */
    protected function scenarioServer(array $permissions = [], array $tags = ['minecraft', 'fabric'], string $minecraft = '1.20.1'): array
    {
        [$user, $server] = $this->generateTestAccount($permissions);

        $egg = $this->cloneEggAndVariables($server->egg);
        $egg->forceFill(['tags' => $tags])->save();
        $server->forceFill(['egg_id' => $egg->id])->save();

        $variable = EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'MINECRAFT_VERSION', 'default_value' => 'latest']);
        ServerVariable::query()->create(['server_id' => $server->id, 'variable_id' => $variable->getKey(), 'variable_value' => $minecraft]);

        return [$user, $server->fresh()];
    }

    /**
     * Fakes Modrinth for a pack and puts its files on the fake "internet" the node pulls from.
     *
     * @param  array<string, string>|null  $extraEntries  archive entries besides the index
     * @param  array<string, string>  $dependencies
     */
    protected function scenarioPack(?array $extraEntries = null, array $dependencies = ['minecraft' => '1.20.1', 'fabric-loader' => '0.15.11']): string
    {
        $this->pack = [
            'sodium' => Mrpack::file('mods/sodium.jar', 'sodium-bytes', 'https://cdn.modrinth.com/data/AANobbMI/versions/a/sodium.jar'),
            'lithium' => Mrpack::file('mods/lithium.jar', 'lithium-bytes', 'https://cdn.modrinth.com/data/gvQqBUqZ/versions/b/lithium.jar'),
            'github' => Mrpack::file('mods/from-github.jar', 'github-bytes', 'https://github.com/o/r/releases/download/v1/from-github.jar'),
            'client' => Mrpack::file('mods/zoomify.jar', 'zoomify-bytes', 'https://cdn.modrinth.com/data/w7ThoJFB/versions/c/zoomify.jar', 'unsupported'),
        ];

        $archive = Mrpack::zip(array_merge(['modrinth.index.json' => Mrpack::index(array_values($this->pack), $dependencies)], $extraEntries ?? [
            'overrides/config/sodium.toml' => 'both',
            'server-overrides/config/sodium.toml' => 'server',
            'overrides/config/extra/deep.json' => '{}',
            'overrides/ops.json' => '[{"name":"attacker"}]',
            'overrides/world/datapacks/x.zip' => 'datapack',
            'client-overrides/options.txt' => 'client',
        ]));

        // A fresh fake: stubs registered by an earlier call would otherwise win.
        Http::swap(new HttpFactory());
        Http::fake([
            'api.modrinth.com/v2/version/'.Mrpack::VERSION_ID => Http::response(Mrpack::version($archive)),
            'api.modrinth.com/v2/version_files' => Http::response(Mrpack::lookup([$this->pack['sodium'], $this->pack['lithium']])),
            Mrpack::ARCHIVE_URL => Http::response($archive),
        ]);

        foreach ($this->pack as $file) {
            $this->files->remote[$file['downloads'][0]] = match ($file['path']) {
                'mods/sodium.jar' => 'sodium-bytes',
                'mods/lithium.jar' => 'lithium-bytes',
                'mods/from-github.jar' => 'github-bytes',
                default => 'zoomify-bytes',
            };
        }

        return $archive;
    }

    /** Marks every backup of the server as finished. */
    protected function finishBackups(Server $server, bool $successful = true): void
    {
        Backup::query()->where('server_id', $server->id)->update(['completed_at' => now(), 'is_successful' => $successful]);
    }

    /** Runs queued install jobs whose seq is current, one at a time, until none is left (or $max). */
    protected function runJobs(int $max = 200, ?\Closure $between = null): void
    {
        $ran = [];
        for ($i = 0; $i < $max; $i++) {
            $install = PackInstall::query()->latest('id')->first();
            if ($install === null || !$install->isActive()) {
                return;
            }

            $job = null;
            foreach (Queue::pushedJobs() as $pushed) {
                foreach ($pushed as $entry) {
                    if ($entry['job'] instanceof InstallStep && $entry['job']->seq === $install->seq && !isset($ran[spl_object_id($entry['job'])])) {
                        $job = $entry['job'];
                    }
                }
            }

            if ($job === null) {
                return;
            }

            $ran[spl_object_id($job)] = true;
            if ($between !== null) {
                $between($job, $install);
            }
            $this->app->call([$job, 'handle']);
        }
    }

    protected function latestInstall(): PackInstall
    {
        /** @var PackInstall $install */
        $install = PackInstall::query()->latest('id')->firstOrFail();

        return $install;
    }
}
