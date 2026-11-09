<?php

namespace DrMxrcy\ModpacksPlus;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Services\LoaderEggResolver;
use DrMxrcy\ModpacksPlus\Services\PackUpdateChecker;
use DrMxrcy\ModpacksPlus\Services\PropertiesPreserver;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            // Never the saved key: the field stays empty and only says that one is saved.
            'curseforge_api_key' => null,
            'curseforge_api_key_remove' => false,
            'curseforge_mirror' => (bool) config('modpacks-plus.curseforge.mirror', false),
            'update_check' => PackUpdateChecker::frequency(),
            'preserved_properties' => implode(', ', PropertiesPreserver::keys(config('modpacks-plus.preserved_properties'))),
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
            Section::make(trans('modpacks-plus::strings.settings.pack_updates'))
                ->schema([
                    Select::make('update_check')
                        ->label(trans('modpacks-plus::strings.settings.update_check'))
                        ->helperText(trans('modpacks-plus::strings.settings.update_check_help'))
                        ->options(collect(PackUpdateChecker::FREQUENCIES)->mapWithKeys(fn (string $frequency) => [$frequency => trans('modpacks-plus::strings.settings.update_check_'.$frequency)])->all())
                        ->selectablePlaceholder(false)
                        ->required(),
                    Textarea::make('preserved_properties')
                        ->label(trans('modpacks-plus::strings.settings.preserved_properties'))
                        ->helperText(trans('modpacks-plus::strings.settings.preserved_properties_help'))
                        ->rows(3)
                        ->maxLength(2000),
                ]),
            Section::make(trans('modpacks-plus::strings.settings.default_eggs'))
                ->description(trans('modpacks-plus::strings.settings.default_eggs_help'))
                ->schema($eggs),
            Section::make(trans('modpacks-plus::strings.settings.curseforge'))
                ->description(trans('modpacks-plus::strings.settings.curseforge_help'))
                ->schema([
                    TextInput::make('curseforge_api_key')
                        ->label(trans('modpacks-plus::strings.settings.curseforge_api_key'))
                        ->helperText(trans('modpacks-plus::strings.settings.curseforge_api_key_help'))
                        ->password()
                        ->revealable(false)
                        ->autocomplete('new-password')
                        ->maxLength(128)
                        ->regex(CurseForgeClient::KEY_PATTERN)
                        ->placeholder(fn () => self::curseForgeKeySaved() ? trans('modpacks-plus::strings.settings.curseforge_api_key_placeholder') : null)
                        // Whatever the form is filled with, the saved key is never sent to the browser.
                        ->afterStateHydrated(fn (TextInput $component) => $component->state(null)),
                    Toggle::make('curseforge_api_key_remove')
                        ->label(trans('modpacks-plus::strings.settings.curseforge_api_key_remove'))
                        ->visible(fn () => self::curseForgeKeySaved()),
                    Toggle::make('curseforge_mirror')
                        ->label(trans('modpacks-plus::strings.settings.curseforge_mirror'))
                        ->helperText(trans('modpacks-plus::strings.settings.curseforge_mirror_help')),
                ]),
        ];
    }

    /** Whether a CurseForge API key is saved (its value is never read out for the form). */
    public static function curseForgeKeySaved(): bool
    {
        return CurseForgeClient::fromConfig()->mode() === CurseForgeClient::MODE_KEY;
    }

    public function saveSettings(#[\SensitiveParameter] array $data): void
    {
        $values = [
            'MODPACKS_PLUS_ALLOW_SKIP_BACKUP' => !empty($data['allow_skip_backup']) ? 'true' : 'false',
            'MODPACKS_PLUS_ALLOW_USER_LOADER_SWITCH' => !empty($data['allow_user_loader_switch']) ? 'true' : 'false',
            CurseForgeClient::MIRROR_ENV => !empty($data['curseforge_mirror']) ? 'true' : 'false',
        ];

        // Plan 36. Only a known frequency, and only valid keys (letters, digits, . _ -) reach the
        // .env file; a field the form didn't send keeps its saved value.
        if (array_key_exists('update_check', $data)) {
            $values['MODPACKS_PLUS_UPDATE_CHECK'] = is_string($data['update_check']) && in_array($data['update_check'], PackUpdateChecker::FREQUENCIES, true) ? $data['update_check'] : 'off';
        }
        if (array_key_exists('preserved_properties', $data)) {
            $values['MODPACKS_PLUS_PRESERVED_PROPERTIES'] = implode(',', PropertiesPreserver::keys(is_string($data['preserved_properties']) ? $data['preserved_properties'] : ''));
        }

        $resolver = new LoaderEggResolver();
        foreach (EggVariableMap::loaders() as $loader) {
            $egg = $data['egg_'.$loader] ?? null;
            // Only an egg the resolver would accept for this loader can be stored.
            $values[self::eggEnvKey($loader)] = is_numeric($egg) && isset($resolver->choices($loader)[(int) $egg]) ? (string) (int) $egg : '';
        }

        // Blank keeps the saved key; only a well-formed key is written (it is never echoed back).
        $key = is_string($data['curseforge_api_key'] ?? null) ? trim($data['curseforge_api_key']) : '';
        if (!empty($data['curseforge_api_key_remove'])) {
            $values[CurseForgeClient::KEY_ENV] = '';
        } elseif ($key !== '' && CurseForgeClient::isValidKey($key)) {
            $values[CurseForgeClient::KEY_ENV] = $key;
        } elseif ($key !== '') {
            Notification::make()
                ->title(trans('modpacks-plus::strings.settings.curseforge_api_key_invalid'))
                ->danger()
                ->send();
        }
        unset($key);

        $this->writeToEnvironment($values);

        Notification::make()
            ->title(trans('modpacks-plus::strings.settings.saved'))
            ->success()
            ->send();
    }
}
