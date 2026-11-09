<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Response;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 33: Modpacks Plus records a pack's mods in `.modrinth-metadata.json` with an extra
 * `installed_as_modpack` key. Modrinth Plus doesn't write that key, but every one of its
 * metadata writes (install, update, uninstall, pin) must carry it, and every other entry,
 * through unchanged.
 */
class ModpackMetadataRoundTripTest extends TestCase
{
    private const PATH = 'mods/.modrinth-metadata.json';

    private const FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    private ?string $file = null;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->andReturnSelf();
        $repository->shouldReceive('getContent')->andReturnUsing(function (string $path) {
            $this->assertSame(self::PATH, $path);

            return $this->file ?? throw new FileNotFoundException('no metadata yet');
        });
        $repository->shouldReceive('putContent')->andReturnUsing(function (string $path, string $content) {
            $this->assertSame(self::PATH, $path);
            $this->file = $content;

            return new Response(new Psr7Response(204));
        });
        $this->app->instance(DaemonFileRepository::class, $repository);
    }

    private function server(): Server
    {
        $server = new Server();
        $server->id = 3;

        return $server;
    }

    private function service(): MinecraftModrinthService
    {
        return app(MinecraftModrinthService::class);
    }

    /** @return array<string, mixed> */
    private static function packTag(): array
    {
        return ['provider' => 'modrinth', 'pack_id' => 'PACKID01', 'version_id' => 'VERSID01', 'pack_name' => 'Cool Pack', 'version_number' => '1.0.0'];
    }

    /** @return array<string, mixed> an entry exactly as Modpacks Plus writes it */
    private static function packEntry(string $id): array
    {
        return [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => ucfirst($id),
            'version_id' => "$id-1",
            'version_number' => '1.0',
            'filename' => "$id.jar",
            'installed_at' => '2026-11-09T12:00:00+00:00',
            'installed_as_modpack' => self::packTag(),
        ];
    }

    /** @return array<string, mixed> */
    private static function original(): array
    {
        return [
            'installed_mods' => [
                self::packEntry('sodium'),
                ['project_id' => 'jei', 'project_slug' => 'jei', 'project_title' => 'JEI', 'version_id' => 'j1', 'version_number' => '1', 'filename' => 'jei.jar', 'installed_at' => '2026-01-01T00:00:00+00:00', 'pinned' => true],
                self::packEntry('lithium'),
            ],
            'written_by' => 'someone else',
        ];
    }

    /** @return array<string, array<string, mixed>> entries by project id */
    private function entries(): array
    {
        return array_column(json_decode((string) $this->file, true, flags: JSON_THROW_ON_ERROR)['installed_mods'], null, 'project_id');
    }

    public function test_pack_entries_are_read_as_installed_with_their_tag(): void
    {
        $this->file = json_encode(self::original(), self::FLAGS);

        $installed = $this->service()->readInstalledModsMetadata($this->server(), ModrinthProjectType::Mod) ?? [];

        $this->assertSame(['sodium', 'jei', 'lithium'], array_column($installed, 'project_id'));
        $this->assertSame('Cool Pack', MinecraftModrinthService::installedAsModpack($installed[0]));
        $this->assertNull(MinecraftModrinthService::installedAsModpack($installed[1]));
    }

    public function test_install_update_pin_and_uninstall_keep_the_tag_and_every_other_entry(): void
    {
        $this->file = json_encode(self::original(), self::FLAGS);
        $server = $this->server();
        $type = ModrinthProjectType::Mod;

        // Install another project.
        $this->assertTrue($this->service()->saveModMetadata($server, $type, 'iris', 'iris', 'Iris', 'i1', '1.0', 'iris.jar'));
        // Update a pack mod on its own.
        $this->assertTrue($this->service()->saveModMetadata($server, $type, 'sodium', 'sodium', 'Sodium', 'sodium-2', '2.0', 'sodium-2.jar'));
        // Pin and unpin a pack mod.
        $this->assertTrue($this->service()->setPinned($server, $type, 'lithium', true));
        $this->assertTrue($this->service()->setPinned($server, $type, 'lithium', false));
        // Uninstall the project installed above.
        $this->assertTrue($this->service()->removeModMetadata($server, $type, 'iris'));

        $entries = $this->entries();
        $this->assertSame(['sodium', 'jei', 'lithium'], array_keys($entries));

        // Fails if: an update drops installed_as_modpack (the badge and warning would vanish).
        $this->assertSame(self::packTag(), $entries['sodium']['installed_as_modpack']);
        $this->assertSame(['sodium-2', '2.0', 'sodium-2.jar'], [$entries['sodium']['version_id'], $entries['sodium']['version_number'], $entries['sodium']['filename']]);
        $this->assertSame('2026-11-09T12:00:00+00:00', $entries['sodium']['installed_at']);

        // The untouched entries are exactly as they were, and so is the top-level key.
        $original = self::original();
        $this->assertSame($original['installed_mods'][1], $entries['jei']);
        $this->assertSame($original['installed_mods'][2], $entries['lithium']);
        $this->assertSame('someone else', json_decode((string) $this->file, true)['written_by']);
    }

    public function test_uninstalling_a_pack_mod_removes_only_that_entry(): void
    {
        $this->file = json_encode(self::original(), self::FLAGS);

        $this->assertTrue($this->service()->removeModMetadata($this->server(), ModrinthProjectType::Mod, 'sodium'));

        $original = self::original();
        $this->assertSame(json_encode(['installed_mods' => [$original['installed_mods'][1], $original['installed_mods'][2]], 'written_by' => 'someone else'], self::FLAGS), $this->file);
    }

    /** @return array<string, array{mixed}> */
    public static function malformedTags(): array
    {
        return [
            'missing' => [null],
            'a string' => ['Cool Pack'],
            'empty object' => [[]],
            'blank name and id' => [['pack_name' => ' ', 'pack_id' => '']],
            'non-string name' => [['pack_name' => 5]],
        ];
    }

    #[DataProvider('malformedTags')]
    public function test_a_malformed_tag_is_not_a_modpack(mixed $tag): void
    {
        $entry = self::packEntry('sodium');
        $entry['installed_as_modpack'] = $tag;

        $this->assertNull(MinecraftModrinthService::installedAsModpack($entry));
    }

    public function test_the_pack_id_stands_in_for_a_missing_name(): void
    {
        $entry = self::packEntry('sodium');
        unset($entry['installed_as_modpack']['pack_name']);

        $this->assertSame('PACKID01', MinecraftModrinthService::installedAsModpack($entry));
    }

    public function test_modrinth_plus_never_depends_on_modpacks_plus(): void
    {
        // Fails if: this plugin needs Modpacks Plus installed; the shared file is the only link.
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $this->assertStringNotContainsString('ModpacksPlus', (string) file_get_contents((string) $file), (string) $file);
        }
        $this->assertStringNotContainsString('modpacks-plus', (string) file_get_contents(dirname(__DIR__, 2).'/plugin.json'));
    }
}
