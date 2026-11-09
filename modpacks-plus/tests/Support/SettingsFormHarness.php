<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use DrMxrcy\ModpacksPlus\ModpacksPlusPlugin;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * Renders the plugin's settings form exactly as the panel's plugin settings action does:
 * the schema from getSettingsForm(), filled with getSettingsFormData().
 *
 * @property-read Schema $form
 */
class SettingsFormHarness extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill((new ModpacksPlusPlugin())->getSettingsFormData());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components((new ModpacksPlusPlugin())->getSettingsForm())->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
