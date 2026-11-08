#!/usr/bin/env bash
# Package one plugin into dist/<id>.zip (+ dist/<id>.update.json).
#
#   scripts/package.sh <plugin-dir> [download-url]
#
# The zip contains a single top-level folder named after the plugin id, which is
# the layout Pelican's importer expects (plugin.json at root or one level deep).
# If a download URL is given, update.json is written for Pelican's update checker
# (format: {"*": {version, download_url, sha256}}).
set -euo pipefail

dir="${1:?usage: scripts/package.sh <plugin-dir> [download-url]}"
download_url="${2:-}"
root="$(cd "$(dirname "$0")/.." && pwd)"
dir="${dir%/}"

manifest="$root/$dir/plugin.json"
[[ -f "$manifest" ]] || { echo "no plugin.json in $dir" >&2; exit 1; }

id="$(jq -r .id "$manifest")"
version="$(jq -r .version "$manifest")"
[[ "$id" == "$(basename "$dir")" ]] || { echo "plugin id '$id' must match folder '$(basename "$dir")'" >&2; exit 1; }

dist="$root/dist"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$dist" "$stage/$id"

rsync -a \
    --exclude '.DS_Store' --exclude '__MACOSX' --exclude '.git' --exclude '.idea' --exclude '.vscode' \
    --exclude 'tests' --exclude 'node_modules' \
    "$root/$dir/" "$stage/$id/"

rm -f "$dist/$id.zip"
(cd "$stage" && zip -qrX "$dist/$id.zip" "$id")

sha="$( (command -v sha256sum >/dev/null && sha256sum "$dist/$id.zip" || shasum -a 256 "$dist/$id.zip") | cut -d' ' -f1)"

if [[ -n "$download_url" ]]; then
    jq -n --arg v "$version" --arg u "$download_url" --arg s "$sha" \
        '{"*": {version: $v, download_url: $u, sha256: $s}}' > "$dist/$id.update.json"
fi

echo "$dist/$id.zip  version=$version  sha256=$sha"
