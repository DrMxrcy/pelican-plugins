<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Exception;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Response;
use Mockery;

/**
 * Plan 18: pinning stores an optional `"pinned": true` on the project's entry in
 * `.modrinth-metadata.json`. The official plugin ignores the extra key, so the file stays
 * readable by it, and every other entry and key is written back exactly as it was.
 */
class PinMetadataTest extends TestCase
{
    private const PATH = 'mods/.modrinth-metadata.json';

    private const FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    /** Contents of the fake metadata file; null = it doesn't exist. */
    private ?string $file = null;

    /** Thrown by the fake daemon when the metadata file is read. */
    private ?Exception $readError = null;

    /** The fake daemon answers every write with this status. */
    private int $writeStatus = 204;

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->andReturnSelf();
        $repository->shouldReceive('getContent')->andReturnUsing(function (string $path) {
            $this->assertSame(self::PATH, $path);

            if ($this->readError) {
                throw $this->readError;
            }

            return $this->file ?? throw new FileNotFoundException('no metadata yet');
        });
        $repository->shouldReceive('putContent')->andReturnUsing(function (string $path, string $content) {
            $this->assertSame(self::PATH, $path);
            $this->writes[] = $content;

            if ($this->writeStatus < 300) {
                $this->file = $content;
            }

            return new Response(new Psr7Response($this->writeStatus));
        });
        $repository->shouldReceive('deleteFiles')->never();

