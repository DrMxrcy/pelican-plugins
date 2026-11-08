<?php

/**
 * Validate every top-level plugin folder's plugin.json.
 *
 * Rules: valid JSON, required keys present, no "meta" key (panel writes that),
 * id matches the folder name, version is semver.
 */
$root = realpath(__DIR__ . '/..');
$required = ['id', 'name', 'author', 'version', 'description', 'category', 'namespace', 'class', 'panels'];
$errors = [];
$count = 0;

foreach (glob($root . '/*/plugin.json') as $file) {
    $count++;
    $folder = basename(dirname($file));
    $rel = "$folder/plugin.json";
    $json = json_decode((string) file_get_contents($file), true);

    if (!is_array($json)) {
        $errors[] = "$rel: invalid JSON (" . json_last_error_msg() . ')';

        continue;
    }

    foreach ($required as $key) {
        if (!array_key_exists($key, $json)) {
            $errors[] = "$rel: missing '$key'";
        }
    }

    if (array_key_exists('meta', $json)) {
        $errors[] = "$rel: contains 'meta' key, remove it";
    }

    if (($json['id'] ?? null) !== $folder) {
        $errors[] = "$rel: id '" . ($json['id'] ?? '') . "' must equal folder name '$folder'";
    }

    if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', (string) ($json['version'] ?? ''))) {
        $errors[] = "$rel: version must be semver";
    }

    echo "checked $rel\n";
}

if ($count === 0) {
    $errors[] = 'no plugin.json files found';
}

if ($errors) {
    fwrite(STDERR, "\n" . implode("\n", array_map(fn ($e) => "  - $e", $errors)) . "\n");
    exit(1);
}

echo "all $count plugin.json file(s) valid\n";
