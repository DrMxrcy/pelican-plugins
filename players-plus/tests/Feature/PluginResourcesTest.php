<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use DrMxrcy\PlayersPlus\Console\Commands\PollPlayersCommand;
use DrMxrcy\PlayersPlus\Console\Commands\PruneSamplesCommand;
use DrMxrcy\PlayersPlus\Console\Commands\RollupCommand;
use DrMxrcy\PlayersPlus\Filament\Server\Pages\PlayersPage;
use DrMxrcy\PlayersPlus\Models\HourlyRollup;
use DrMxrcy\PlayersPlus\Models\Player;
use DrMxrcy\PlayersPlus\Models\PlayerSession;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\PlayersPlusPlugin;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PluginResourcesTest extends TestCase
{
    public function test_plugin_config_is_registered_with_its_defaults(): void
    {
        $this->assertTrue(config('players-plus.enabled'));
        $this->assertSame(2, config('players-plus.poll_interval'));
        $this->assertSame(30, config('players-plus.retention_days'));
        $this->assertSame(365, config('players-plus.rollup_retention_days'));
        $this->assertSame(3, config('players-plus.timeout'));
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
     * MySQL/MariaDB can give the first non-null `timestamp` column an implicit
     * ON UPDATE CURRENT_TIMESTAMP, silently rewriting poll and session times on any update.
     */
    public function test_migrations_use_datetime_not_timestamp_columns(): void
    {
        $migrations = glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [];
        $this->assertNotEmpty($migrations);
        foreach ($migrations as $migration) {
            $this->assertDoesNotMatchRegularExpression('/->(timestamp|timestamps|timestampTz|timestampsTz|nullableTimestamps)\(/', (string) file_get_contents($migration), basename($migration));
        }
    }

    /**
     * Player Counter and Server Analytics live in the same panel: every name this plugin adds
     * (env, tables, translations, routes, commands, cache keys) must carry its own prefix.
     */
    public function test_everything_is_namespaced(): void
    {
        $root = dirname(__DIR__, 2);

        preg_match_all("/env\\('([A-Z_]+)'/", (string) file_get_contents($root . '/config/players-plus.php'), $env);
        $this->assertNotEmpty($env[1]);
        foreach ($env[1] as $name) {
            $this->assertStringStartsWith('PLAYERS_PLUS_', $name);
        }

        $tables = [];
        foreach (glob($root . '/database/migrations/*.php') ?: [] as $migration) {
            preg_match_all("/Schema::(?:create|table|dropIfExists)\\('([a-z_]+)'/", (string) file_get_contents($migration), $found);
            array_push($tables, ...$found[1]);
        }
        $this->assertNotEmpty($tables);
        foreach ($tables as $table) {
            $this->assertStringStartsWith('pp_', $table);
        }
        $this->assertSame('pp_samples', (new Sample())->getTable());
        $this->assertSame('pp_players', (new Player())->getTable());
        $this->assertSame('pp_sessions', (new PlayerSession())->getTable());
        $this->assertContains('pp_players', $tables);
        $this->assertContains('pp_sessions', $tables);
        $this->assertSame('pp_hourly', (new HourlyRollup())->getTable());
        $this->assertContains('pp_hourly', $tables);

        $this->assertStringStartsWith('players-plus', (string) PlayersPage::getSlug());
        $this->assertStringStartsWith('players-plus:', (new PollPlayersCommand())->getName());
        $this->assertStringStartsWith('players-plus:', (new PruneSamplesCommand())->getName());
        $this->assertStringStartsWith('players-plus:', (new RollupCommand())->getName());

        $translations = 0;
        $cacheKeys = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $source = (string) file_get_contents((string) $file);
            $rel = substr((string) $file, strlen($root) + 1);

            preg_match_all("/trans\\(\\s*'([^']+)'/", $source, $keys);
            foreach ($keys[1] as $key) {
                $translations++;
                $this->assertStringStartsWith('players-plus::', $key, $rel);
            }

            // Cache keys are dotted, interpolated literals such as "players-plus.query-port.$id".
            preg_match_all('/"([a-z][a-z-]*\\.[a-z.-]*)\\$/', $source, $keys);
            foreach ($keys[1] as $key) {
                $cacheKeys++;
                $this->assertStringStartsWith('players-plus.', $key, $rel);
            }
        }
        $this->assertGreaterThan(10, $translations);
        $this->assertGreaterThanOrEqual(3, $cacheKeys);
    }
}
