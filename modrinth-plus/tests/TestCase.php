<?php

namespace DrMxrcy\ModrinthPlus\Tests;

use App\Tests\TestCase as PanelTestCase;

/**
 * Laravel-booted base for modrinth-plus tests.
 *
 * The panel skips plugin loading while running tests, so this registers the
 * plugin's config (config('modrinth-plus.*')) and translation namespace
 * (trans('modrinth-plus::...')) the same way PluginService::loadPlugins() does.
 * Add RefreshDatabase in a subclass for tests that need the panel DB/factories.
 * Pure unit tests should extend PHPUnit\Framework\TestCase instead.
 */
abstract class TestCase extends PanelTestCase
{
    public const PLUGIN_ID = 'modrinth-plus';

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
