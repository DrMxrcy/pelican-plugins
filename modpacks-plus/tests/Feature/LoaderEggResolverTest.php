<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Egg;
use App\Models\EggVariable;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\ModpacksPlusPlugin;
use DrMxrcy\ModpacksPlus\Services\LoaderEggResolver;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;

/**
 * Plan 35 step 2: which imported egg a server moves to for a loader (official eggs by
 * uuid / update_url, tags, the admin's default, tie-break, no match), and the settings.
 */
class LoaderEggResolverTest extends TestCase
{
    use RefreshDatabase;

    public const NEOFORGE_UUID = 'e23e092f-b803-4f34-82cf-2d6518c6351a';

    public const NEOFORGE_URL = 'https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/neoforge/egg-neo-forge.json';

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $variables
     */
    public static function makeEgg(array $attributes, array $variables = ['MC_VERSION', 'NEOFORGE_VERSION']): Egg
    {
        /** @var Egg $egg */
        $egg = Egg::factory()->create(array_merge([
            'tags' => ['minecraft'],
            'update_url' => null,
            'docker_images' => ['Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17', 'Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21'],
        ], $attributes));

        foreach ($variables as $variable) {
            EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => $variable, 'default_value' => '', 'rules' => ['nullable', 'string']]);
        }

        return $egg->fresh() ?? $egg;
    }

    private function resolve(string $loader): Egg
    {
        return (new LoaderEggResolver())->resolve($loader);
    }

    private function assertRefused(string $reason, string $loader, ?string $detailContains = null): void
    {
        try {
            $this->resolve($loader);
            $this->fail("expected $reason");
        } catch (InstallRefusedException $exception) {
            $this->assertSame($reason, $exception->reason);
            if ($detailContains !== null) {
                $this->assertStringContainsString($detailContains, $exception->detail);
            }
        }
    }

    public function test_official_egg_is_found_by_uuid_without_a_loader_tag(): void
    {
        $egg = self::makeEgg(['uuid' => self::NEOFORGE_UUID, 'name' => 'NeoForge']);

        $this->assertSame($egg->id, $this->resolve('neoforge')->id);
    }

    public function test_official_egg_is_found_by_update_url_with_a_random_uuid(): void
    {
        $egg = self::makeEgg(['update_url' => self::NEOFORGE_URL, 'tags' => []]);

        $this->assertSame($egg->id, $this->resolve('neoforge')->id);
    }

    public function test_no_matching_egg_refuses_naming_the_missing_egg(): void
    {
        self::makeEgg(['uuid' => '78b02ebb-fec8-49c5-943c-ca4aa117b693', 'name' => 'Fabric'], ['MC_VERSION', 'LOADER_VERSION']);

        $this->assertRefused('egg_missing', 'neoforge', 'NeoForge');
        $this->assertSame(
            'Import the official NeoForge egg first (pelican-eggs/minecraft). Nothing was changed.',
            trans('modpacks-plus::strings.reasons.egg_missing', ['detail' => 'NeoForge']),
        );
    }

    public function test_tagged_egg_the_table_does_not_know_is_refused(): void
    {
        self::makeEgg(['name' => 'My NeoForge', 'tags' => ['minecraft', 'neoforge']]);

        $this->assertRefused('egg_unknown', 'neoforge', 'My NeoForge');
    }

    public function test_egg_recognised_as_one_loader_but_tagged_as_another_is_never_used(): void
    {
        self::makeEgg(['uuid' => self::NEOFORGE_UUID, 'tags' => ['minecraft', 'fabric']]);

        $this->assertRefused('egg_missing', 'neoforge');
    }

    public function test_prefers_the_egg_updated_from_pelican_eggs(): void
    {
        self::makeEgg(['uuid' => self::NEOFORGE_UUID, 'name' => 'Old copy']);
        $official = self::makeEgg(['update_url' => self::NEOFORGE_URL, 'name' => 'NeoForge']);

        $this->assertSame($official->id, $this->resolve('neoforge')->id);
    }

    public function test_two_equally_good_eggs_are_ambiguous_until_an_admin_picks_one(): void
    {
        $first = self::makeEgg(['update_url' => self::NEOFORGE_URL, 'name' => 'NeoForge A']);
        $second = self::makeEgg(['update_url' => str_replace('egg-neo-forge.json', 'egg-pterodactyl-neo-forge.json', self::NEOFORGE_URL), 'name' => 'NeoForge B']);

        $this->assertRefused('egg_ambiguous', 'neoforge', 'NeoForge A #'.$first->id);

        config()->set('modpacks-plus.loader_eggs.neoforge', $second->id);
        $this->assertSame($second->id, $this->resolve('neoforge')->id);
    }

    public function test_the_admin_default_beats_the_update_url_preference(): void
    {
        $byUuid = self::makeEgg(['uuid' => self::NEOFORGE_UUID]);
        self::makeEgg(['update_url' => self::NEOFORGE_URL]);

        config()->set('modpacks-plus.loader_eggs.neoforge', $byUuid->id);

        $this->assertSame($byUuid->id, $this->resolve('neoforge')->id);
    }

    public function test_a_default_that_is_gone_or_another_loaders_is_ignored(): void
    {
        $egg = self::makeEgg(['uuid' => self::NEOFORGE_UUID]);
        $fabric = self::makeEgg(['uuid' => '78b02ebb-fec8-49c5-943c-ca4aa117b693'], ['MC_VERSION', 'LOADER_VERSION']);

        config()->set('modpacks-plus.loader_eggs.neoforge', 999999);
        $gone = $this->resolve('neoforge');
        $this->assertSame($egg->id, $gone->id);

        config()->set('modpacks-plus.loader_eggs.neoforge', $fabric->id);
        $otherLoaders = $this->resolve('neoforge');
        $this->assertSame($egg->id, $otherLoaders->id);
    }

    public function test_a_default_pointing_at_an_unknown_tagged_egg_is_refused(): void
    {
        self::makeEgg(['uuid' => self::NEOFORGE_UUID]);
        $custom = self::makeEgg(['name' => 'Custom NeoForge', 'tags' => ['minecraft', 'neoforge']]);

        config()->set('modpacks-plus.loader_eggs.neoforge', $custom->id);

        $this->assertRefused('egg_unknown', 'neoforge', 'Custom NeoForge');
    }

    public function test_egg_without_the_version_variables_is_refused(): void
    {
        self::makeEgg(['uuid' => self::NEOFORGE_UUID, 'name' => 'Broken NeoForge'], ['MC_VERSION']);

        $this->assertRefused('egg_variables_missing', 'neoforge', 'NEOFORGE_VERSION');
    }

    public function test_unsupported_loader_is_refused(): void
    {
        $this->assertRefused('loader_unsupported', 'paper');
    }

    public function test_resolving_never_changes_an_egg(): void
    {
        self::makeEgg(['uuid' => self::NEOFORGE_UUID]);
        self::makeEgg(['name' => 'Tagged', 'tags' => ['minecraft', 'neoforge']]);
        $before = Egg::query()->orderBy('id')->get()->map(fn (Egg $egg) => $egg->getAttributes())->all();

        try {
            $this->resolve('neoforge');
        } catch (InstallRefusedException) {
        }
        $this->resolve('neoforge');

        $this->assertSame($before, Egg::query()->orderBy('id')->get()->map(fn (Egg $egg) => $egg->getAttributes())->all());
    }

    public function test_server_target_recognises_untagged_official_eggs(): void
    {
        $official = self::makeEgg(['uuid' => self::NEOFORGE_UUID, 'tags' => []]);
        $tagged = self::makeEgg(['tags' => ['minecraft', 'Fabric']], []);
        $conflict = self::makeEgg(['update_url' => self::NEOFORGE_URL, 'tags' => ['minecraft', 'forge']]);
        $other = self::makeEgg(['tags' => ['proxy']], []);

        $this->assertTrue(ServerTarget::isMinecraftEgg($official));
        $this->assertSame('neoforge', ServerTarget::eggLoader($official));
        $this->assertSame('fabric', ServerTarget::eggLoader($tagged));
        $this->assertNull(ServerTarget::eggLoader($conflict));
        $this->assertFalse(ServerTarget::isMinecraftEgg($other));
        $this->assertNull(ServerTarget::eggLoader($other));
    }

    public function test_settings_show_and_store_only_eggs_the_resolver_accepts(): void
    {
        $official = self::makeEgg(['uuid' => self::NEOFORGE_UUID, 'name' => 'NeoForge']);
        $custom = self::makeEgg(['name' => 'Custom', 'tags' => ['minecraft', 'neoforge']]);

        $this->assertSame([$official->id => 'NeoForge #'.$official->id], (new LoaderEggResolver())->choices('neoforge'));

        config()->set('modpacks-plus.loader_eggs.neoforge', $official->id);
        config()->set('modpacks-plus.allow_user_loader_switch', true);
        $data = (new ModpacksPlusPlugin())->getSettingsFormData();
        $this->assertSame($official->id, $data['egg_neoforge']);
        $this->assertNull($data['egg_fabric']);
        $this->assertTrue($data['allow_user_loader_switch']);

        $written = null;
        /** @var ModpacksPlusPlugin&MockInterface $plugin */
        $plugin = Mockery::mock(ModpacksPlusPlugin::class)->makePartial();
        $plugin->shouldReceive('writeToEnvironment')->once()->andReturnUsing(function (array $values) use (&$written) {
            $written = $values;
        });

        $plugin->saveSettings(['allow_skip_backup' => false, 'allow_user_loader_switch' => true, 'egg_neoforge' => $custom->id, 'egg_fabric' => '1 OR 1=1', 'egg_forge' => null, 'egg_quilt' => null]);

        $this->assertSame([
            'MODPACKS_PLUS_ALLOW_SKIP_BACKUP' => 'false',
            'MODPACKS_PLUS_ALLOW_USER_LOADER_SWITCH' => 'true',
            'MODPACKS_PLUS_CURSEFORGE_MIRROR' => 'false',
            'MODPACKS_PLUS_EGG_FABRIC' => '',
            'MODPACKS_PLUS_EGG_FORGE' => '',
            'MODPACKS_PLUS_EGG_NEOFORGE' => '',
            'MODPACKS_PLUS_EGG_QUILT' => '',
        ], $written);

        $plugin->shouldReceive('writeToEnvironment')->once()->andReturnUsing(function (array $values) use (&$written) {
            $written = $values;
        });
        $plugin->saveSettings(['egg_neoforge' => $official->id]);
        $this->assertSame((string) $official->id, $written['MODPACKS_PLUS_EGG_NEOFORGE'] ?? null);
        $this->assertSame('false', $written['MODPACKS_PLUS_ALLOW_USER_LOADER_SWITCH'] ?? null);

        $this->assertCount(4, (new ModpacksPlusPlugin())->getSettingsForm());
    }
}
