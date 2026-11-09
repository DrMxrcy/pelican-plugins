<?php

namespace DrMxrcy\ModpacksPlus\Tests;

use App\Tests\TestCase as PanelTestCase;
use Illuminate\Foundation\Application;

/**
 * Laravel-booted base for modpacks-plus tests.
 *
 * The panel skips plugin loading while running tests, so this registers the
 * plugin's config (config('modpacks-plus.*')), translation namespace
 * (trans('modpacks-plus::...')) and migrations the same way
 * PluginService::loadPlugins() does. The migration path is added when the
 * application is created, before RefreshDatabase migrates.
 * Pure unit tests should extend PHPUnit\Framework\TestCase instead.
 */
abstract class TestCase extends PanelTestCase
{
    public const PLUGIN_ID = 'modpacks-plus';

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $migrations = dirname(__DIR__) . '/database/migrations';
        if ($app->resolved('migrator')) {
            $app->make('migrator')->path($migrations);
        } else {
            $app->afterResolving('migrator', function ($migrator) use ($migrations) {
                $migrator->path($migrations);
            });
        }

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
    }
}
