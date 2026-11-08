<?php

/*
 * Writes <panel>/phpunit.plugins.xml from the panel's own phpunit.xml: same <php>
 * env (sqlite testing DB, array cache, ...), plugin bootstrap, and one test suite
 * per staged plugin that has a tests/ folder. Prints the suite names.
 *
 *   php make-phpunit-config.php <panel-dir>
 */

$panel = rtrim($argv[1] ?? '', '/');
if ($panel === '' || !is_file("$panel/phpunit.xml")) {
    fwrite(STDERR, "usage: php make-phpunit-config.php <panel-dir>\n");
    exit(2);
}

$doc = new DOMDocument();
$doc->preserveWhiteSpace = false;
$doc->formatOutput = true;
$doc->load("$panel/phpunit.xml");

$root = $doc->documentElement;
$root->setAttribute('bootstrap', 'plugin-tests-bootstrap.php');
$root->setAttribute('cacheDirectory', 'storage/framework/cache/phpunit-plugins');

foreach (['testsuites', 'source', 'coverage'] as $tag) {
    foreach (iterator_to_array($root->getElementsByTagName($tag)) as $node) {
        $root->removeChild($node);
    }
}

// A fixed key so encrypted casts/cookies work without touching the panel .env.
$php = $root->getElementsByTagName('php')->item(0) ?? $root->appendChild($doc->createElement('php'));
$env = $doc->createElement('env');
$env->setAttribute('name', 'APP_KEY');
$env->setAttribute('value', 'base64:' . base64_encode(str_repeat('p', 32)));
$php->appendChild($env);

$suites = $doc->createElement('testsuites');
$names = [];
foreach (glob("$panel/plugins/*/tests", GLOB_ONLYDIR) ?: [] as $testsDir) {
    $id = basename(dirname($testsDir));
    $suite = $doc->createElement('testsuite');
    $suite->setAttribute('name', $id);
    $suite->appendChild($doc->createElement('directory', "plugins/$id/tests"));
    $suites->appendChild($suite);
    $names[] = $id;
}
$php->parentNode->insertBefore($suites, $php);

$doc->save("$panel/phpunit.plugins.xml");
echo implode("\n", $names), $names === [] ? '' : "\n";
