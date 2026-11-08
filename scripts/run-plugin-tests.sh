#!/usr/bin/env bash
# Run every staged plugin's tests/ suite with the panel's Pest.
#
#   bash scripts/run-plugin-tests.sh <panel-dir>
#
# Expects the panel cloned + composer-installed and plugins staged into
# <panel-dir>/plugins/<id>/ (scripts/check.sh and CI do both). Writes only
# generated, git-ignored files inside <panel-dir>: plugin-tests-bootstrap.php,
# phpunit.plugins.xml and database/testing.sqlite.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
panel="${1:?usage: run-plugin-tests.sh <panel-dir>}"
panel="$(cd "$panel" && pwd)"

cp "$here/plugin-tests/bootstrap.php" "$panel/plugin-tests-bootstrap.php"
# Feature tests that use RefreshDatabase migrate this sqlite file (phpunit.xml: DB_DATABASE=testing.sqlite).
touch "$panel/database/testing.sqlite"

mapfile -t suites < <(php "$here/plugin-tests/make-phpunit-config.php" "$panel")
if [[ ${#suites[@]} -eq 0 ]]; then
    echo "no plugin has a tests/ folder"
    exit 0
fi

cd "$panel"
for id in "${suites[@]}"; do
    echo "--> $id"
    php -d memory_limit=-1 vendor/bin/pest --configuration phpunit.plugins.xml --testsuite "$id" \
        --test-directory "plugins/$id/tests" --do-not-fail-on-phpunit-warning
done
