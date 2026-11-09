<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use DrMxrcy\PlayersPlus\PlayersPlusPlugin;
use DrMxrcy\PlayersPlus\Support\Settings;
use DrMxrcy\PlayersPlus\Tests\TestCase;

class SettingsTest extends TestCase
{
    public function test_defaults_come_from_the_plugin_config(): void
    {
        $this->assertTrue(Settings::enabled());
        $this->assertSame(2, Settings::pollInterval());
        $this->assertSame(30, Settings::retentionDays());
        $this->assertSame(365, Settings::rollupRetentionDays());
        $this->assertSame(3.0, Settings::timeout());
    }

    public function test_out_of_range_values_are_clamped(): void
    {
        config()->set('players-plus.poll_interval', 0);
        config()->set('players-plus.retention_days', 99999);
        config()->set('players-plus.timeout', 60);
        $this->assertSame(1, Settings::pollInterval());
        $this->assertSame(Settings::MAX_RETENTION_DAYS, Settings::retentionDays());
        $this->assertSame(10.0, Settings::timeout());

        config()->set('players-plus.poll_interval', '9');
        config()->set('players-plus.retention_days', -4);
        $this->assertSame(5, Settings::pollInterval());
        $this->assertSame(1, Settings::retentionDays());

        config()->set('players-plus.rollup_retention_days', 0);
        $this->assertSame(1, Settings::rollupRetentionDays());
        config()->set('players-plus.rollup_retention_days', 'forever');
        $this->assertSame(365, Settings::rollupRetentionDays());
    }

    public function test_enabled_reads_env_style_strings(): void
    {
        config()->set('players-plus.enabled', 'false');
        $this->assertFalse(Settings::enabled());

        config()->set('players-plus.enabled', '1');
        $this->assertTrue(Settings::enabled());
    }

    public function test_settings_form_data_reflects_config(): void
    {
        config()->set('players-plus.poll_interval', 4);

        $data = (new PlayersPlusPlugin())->getSettingsFormData();

        $this->assertSame(['enabled' => true, 'poll_interval' => 4, 'retention_days' => 30, 'rollup_retention_days' => 365], $data);
        $this->assertCount(4, (new PlayersPlusPlugin())->getSettingsForm());
    }

    public function test_save_writes_prefixed_clamped_environment_values(): void
    {
        $plugin = new class extends PlayersPlusPlugin
        {
            /** @var array<string, mixed> */
            public array $written = [];

            public function writeToEnvironment(array $values = []): void
            {
                $this->written = $values;
            }
        };

        $plugin->saveSettings(['enabled' => false, 'poll_interval' => 12, 'retention_days' => '7', 'rollup_retention_days' => 99999]);

        $this->assertSame([
            'PLAYERS_PLUS_ENABLED' => 'false',
            'PLAYERS_PLUS_POLL_INTERVAL' => 5,
            'PLAYERS_PLUS_RETENTION_DAYS' => 7,
            'PLAYERS_PLUS_ROLLUP_RETENTION_DAYS' => Settings::MAX_RETENTION_DAYS,
        ], $plugin->written);
    }
}
