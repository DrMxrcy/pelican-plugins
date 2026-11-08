<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Models\Egg;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use DrMxrcy\ModrinthPlus\Enums\ModrinthProjectType;
use DrMxrcy\ModrinthPlus\Exceptions\InvalidLevelNameException;
use DrMxrcy\ModrinthPlus\Exceptions\ServerPropertiesUnreadableException;
use DrMxrcy\ModrinthPlus\Services\MinecraftModrinthService;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Exception;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Response;
use Mockery;

/**
 * Plan 12: datapacks live in `<level-name>/datapacks`; mods and plugins keep their fixed folders
 * and never read server.properties.
 */
class DatapackFolderTest extends TestCase
{
    /** @var array<int, string> every path getContent() was asked for */
    private array $reads = [];

    /** @var array<int, string> every path putContent() wrote */
    private array $writes = [];

    /** What server.properties holds; null = missing, Exception = daemon error. */
    private string|Exception|null $properties = null;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->andReturnSelf();
        $repository->shouldReceive('getContent')->andReturnUsing(function (string $path) {
            $this->reads[] = $path;

            if ($path === 'server.properties') {
                if ($this->properties instanceof Exception) {
                    throw $this->properties;
                }

                if ($this->properties === null) {
                    throw new FileNotFoundException();
                }

                return $this->properties;
            }

            return json_encode(['installed_mods' => []], JSON_THROW_ON_ERROR);
        });
        $repository->shouldReceive('putContent')->andReturnUsing(function (string $path) {
            $this->writes[] = $path;

            return new Response(new Psr7Response(204));
        });

        $this->app->instance(DaemonFileRepository::class, $repository);
    }

    /** @param  array<int, string>  $features */
    private function server(array $features = ['modrinth_mods', 'modrinth_plugins', 'modrinth_datapacks']): Server
    {
        $server = new Server();
        $server->id = 1;
        $server->setRelation('egg', (new Egg())->forceFill(['features' => $features, 'tags' => ['minecraft', 'fabric']]));

        return $server;
    }

    private function service(): MinecraftModrinthService
    {
        return app(MinecraftModrinthService::class);
    }

    public function test_mod_and_plugin_folders_are_unchanged_and_never_read_server_properties(): void
    {
        $this->properties = "level-name=survival\n";

        $this->assertSame('mods', $this->service()->getProjectFolder($this->server(), ModrinthProjectType::Mod));
        $this->assertSame('plugins', $this->service()->getProjectFolder($this->server(), ModrinthProjectType::Plugin));

        $this->service()->getInstalledModsMetadata($this->server(), ModrinthProjectType::Mod);
        $this->service()->saveModMetadata($this->server(), ModrinthProjectType::Plugin, 'p', 'p', 'P', 'v', '1', 'p.jar');

        $this->assertNotContains('server.properties', $this->reads);
        $this->assertSame(['mods/.modrinth-metadata.json', 'plugins/.modrinth-metadata.json'], $this->reads);
        $this->assertSame(['plugins/.modrinth-metadata.json'], $this->writes);
    }

    public function test_datapack_folder_follows_level_name(): void
    {
        $this->properties = "motd=hi\nlevel-name=survival\n";

        $this->assertSame('survival/datapacks', $this->service()->getProjectFolder($this->server(), ModrinthProjectType::Datapack));
    }

    public function test_datapack_folder_defaults_to_world_without_server_properties(): void
    {
        $this->assertSame('world/datapacks', $this->service()->getProjectFolder($this->server(), ModrinthProjectType::Datapack));
    }

    public function test_datapack_folder_defaults_to_world_without_a_level_name(): void
    {
        foreach (["motd=hi\n", "level-name=\n"] as $properties) {
            $this->properties = $properties;

            $this->assertSame('world/datapacks', $this->service()->getProjectFolder($this->server(), ModrinthProjectType::Datapack), $properties);
        }
    }

    public function test_unreadable_server_properties_never_guesses_the_world(): void
    {
        $this->properties = new Exception('daemon unreachable');

        // Fails if: a daemon error silently falls back to "world".
        try {
            $this->service()->getProjectFolder($this->server(), ModrinthProjectType::Datapack);
            $this->fail('The world folder was guessed although server.properties could not be read.');
        } catch (ServerPropertiesUnreadableException) {
        }

        // Writers refuse (no metadata written anywhere), readers report a failed read.
        $this->assertFalse($this->service()->saveModMetadata($this->server(), ModrinthProjectType::Datapack, 'terralith', 'terralith', 'Terralith', 'v1', '2.5.8', 'Terralith.zip'));
        $this->assertFalse($this->service()->removeModMetadata($this->server(), ModrinthProjectType::Datapack, 'terralith'));
        $this->assertNull($this->service()->readInstalledModsMetadata($this->server(), ModrinthProjectType::Datapack));
        $this->assertSame([], $this->writes);
        $this->assertSame(['server.properties'], array_values(array_unique($this->reads)));
    }

    public function test_unsafe_level_name_never_guesses_the_world(): void
    {
        foreach (["level-name=../../etc\n", "level-name=.\n", "level-name=/srv/world\n", "level-name=world \n"] as $properties) {
            $this->properties = $properties;

            // Fails if: an unsafe level-name falls back to "world" and datapacks land there.
            try {
                $folder = $this->service()->getProjectFolder($this->server(), ModrinthProjectType::Datapack);
                $this->fail("The world folder was guessed as [$folder] for $properties");
            } catch (InvalidLevelNameException) {
            }

            $this->assertFalse($this->service()->saveModMetadata($this->server(), ModrinthProjectType::Datapack, 'terralith', 'terralith', 'Terralith', 'v1', '2.5.8', 'Terralith.zip'));
            $this->assertNull($this->service()->readInstalledModsMetadata($this->server(), ModrinthProjectType::Datapack));
        }

        $this->assertSame([], $this->writes);
        $this->assertSame(['server.properties'], array_values(array_unique($this->reads)));
    }

    public function test_datapack_metadata_lives_in_the_datapacks_folder(): void
    {
        $this->properties = "level-name=survival\n";

        $this->service()->saveModMetadata($this->server(), ModrinthProjectType::Datapack, 'terralith', 'terralith', 'Terralith', 'v1', '2.5.8', 'Terralith_1.21_v2.5.8.zip');

        $this->assertContains('survival/datapacks/.modrinth-metadata.json', $this->reads);
        $this->assertSame(['survival/datapacks/.modrinth-metadata.json'], $this->writes);
    }

    public function test_datapack_type_needs_the_egg_feature(): void
    {
        $this->assertContains(ModrinthProjectType::Datapack, ModrinthProjectType::fromServer($this->server()));
        $this->assertNotContains(ModrinthProjectType::Datapack, ModrinthProjectType::fromServer($this->server(['modrinth_mods', 'modrinth_plugins'])));
        $this->assertSame([ModrinthProjectType::Plugin, ModrinthProjectType::Mod], ModrinthProjectType::fromServer($this->server(['modrinth_mods', 'modrinth_plugins'])));
    }
}
