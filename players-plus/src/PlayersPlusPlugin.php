<?php

namespace DrMxrcy\PlayersPlus;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\ConsoleWidgetPosition;
use App\Filament\Server\Pages\Console;
use App\Traits\EnvironmentWriterTrait;
use DrMxrcy\PlayersPlus\Filament\Server\Widgets\OnlinePlayersWidget;
use DrMxrcy\PlayersPlus\Support\PollHealth;
use DrMxrcy\PlayersPlus\Support\Settings;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;

class PlayersPlusPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'players-plus';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $pages = plugin_path($this->getId(), "src/Filament/$id/Pages");
        if (is_dir($pages)) {
            $panel->discoverPages($pages, "DrMxrcy\\PlayersPlus\\Filament\\$id\\Pages");
        }

        if ($panel->getId() === 'server') {
            Console::registerCustomWidgets(ConsoleWidgetPosition::AboveConsole, [OnlinePlayersWidget::class]);
        }
    }

    public function boot(Panel $panel): void {}

    /** @return array<string, mixed> */
    public function getSettingsFormData(): array
    {
        return [
            'enabled' => Settings::enabled(),
            'poll_interval' => Settings::pollInterval(),
            'retention_days' => Settings::retentionDays(),
            'rollup_retention_days' => Settings::rollupRetentionDays(),
            'moderation' => Settings::moderationEnabled(),
            'log_scan' => filter_var(config('players-plus.log_scan', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            // Plan 34: polls stopped (queue worker or scheduler not running). Shown only then.
            ...array_filter([PollHealth::callout()]),
            Toggle::make('enabled')
                ->label(trans('players-plus::strings.settings.enabled'))
                ->helperText(trans('players-plus::strings.settings.enabled_help')),
            TextInput::make('poll_interval')
                ->label(trans('players-plus::strings.settings.poll_interval'))
                ->helperText(trans('players-plus::strings.settings.poll_interval_help'))
                ->integer()
                ->required()
                ->minValue(Settings::MIN_INTERVAL)
                ->maxValue(Settings::MAX_INTERVAL)
                ->suffix(trans('players-plus::strings.settings.minutes')),
            TextInput::make('retention_days')
                ->label(trans('players-plus::strings.settings.retention_days'))
                ->helperText(trans('players-plus::strings.settings.retention_days_help'))
                ->integer()
                ->required()
                ->minValue(1)
                ->maxValue(Settings::MAX_RETENTION_DAYS)
                ->suffix(trans('players-plus::strings.settings.days')),
            TextInput::make('rollup_retention_days')
                ->label(trans('players-plus::strings.settings.rollup_retention_days'))
                ->helperText(trans('players-plus::strings.settings.rollup_retention_days_help'))
                ->integer()
                ->required()
                ->minValue(1)
                ->maxValue(Settings::MAX_RETENTION_DAYS)
                ->suffix(trans('players-plus::strings.settings.days')),
            Toggle::make('moderation')
                ->label(trans('players-plus::strings.settings.moderation'))
                ->helperText(trans('players-plus::strings.settings.moderation_help')),
            Toggle::make('log_scan')
                ->label(trans('players-plus::strings.settings.log_scan'))
                ->helperText(trans('players-plus::strings.settings.log_scan_help')),
        ];
    }

    /** @param  array<mixed, mixed>  $data */
    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'PLAYERS_PLUS_ENABLED' => filter_var($data['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'PLAYERS_PLUS_POLL_INTERVAL' => Settings::clampInt($data['poll_interval'] ?? null, Settings::MIN_INTERVAL, Settings::MAX_INTERVAL, 2),
            'PLAYERS_PLUS_RETENTION_DAYS' => Settings::clampInt($data['retention_days'] ?? null, 1, Settings::MAX_RETENTION_DAYS, 30),
            'PLAYERS_PLUS_ROLLUP_RETENTION_DAYS' => Settings::clampInt($data['rollup_retention_days'] ?? null, 1, Settings::MAX_RETENTION_DAYS, 365),
            'PLAYERS_PLUS_MODERATION' => filter_var($data['moderation'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'PLAYERS_PLUS_LOG_SCAN' => filter_var($data['log_scan'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
        ]);

        Notification::make()
            ->title(trans('players-plus::strings.settings.saved'))
            ->success()
            ->send();
    }
}
