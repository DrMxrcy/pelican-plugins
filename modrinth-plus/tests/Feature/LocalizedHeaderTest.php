<?php

namespace DrMxrcy\ModrinthPlus\Tests\Feature;

use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModrinthPlus\Facades\MinecraftModrinth;
use DrMxrcy\ModrinthPlus\Filament\Server\Pages\MinecraftModrinthModPage;
use DrMxrcy\ModrinthPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/** The page header (Minecraft version, loader, installed count) follows the panel language. */
class LocalizedHeaderTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('server');

        [$user, $server] = $this->generateTestAccount();
        $server->egg->forceFill(['features' => ['modrinth_mods']])->save();

        $this->actingAs($user);
        Filament::setTenant($server);

        MinecraftModrinth::shouldReceive('getMinecraftVersion')->andReturn('26.3');
        MinecraftModrinth::shouldReceive('getLoaderFromServer')->andReturn(null);
        MinecraftModrinth::shouldReceive('listFolder')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsMetadata')->andReturn([]);
        MinecraftModrinth::shouldReceive('getInstalledModsFromModrinth')->andReturn([]);
        MinecraftModrinth::shouldReceive('getCategories')->andReturn([]);
        MinecraftModrinth::shouldReceive('getProjects')->andReturn(['hits' => [], 'total_hits' => 0, 'versions' => []]);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_header_labels_are_german_in_german(): void
    {
        app()->setLocale('de');

        Livewire::test(MinecraftModrinthModPage::class)
            ->assertSee('Minecraft-Version')
            ->assertSee('Installierte Minecraft Mods')
            ->assertDontSee('Minecraft Version');
    }

    public function test_header_labels_are_english_in_english(): void
    {
        Livewire::test(MinecraftModrinthModPage::class)
            ->assertSee('Minecraft Version')
            ->assertSee('Loader')
            ->assertSee('Installed Minecraft Mods');
    }
}
