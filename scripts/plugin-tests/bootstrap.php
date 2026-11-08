<?php

/*
 * PHPUnit bootstrap for plugin test suites, copied into the panel checkout by
 * scripts/run-plugin-tests.sh. The panel skips PluginService::loadPlugins() while
 * running unit tests, so plugin classes are not autoloaded there: register PSR-4
 * for every staged plugin (src/ and tests/) from its plugin.json namespace.
 */

use Composer\Autoload\ClassLoader;

/** @var ClassLoader $loader */
$loader = require __DIR__ . '/vendor/autoload.php';

foreach (glob(__DIR__ . '/plugins/*/plugin.json') ?: [] as $manifest) {
    $dir = dirname($manifest);
    $data = json_decode((string) file_get_contents($manifest), true);
    $namespace = is_array($data) && is_string($data['namespace'] ?? null) ? trim($data['namespace'], '\\') : '';

    if ($namespace === '') {
        fwrite(STDERR, "plugin-tests: no namespace in $manifest, skipping autoload\n");

        continue;
    }

    $loader->addPsr4($namespace . '\\Tests\\', $dir . '/tests/');
    $loader->addPsr4($namespace . '\\', $dir . '/src/');
}
