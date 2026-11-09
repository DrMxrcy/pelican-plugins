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

    public function test_log_scan_defaults_on_and_needs_polling(): void
    {
        $this->assertTrue(Settings::logScan());

        config()->set('players-plus.log_scan', 'false');
        $this->assertFalse(Settings::logScan());

        config()->set('players-plus.log_scan', 'junk');
        $this->assertTrue(Settings::logScan());

        config()->set('players-plus.enabled', false);
        $this->assertFalse(Settings::logScan());
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

        $this->assertSame(['enabled' => true, 'poll_interval' => 4, 'retention_days' => 30, 'rollup_retention_days' => 365, 'moderation' => true, 'log_scan' => true], $data);
        $this->assertCount(6, (new PlayersPlusPlugin())->getSettingsForm());
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

        $plugin->saveSettings(['enabled' => false, 'poll_interval' => 12, 'retention_days' => '7', 'rollup_retention_days' => 99999, 'moderation' => false, 'log_scan' => '0']);

        $this->assertSame([
            'PLAYERS_PLUS_ENABLED' => 'false',
            'PLAYERS_PLUS_POLL_INTERVAL' => 5,
            'PLAYERS_PLUS_RETENTION_DAYS' => 7,
            'PLAYERS_PLUS_ROLLUP_RETENTION_DAYS' => Settings::MAX_RETENTION_DAYS,
            'PLAYERS_PLUS_MODERATION' => 'false',
            'PLAYERS_PLUS_LOG_SCAN' => 'false',
        ], $plugin->written);

        $plugin->saveSettings([]);
        $this->assertSame('true', $plugin->written['PLAYERS_PLUS_MODERATION']);
    }

    public function test_moderation_is_on_by_default_and_fails_closed(): void
    {
        $this->assertTrue(Settings::moderationEnabled());

        foreach (['true', '1', 'on', true] as $on) {
            config()->set('players-plus.moderation', $on);
            $this->assertTrue(Settings::moderationEnabled());
        }
        foreach (['false', '0', 'off', false, '', null, 'garbage', ['x']] as $off) {
            config()->set('players-plus.moderation', $off);
            $this->assertFalse(Settings::moderationEnabled());
        }
    }
}
