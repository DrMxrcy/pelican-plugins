<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 35 step 1: which eggs are the official pelican-eggs/minecraft loader eggs, and
 * which of their variables take the Minecraft and loader versions.
 */
class EggVariableMapTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function officialUuids(): array
    {
        return [
            'fabric' => ['78b02ebb-fec8-49c5-943c-ca4aa117b693', 'fabric'],
            'forge' => ['ed072427-f209-4603-875c-f540c6dd5a65', 'forge'],
            'forge enhanced (pre 2026-01)' => ['d6018085-eecc-42bf-bf8c-51ea45a69ace', 'forge'],
            'neoforge' => ['e23e092f-b803-4f34-82cf-2d6518c6351a', 'neoforge'],
            'quilt' => ['dff33655-6e6a-4430-accf-e5aea04c2912', 'quilt'],
        ];
    }

    #[DataProvider('officialUuids')]
    public function test_official_eggs_are_known_by_uuid(string $uuid, string $loader): void
    {
        $this->assertSame($loader, EggVariableMap::loaderFor($uuid, null));
        $this->assertSame($loader, EggVariableMap::loaderFor(strtoupper($uuid), null));
    }

    /** @return array<string, array{string, string}> */
    public static function officialUrls(): array
    {
        $base = 'https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/';

        return [
            'fabric yaml' => [$base.'fabric/egg-fabric.yaml', 'fabric'],
            'fabric json' => [$base.'fabric/egg-fabric.json', 'fabric'],
            'fabric ptdl' => [$base.'fabric/egg-pterodactyl-fabric.json', 'fabric'],
            'forge yaml' => [$base.'forge/egg-forge-minecraft.yaml', 'forge'],
            'forge ptdl' => [$base.'forge/pterodactyl-egg-forge-minecraft.json', 'forge'],
            'neoforge json' => [$base.'neoforge/egg-neo-forge.json', 'neoforge'],
            'neoforge ptdl' => [$base.'neoforge/egg-pterodactyl-neo-forge.json', 'neoforge'],
            'quilt yaml' => [$base.'quilt/egg-quilt.yaml', 'quilt'],
            'quilt ptdl' => [$base.'quilt/egg-pterodactyl-quilt.json', 'quilt'],
            'short main ref' => ['https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/neoforge/egg-neo-forge.json', 'neoforge'],
        ];
    }

    #[DataProvider('officialUrls')]
    public function test_official_eggs_are_known_by_update_url(string $url, string $loader): void
    {
        $this->assertSame($loader, EggVariableMap::loaderFor('00000000-0000-4000-8000-000000000000', $url));
        $this->assertTrue(EggVariableMap::isOfficialUpdateUrl($url));
    }

    /** @return array<string, array{?string}> */
    public static function foreignUrls(): array
    {
        return [
            'null' => [null],
            'other repo' => ['https://raw.githubusercontent.com/someone/minecraft/refs/heads/main/java/fabric/egg-fabric.yaml'],
            'other branch' => ['https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/dev/java/fabric/egg-fabric.yaml'],
            'http' => ['http://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/fabric/egg-fabric.yaml'],
            'other host' => ['https://evil.example/pelican-eggs/minecraft/refs/heads/main/java/fabric/egg-fabric.yaml'],
            'sponge forge' => ['https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/spongeforge/egg-sponge-forge.json'],
            'curseforge' => ['https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/curseforge/egg-curse-forge-generic.yaml'],
            'query' => ['https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/fabric/egg-fabric.yaml?x=1'],
            'traversal' => ['https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/fabric/../forge/egg-forge-minecraft.yaml'],
        ];
    }

    #[DataProvider('foreignUrls')]
    public function test_other_eggs_are_unknown(?string $url): void
    {
        $this->assertNull(EggVariableMap::loaderFor('00000000-0000-4000-8000-000000000000', $url));
        $this->assertNull(EggVariableMap::loaderFor(null, $url));
    }

    public function test_uuid_and_url_naming_different_loaders_is_unknown(): void
    {
        $this->assertNull(EggVariableMap::loaderFor(
            '78b02ebb-fec8-49c5-943c-ca4aa117b693',
            'https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/forge/egg-forge-minecraft.yaml',
        ));
    }

    /** @return array<string, array{string, string, ?string, array<string, string>}> */
    public static function variableCases(): array
    {
        return [
            'fabric' => ['fabric', '1.20.1', '0.15.11', ['MC_VERSION' => '1.20.1', 'LOADER_VERSION' => '0.15.11']],
            'forge (Modrinth gives the forge part only)' => ['forge', '1.20.1', '47.2.0', ['MC_VERSION' => '1.20.1', 'FORGE_VERSION' => '1.20.1-47.2.0']],
            'forge (already full)' => ['forge', '1.20.1', '1.20.1-47.2.0', ['MC_VERSION' => '1.20.1', 'FORGE_VERSION' => '1.20.1-47.2.0']],
            'neoforge' => ['neoforge', '1.21.1', '21.1.77', ['MC_VERSION' => '1.21.1', 'NEOFORGE_VERSION' => '21.1.77']],
            'neoforge 1.20.1 lives in the forge repo' => ['neoforge', '1.20.1', '47.1.106', ['MC_VERSION' => '1.20.1', 'NEOFORGE_VERSION' => '1.20.1-47.1.106']],
            'neoforge 1.20.1 already full' => ['neoforge', '1.20.1', '1.20.1-47.1.106', ['MC_VERSION' => '1.20.1', 'NEOFORGE_VERSION' => '1.20.1-47.1.106']],
            'quilt (egg has no loader variable)' => ['quilt', '1.20.1', '0.26.0', ['MC_VERSION' => '1.20.1']],
        ];
    }

    /** @param  array<string, string>  $expected */
    #[DataProvider('variableCases')]
    public function test_versions_map_onto_each_eggs_variables(string $loader, string $minecraft, ?string $loaderVersion, array $expected): void
    {
        $this->assertSame($expected, EggVariableMap::variables($loader, $minecraft, $loaderVersion));
    }

    public function test_every_supported_loader_has_an_entry(): void
    {
        foreach (['fabric', 'forge', 'neoforge', 'quilt'] as $loader) {
            $this->assertContains('MC_VERSION', EggVariableMap::requiredVariables($loader), $loader);
        }
        $this->assertSame(['MC_VERSION', 'LOADER_VERSION'], EggVariableMap::requiredVariables('fabric'));
        $this->assertSame(['MC_VERSION', 'FORGE_VERSION'], EggVariableMap::requiredVariables('forge'));
        $this->assertSame(['MC_VERSION', 'NEOFORGE_VERSION'], EggVariableMap::requiredVariables('neoforge'));
        $this->assertSame(['MC_VERSION'], EggVariableMap::requiredVariables('quilt'));
        $this->assertFalse(EggVariableMap::pinsLoaderVersion('quilt'));
        $this->assertTrue(EggVariableMap::pinsLoaderVersion('neoforge'));
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function refusedValues(): array
    {
        return [
            'unknown loader' => ['paper', '1.20.1', '1'],
            'missing loader version' => ['fabric', '1.20.1', null],
            'empty loader version' => ['neoforge', '1.21.1', ''],
            'latest minecraft' => ['fabric', 'latest', '0.15.11'],
            'shell characters' => ['fabric', '1.20.1', '0.15.11;rm -rf /'],
            'space' => ['forge', '1.20.1', '47.2.0 x'],
            'forge letters' => ['forge', '1.20.1', '47.2.0-beta'],
            'neoforge letters' => ['neoforge', '1.21.1', '21.1.0-beta'],
            'minecraft too long for the forge egg' => ['forge', '1.20.1.1234', '47.2.0'],
            'blank minecraft' => ['quilt', '', null],
        ];
    }

    #[DataProvider('refusedValues')]
    public function test_values_the_egg_would_reject_are_refused(string $loader, string $minecraft, ?string $loaderVersion): void
    {
        $this->expectException(InstallRefusedException::class);

        EggVariableMap::variables($loader, $minecraft, $loaderVersion);
    }
}
