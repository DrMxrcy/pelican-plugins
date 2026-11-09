<?php

namespace DrMxrcy\PlayersPlus\Tests;

use App\Tests\TestCase as PanelTestCase;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

/**
 * Laravel-booted base for players-plus tests.
 *
 * The panel skips plugin loading while running tests, so this registers the
 * plugin's config (config('players-plus.*')), translation namespace
 * (trans('players-plus::...')), views and migrations the same way PluginService::loadPlugins()
 * does. Add RefreshDatabase in a subclass for tests that need the panel DB/factories.
 * Pure unit tests should extend PHPUnit\Framework\TestCase instead.
 */
abstract class TestCase extends PanelTestCase
{
    public const PLUGIN_ID = 'players-plus';

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        // Registered before RefreshDatabase runs migrate:fresh, so pp_* tables exist in tests.
        $app->afterResolving('migrator', function ($migrator) {
            $migrator->path(dirname(__DIR__) . '/database/migrations');
        });

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__);

        $config = $root . '/config/' . self::PLUGIN_ID . '.php';
        if (file_exists($config)) {
            config()->set(self::PLUGIN_ID, require $config);
        }

        $this->app->make('translator')->addNamespace(self::PLUGIN_ID, $root . '/lang');
        $this->app->make('view')->addNamespace(self::PLUGIN_ID, $root . '/resources/views');
    }

    /**
     * Registers routes for plugin pages on the server panel, the way Filament does for a
     * loaded plugin, so Page::getUrl() works in tests (the panel skips plugins in tests).
     *
     * @param  list<class-string<Page>>  $pages
     */
    protected function registerServerPageRoutes(array $pages): void
    {
        $panel = Filament::getPanel('server');
        $prefix = $panel->getTenantRoutePrefix();
        $attribute = $panel->getTenantSlugAttribute();
        $tenant = (filled($prefix) ? "$prefix/" : '') . '{tenant' . (filled($attribute) ? ":$attribute" : '') . '}';

        Route::name('filament.server.')->prefix($panel->getPath())->group(function () use ($pages, $panel, $tenant) {
            Route::prefix($tenant)->group(function () use ($pages, $panel) {
                foreach ($pages as $page) {
                    $page::registerRoutes($panel);
                }
            });
        });
        Route::getRoutes()->refreshNameLookups();
    }
}
