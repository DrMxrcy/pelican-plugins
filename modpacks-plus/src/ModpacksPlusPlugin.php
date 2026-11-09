<?php

namespace DrMxrcy\ModpacksPlus;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use DrMxrcy\ModpacksPlus\Services\LoaderEggResolver;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;

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

    /** Env key of the default egg for a loader. */
    public static function eggEnvKey(string $loader): string
    {
        return 'MODPACKS_PLUS_EGG_'.strtoupper($loader);
    }

    /** @return array<string, mixed> */
    public function getSettingsFormData(): array
    {
        $data = [
            'allow_skip_backup' => (bool) config('modpacks-plus.allow_skip_backup', false),
            'allow_user_loader_switch' => (bool) config('modpacks-plus.allow_user_loader_switch', false),
        ];

        foreach (EggVariableMap::loaders() as $loader) {
            $egg = config('modpacks-plus.loader_eggs.'.$loader);
            $data['egg_'.$loader] = is_numeric($egg) ? (int) $egg : null;
        }

        return $data;
    }

    public function getSettingsForm(): array
    {
        $resolver = new LoaderEggResolver();
        $eggs = [];
        foreach (EggVariableMap::loaders() as $loader) {
            $eggs[] = Select::make('egg_'.$loader)
                ->label(trans('modpacks-plus::strings.settings.default_egg', ['loader' => LoaderEggResolver::loaderName($loader)]))
                ->options(fn () => $resolver->choices($loader))
                ->placeholder(trans('modpacks-plus::strings.settings.default_egg_auto'));
        }

        return [
            Toggle::make('allow_skip_backup')
                ->label(trans('modpacks-plus::strings.settings.allow_skip_backup'))
                ->helperText(trans('modpacks-plus::strings.settings.allow_skip_backup_help')),
            Toggle::make('allow_user_loader_switch')
                ->label(trans('modpacks-plus::strings.settings.allow_user_loader_switch'))
                ->helperText(trans('modpacks-plus::strings.settings.allow_user_loader_switch_help')),
            Section::make(trans('modpacks-plus::strings.settings.default_eggs'))
                ->description(trans('modpacks-plus::strings.settings.default_eggs_help'))
                ->schema($eggs),
        ];
    }

    public function saveSettings(array $data): void
    {
        $values = [
            'MODPACKS_PLUS_ALLOW_SKIP_BACKUP' => !empty($data['allow_skip_backup']) ? 'true' : 'false',
            'MODPACKS_PLUS_ALLOW_USER_LOADER_SWITCH' => !empty($data['allow_user_loader_switch']) ? 'true' : 'false',
        ];

        $resolver = new LoaderEggResolver();
        foreach (EggVariableMap::loaders() as $loader) {
            $egg = $data['egg_'.$loader] ?? null;
            // Only an egg the resolver would accept for this loader can be stored.
            $values[self::eggEnvKey($loader)] = is_numeric($egg) && isset($resolver->choices($loader)[(int) $egg]) ? (string) (int) $egg : '';
        }

        $this->writeToEnvironment($values);

        Notification::make()
            ->title(trans('modpacks-plus::strings.settings.saved'))
            ->success()
            ->send();
    }
}
