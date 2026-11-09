#!/usr/bin/env bash
# Local quality gate: plugin.json validation, Pint, PHPStan and plugin tests.
#
#   bash scripts/check.sh            # everything
#   bash scripts/check.sh pint       # one stage: validate | pint | phpstan | test
#
# Runs inside Docker (scripts/docker/Dockerfile, PHP 8.4 + composer), so no local
# PHP is needed. Clones Pelican Panel into the git-ignored ./pelican folder on first
# run (refresh with PELICAN_REFRESH=1), stages every plugin into pelican/plugins/
# and runs PHPStan + the plugin test suites from inside the panel, the same way CI does.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
image="pelican-plugins-check:php8.4"

if [[ "${PELICAN_CHECK_IN_CONTAINER:-}" != 1 ]]; then
    command -v docker >/dev/null || { echo "docker is required" >&2; exit 1; }
    docker image inspect "$image" >/dev/null 2>&1 || docker build -q -t "$image" "$root/scripts/docker" >/dev/null
    exec docker run --rm \
        -e PELICAN_CHECK_IN_CONTAINER=1 -e PELICAN_REFRESH="${PELICAN_REFRESH:-}" \
        -v "$root:/repo" -v pelican-plugins-composer-cache:/root/.composer/cache \
        -w /repo "$image" bash scripts/check.sh "$@"
fi

cd "$root"
stage="${1:-all}"

run_validate() {
    echo "==> validate plugin.json"
    php scripts/validate-plugins.php
}

run_pint() {
    echo "==> pint"
    if [[ ! -x .tools/pint/vendor/bin/pint ]]; then
        mkdir -p .tools/pint
        composer --working-dir=.tools/pint init -q --name=local/pint-runner
        composer --working-dir=.tools/pint require -q --dev "laravel/pint:^1.29"
    fi
    .tools/pint/vendor/bin/pint --test
}

ensure_panel() {
    if [[ -n "${PELICAN_REFRESH:-}" ]]; then rm -rf pelican; fi
    if [[ ! -d pelican/.git ]]; then
        echo "==> cloning pelican-dev/panel"
        git clone -q --depth=1 https://github.com/pelican-dev/panel pelican
    fi
    if [[ ! -d pelican/vendor ]]; then
        echo "==> composer install (panel)"
        (cd pelican && composer install -q --no-progress --no-scripts)
    fi
    [[ -f pelican/.env ]] || cp pelican/.env.example pelican/.env
    # Stage plugins exactly like CI: one folder per plugin under pelican/plugins/.
    mkdir -p pelican/plugins
    # Drop staged copies of plugins that no longer exist here, so a removed or renamed
    # plugin can't keep running in phpstan/tests.
    for staged in pelican/plugins/*/; do
        [[ -d "$staged" ]] || continue
        d="$(basename "$staged")"
        [[ -f "$d/plugin.json" ]] || rm -rf "$staged"
    done
    for manifest in */plugin.json; do
        d="$(dirname "$manifest")"
        [[ "$d" == pelican ]] && continue
        rsync -a --delete "$d/" "pelican/plugins/$d/"
    done
    cp phpstan.neon pelican/phpstan.neon
}

run_phpstan() {
    ensure_panel
    echo "==> phpstan"
    (cd pelican && vendor/bin/phpstan analyse --memory-limit=-1 --no-progress)
}

run_test() {
    ensure_panel
    echo "==> plugin tests"
    bash scripts/run-plugin-tests.sh pelican
}

case "$stage" in
    all) run_validate; run_pint; run_phpstan; run_test ;;
    validate) run_validate ;;
    pint) run_pint ;;
    phpstan) run_phpstan ;;
    test) run_test ;;
    *) echo "unknown stage: $stage (validate|pint|phpstan|test)" >&2; exit 2 ;;
esac

echo "==> OK ($stage)"