        $this->app->instance(DaemonFileRepository::class, $repository);
    }

    private function server(): Server
    {
        $server = new Server();
        $server->id = 1;

        return $server;
    }

    private function service(): MinecraftModrinthService
    {
        return app(MinecraftModrinthService::class);
    }

    /** @return array<string, mixed> */
    private static function entry(string $id): array
    {
        return [
            'project_id' => $id,
            'project_slug' => $id,
            'project_title' => ucfirst($id),
            'version_id' => "$id-1",
            'version_number' => '1.0',
            'filename' => "$id.jar",
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ];
    }

    /**
     * Three tracked mods as the official plugin writes them, plus things this plugin doesn't
     * understand: a top-level key, an extra key on an entry and an entry missing required keys.
     *
     * @return array<string, mixed>
     */
    private static function original(): array
    {
        return [
            'installed_mods' => [
                self::entry('sodium'),
                self::entry('lithium'),
                self::entry('iris') + ['installed_as_dependency_of' => 'sodium', 'note' => 'from/another tool'],
                ['project_id' => 'partial', 'filename' => 'partial.jar'],
            ],
            'written_by' => 'minecraft-modrinth',
        ];
    }

    private function seedFile(): void
    {
        $this->file = json_encode(self::original(), self::FLAGS);
    }

    private function pin(string $id, bool $pinned = true): bool
    {
        return $this->service()->setPinned($this->server(), ModrinthProjectType::Mod, $id, $pinned);
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return json_decode((string) $this->file, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_pinning_adds_the_key_and_leaves_everything_else_byte_for_byte(): void
    {
        $this->seedFile();

        $this->assertTrue($this->pin('lithium'));

        $expected = self::original();
        $expected['installed_mods'][1]['pinned'] = true;

        // Fails if: another entry or key is dropped, reordered or re-encoded differently.
        $this->assertCount(1, $this->writes);
        $this->assertSame(json_encode($expected, self::FLAGS), $this->file);
        $this->assertSame(array_keys(self::entry('lithium')), array_slice(array_keys($this->document()['installed_mods'][1]), 0, 7));
    }

    public function test_unpinning_removes_the_key_and_restores_the_original_file(): void
    {
        $this->seedFile();
        $before = $this->file;

        $this->assertTrue($this->pin('iris'));
        $this->assertTrue($this->document()['installed_mods'][2]['pinned']);

        $this->assertTrue($this->pin('iris', false));

        $this->assertArrayNotHasKey('pinned', $this->document()['installed_mods'][2]);
        $this->assertSame($before, $this->file);
    }

    public function test_the_official_plugin_still_reads_a_pinned_entry(): void
    {
        $this->seedFile();
        $this->pin('sodium');

        // Every key the official plugin requires is still there with its old value.
        $this->assertSame(self::entry('sodium'), array_diff_key($this->document()['installed_mods'][0], ['pinned' => true]));
        $this->assertSame(
            ['sodium', 'lithium', 'iris'],
            array_column($this->service()->readInstalledModsMetadata($this->server(), ModrinthProjectType::Mod) ?? [], 'project_id'),
        );
    }

    public function test_pinning_twice_or_unpinning_an_unpinned_project_writes_nothing(): void
    {
        $this->seedFile();
        $this->pin('lithium');
        $this->writes = [];

        $this->assertTrue($this->pin('lithium'));
        $this->assertTrue($this->pin('sodium', false));

        $this->assertSame([], $this->writes);
    }

    public function test_a_project_that_is_not_tracked_cannot_be_pinned(): void
    {
        $this->seedFile();

        $this->assertFalse($this->pin('fabric-api'));
        // An entry missing required keys isn't an installed project either.
        $this->assertFalse($this->pin('partial'));

        $this->file = null;
        $this->assertFalse($this->pin('lithium'));

        $this->assertSame([], $this->writes);
    }

    public function test_pin_and_unpin_write_nothing_when_the_metadata_cannot_be_read(): void
    {
        $this->seedFile();
        $before = $this->file;
        $this->readError = new Exception('cURL error 28: connection timed out');

        $this->assertFalse($this->pin('lithium'));
        $this->assertFalse($this->pin('lithium', false));

        $this->assertSame([], $this->writes);
        $this->assertSame($before, $this->file);
    }

    public function test_an_unparsable_file_is_never_overwritten(): void
    {
        foreach (['{"installed_mods": [', '"just a string"', '{"installed_mods": "nope"}', '[1, 2]'] as $content) {
            $this->file = $content;

            $this->assertFalse($this->pin('lithium'), "Overwrote: $content");
            $this->assertSame($content, $this->file);
        }

        $this->assertSame([], $this->writes);
    }

    public function test_a_refused_write_is_reported_as_a_failure(): void
    {
        $this->seedFile();
        $this->writeStatus = 500;

        $this->assertFalse($this->pin('lithium'));
    }

    public function test_updating_or_reinstalling_the_pinned_project_keeps_the_pin(): void
    {
        $this->seedFile();
        $this->pin('lithium');

        // What "install this version" from the versions modal (or an update) saves.
        $this->assertTrue($this->service()->saveModMetadata($this->server(), ModrinthProjectType::Mod, 'lithium', 'lithium', 'Lithium', 'lithium-0', '0.9', 'lithium-0.9.jar'));

        $entry = $this->document()['installed_mods'][1];
        $this->assertSame('lithium-0', $entry['version_id']);
        $this->assertTrue($entry['pinned']);
    }

    public function test_installing_updating_or_removing_another_project_keeps_the_pin(): void
    {
        $this->seedFile();
        $this->pin('lithium');

        $this->assertTrue($this->service()->saveModMetadata($this->server(), ModrinthProjectType::Mod, 'sodium', 'sodium', 'Sodium', 'sodium-2', '2.0', 'sodium-2.jar'));
        $this->assertTrue($this->service()->saveModMetadata($this->server(), ModrinthProjectType::Mod, 'fabric-api', 'fabric-api', 'Fabric API', 'f-1', '1.0', 'fabric-api.jar'));
        $this->assertTrue($this->service()->removeModMetadata($this->server(), ModrinthProjectType::Mod, 'iris'));

        $mods = array_column($this->document()['installed_mods'], null, 'project_id');
        $this->assertTrue($mods['lithium']['pinned']);
        $this->assertArrayNotHasKey('pinned', $mods['sodium']);
        $this->assertArrayNotHasKey('pinned', $mods['fabric-api']);
    }

    public function test_only_a_literal_true_counts_as_pinned(): void
    {
        $this->assertTrue(MinecraftModrinthService::isPinned(self::entry('a') + ['pinned' => true]));

        foreach ([false, 'true', 1, null, []] as $value) {
            $this->assertFalse(MinecraftModrinthService::isPinned(self::entry('a') + ['pinned' => $value]), var_export($value, true));
        }

        $this->assertFalse(MinecraftModrinthService::isPinned(self::entry('a')));
    }

    public function test_a_pinned_project_never_has_an_update_available(): void
    {
        $versions = [['id' => 'a-2', 'version_number' => '2.0']];

        $this->assertTrue($this->service()->isUpdateAvailable(self::entry('a'), $versions));
        $this->assertFalse($this->service()->isUpdateAvailable(self::entry('a') + ['pinned' => true], $versions));
    }
}
