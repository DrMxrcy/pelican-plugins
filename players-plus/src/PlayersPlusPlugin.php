<?php

namespace DrMxrcy\PlayersPlus;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
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
    }

    public function boot(Panel $panel): void {}

    /** @return array<string, mixed> */
    public function getSettingsFormData(): array
    {
        return [
            'enabled' => Settings::enabled(),
            'poll_interval' => Settings::pollInterval(),
            'retention_days' => Settings::retentionDays(),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
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
        ];
    }

    /** @param  array<mixed, mixed>  $data */
    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'PLAYERS_PLUS_ENABLED' => filter_var($data['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'PLAYERS_PLUS_POLL_INTERVAL' => Settings::clampInt($data['poll_interval'] ?? null, Settings::MIN_INTERVAL, Settings::MAX_INTERVAL, 2),
            'PLAYERS_PLUS_RETENTION_DAYS' => Settings::clampInt($data['retention_days'] ?? null, 1, Settings::MAX_RETENTION_DAYS, 30),
        ]);

        Notification::make()
            ->title(trans('players-plus::strings.settings.saved'))
            ->success()
            ->send();
    }
}
