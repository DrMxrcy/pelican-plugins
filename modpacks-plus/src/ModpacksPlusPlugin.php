<?php

namespace DrMxrcy\ModpacksPlus;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;

class ModpacksPlusPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'modpacks-plus';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "DrMxrcy\\ModpacksPlus\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    /** @return array<string, mixed> */
    public function getSettingsFormData(): array
    {
        return [
            'allow_skip_backup' => (bool) config('modpacks-plus.allow_skip_backup', false),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            Toggle::make('allow_skip_backup')
                ->label(trans('modpacks-plus::strings.settings.allow_skip_backup'))
                ->helperText(trans('modpacks-plus::strings.settings.allow_skip_backup_help')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'MODPACKS_PLUS_ALLOW_SKIP_BACKUP' => !empty($data['allow_skip_backup']) ? 'true' : 'false',
        ]);

        Notification::make()
            ->title(trans('modpacks-plus::strings.settings.saved'))
            ->success()
            ->send();
    }
}
