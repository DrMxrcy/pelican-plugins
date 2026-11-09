<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use DrMxrcy\PlayersPlus\PlayersPlusPlugin;
use DrMxrcy\PlayersPlus\Tests\TestCase;

class PluginResourcesTest extends TestCase
{
    public function test_plugin_config_is_registered(): void
    {
        $this->assertTrue(config()->has('players-plus'));
        $this->assertSame(require dirname(__DIR__, 2) . '/config/players-plus.php', config('players-plus'));
    }

    public function test_plugin_translations_resolve(): void
    {
        $this->assertSame('Players', trans('players-plus::strings.navigation'));
    }

    public function test_plugin_id_matches_manifest(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugin.json'), true);

        $this->assertSame($manifest['id'], (new PlayersPlusPlugin())->getId());
        $this->assertSame('DrMxrcy\\PlayersPlus', $manifest['namespace']);
    }

    /**
     * Player Counter and Server Analytics live in the same panel: every name this plugin adds
     * must carry its own prefix so nothing collides.
     */
    public function test_everything_is_namespaced(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (array_keys(require $root . '/config/players-plus.php') as $key) {
            $this->assertIsString($key);
        }

        $config = (string) file_get_contents($root . '/config/players-plus.php');
        preg_match_all("/env\('([A-Z_]+)'/", $config, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $env) {
            $this->assertStringStartsWith('PLAYERS_PLUS_', $env);
        }

        foreach (glob($root . '/database/migrations/*.php') ?: [] as $migration) {
            preg_match_all("/Schema::(?:create|table|dropIfExists)\('([a-z_]+)'/", (string) file_get_contents($migration), $tables);
            foreach ($tables[1] as $table) {
                $this->assertStringStartsWith('pp_', $table, basename($migration));
            }
        }
    }
}
