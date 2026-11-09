<?php

namespace DrMxrcy\ModpacksPlus\Support;

use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;

/**
 * The official pelican-eggs/minecraft loader eggs this plugin can move a server onto,
 * and which of their variables take the Minecraft and loader versions. Pure: no I/O.
 *
 * Researched 2026-11-09 against github.com/pelican-eggs/minecraft (branch main, commits
 * 75bf05d 2026-05-18 for Fabric, Forge and Quilt, 5b8481e 2026-04-21 for NeoForge; older
 * JSON exports and the Pterodactyl-format copies checked in the history):
 *
 * | Loader   | File (java/…)                         | uuid                                 | MC var     | Loader var                            |
 * |----------|---------------------------------------|--------------------------------------|------------|---------------------------------------|
 * | Fabric   | fabric/egg-fabric.yaml                | 78b02ebb-fec8-49c5-943c-ca4aa117b693 | MC_VERSION | LOADER_VERSION (fabric-loader)        |
 * | Forge    | forge/egg-forge-minecraft.yaml        | ed072427-f209-4603-875c-f540c6dd5a65 | MC_VERSION | FORGE_VERSION = "<mc>-<forge>"        |
 * | Forge    | forge/forge/egg-forge-enhanced.json   | d6018085-eecc-42bf-bf8c-51ea45a69ace | MC_VERSION | FORGE_VERSION (same script, pre 2026) |
 * | NeoForge | neoforge/egg-neo-forge.json           | e23e092f-b803-4f34-82cf-2d6518c6351a | MC_VERSION | NEOFORGE_VERSION ("1.20.1-" for 1.20.1) |
 * | Quilt    | quilt/egg-quilt.yaml                  | dff33655-6e6a-4430-accf-e5aea04c2912 | MC_VERSION | none: the script installs the latest  |
 *
 * - The official eggs carry only the `minecraft` tag (no loader tag), so they are
 *   recognised by uuid (the panel's importer keeps it) or by update_url (the
 *   Pterodactyl-format copies have no uuid; the importer then makes a random one).
 * - All four offer the images ghcr.io/pelican-eggs/yolks:java_{8,11,16,17,21,25}.
 * - Variable rules: Fabric `between:3,15` (all three); Forge/NeoForge MC_VERSION `max:9`,
 *   FORGE_VERSION/NEOFORGE_VERSION `nullable|regex:/^[0-9\.\-]+$/`; Quilt MC_VERSION `between:3,15`.
 *   The checks below are stricter, so a value never reaches the egg's install script
 *   unless the egg would accept it too (the egg's own rules are checked again on use).
 * - Forge's script downloads `forge/<FORGE_VERSION>/forge-<FORGE_VERSION>-installer.jar`, so it
 *   needs the full "<mc>-<forge>" version; Modrinth's index gives the forge part only.
 * - NeoForge's script reads a NEOFORGE_VERSION containing "1.20.1-" as the old
 *   net.neoforged:forge artifact; newer ones are plain ("21.1.77").
 */
final class EggVariableMap
{
    private const RAW_PREFIX = 'https://raw.githubusercontent.com/pelican-eggs/minecraft/';

    /** Refs the official update URLs use (`main` and `refs/heads/main` both serve the branch). */
    private const REFS = ['main/', 'refs/heads/main/'];

    /**
     * @var array<string, array{uuids: array<int, string>, files: array<int, string>, minecraft: string, loader: ?string, format: string, minecraft_max: int}>
     */
    private const EGGS = [
        'fabric' => [
            'uuids' => ['78b02ebb-fec8-49c5-943c-ca4aa117b693'],
            'files' => ['java/fabric/egg-fabric.yaml', 'java/fabric/egg-fabric.json', 'java/fabric/egg-pterodactyl-fabric.json'],
            'minecraft' => 'MC_VERSION',
            'loader' => 'LOADER_VERSION',
            'format' => 'plain',
            'minecraft_max' => 15,
        ],
        'forge' => [
            'uuids' => ['ed072427-f209-4603-875c-f540c6dd5a65', 'd6018085-eecc-42bf-bf8c-51ea45a69ace'],
            'files' => ['java/forge/egg-forge-minecraft.yaml', 'java/forge/egg-forge-minecraft.json', 'java/forge/pterodactyl-egg-forge-minecraft.json'],
            'minecraft' => 'MC_VERSION',
            'loader' => 'FORGE_VERSION',
            'format' => 'minecraft_prefixed',
            'minecraft_max' => 9,
        ],
        'neoforge' => [
            'uuids' => ['e23e092f-b803-4f34-82cf-2d6518c6351a'],
            'files' => ['java/neoforge/egg-neo-forge.json', 'java/neoforge/egg-neo-forge.yaml', 'java/neoforge/egg-pterodactyl-neo-forge.json'],
            'minecraft' => 'MC_VERSION',
            'loader' => 'NEOFORGE_VERSION',
            'format' => 'neoforge',
            'minecraft_max' => 9,
        ],
        'quilt' => [
            'uuids' => ['dff33655-6e6a-4430-accf-e5aea04c2912'],
            'files' => ['java/quilt/egg-quilt.yaml', 'java/quilt/egg-quilt.json', 'java/quilt/egg-pterodactyl-quilt.json'],
            'minecraft' => 'MC_VERSION',
            'loader' => null,
            'format' => 'none',
            'minecraft_max' => 15,
        ],
    ];

    /** Release-style Minecraft versions only (1.20.1, 26.1); no `latest`, snapshots or letters. */
    private const MINECRAFT_PATTERN = '/^\d{1,2}\.\d{1,2}(\.\d{1,2})?$/';

    /** Loader versions as Modrinth gives them: digits, dots and (Fabric/Quilt) plus/dash builds. */
    private const LOADER_PATTERN = '/^[0-9][0-9A-Za-z.+\-]{0,62}$/';

    /** What Forge and NeoForge eggs accept (regex:/^[0-9\.\-]+$/). */
    private const NUMERIC_PATTERN = '/^[0-9][0-9.\-]{0,62}$/';

    /** @return array<int, string> */
    public static function loaders(): array
    {
        return array_keys(self::EGGS);
    }

    /**
     * The loader of an official egg, recognised by its uuid or its update_url; null for
     * any other egg (or when uuid and URL point at different loaders).
     */
    public static function loaderFor(?string $uuid, ?string $updateUrl): ?string
    {
        $byUuid = null;
        $uuid = $uuid !== null ? strtolower(trim($uuid)) : null;
        foreach (self::EGGS as $loader => $egg) {
            if ($uuid !== null && in_array($uuid, $egg['uuids'], true)) {
                $byUuid = $loader;
            }
        }

        $byUrl = self::loaderForUpdateUrl($updateUrl);

        if ($byUuid !== null && $byUrl !== null && $byUuid !== $byUrl) {
            return null;
        }

        return $byUuid ?? $byUrl;
    }

    public static function isOfficialUpdateUrl(?string $updateUrl): bool
    {
        return self::loaderForUpdateUrl($updateUrl) !== null;
    }

    private static function loaderForUpdateUrl(?string $updateUrl): ?string
    {
        if ($updateUrl === null || !str_starts_with($updateUrl, self::RAW_PREFIX)) {
            return null;
        }

        $rest = substr($updateUrl, strlen(self::RAW_PREFIX));
        foreach (self::EGGS as $loader => $egg) {
            foreach (self::REFS as $ref) {
                foreach ($egg['files'] as $file) {
                    if ($rest === $ref.$file) {
                        return $loader;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Env variables the egg must have for this plugin to set the versions.
     *
     * @return array<int, string>
     */
    public static function requiredVariables(string $loader): array
    {
        $egg = self::egg($loader);

        return array_values(array_filter([$egg['minecraft'], $egg['loader']]));
    }

    /** Whether the egg lets the loader version be chosen (Quilt's always installs the latest). */
    public static function pinsLoaderVersion(string $loader): bool
    {
        return self::egg($loader)['loader'] !== null;
    }

    /**
     * Env variable => value to set on a server of this loader's egg.
     *
     * @return array<string, string>
     *
     * @throws InstallRefusedException `version_unsupported` when a value is not one the egg takes
     */
    public static function variables(string $loader, string $minecraft, ?string $loaderVersion): array
    {
        $egg = self::egg($loader);

        if (!preg_match(self::MINECRAFT_PATTERN, $minecraft) || strlen($minecraft) > $egg['minecraft_max']) {
            throw new InstallRefusedException('version_unsupported', $minecraft);
        }

        $variables = [$egg['minecraft'] => $minecraft];

        if ($egg['loader'] === null) {
            return $variables;
        }

        $loaderVersion = trim((string) $loaderVersion);
        if (!preg_match(self::LOADER_PATTERN, $loaderVersion)) {
            throw new InstallRefusedException('version_unsupported', $loader.' '.$loaderVersion);
        }

        $value = match ($egg['format']) {
            'minecraft_prefixed' => str_starts_with($loaderVersion, $minecraft.'-') ? $loaderVersion : $minecraft.'-'.$loaderVersion,
            'neoforge' => $minecraft === '1.20.1' && !str_starts_with($loaderVersion, '1.20.1-') ? '1.20.1-'.$loaderVersion : $loaderVersion,
            default => $loaderVersion,
        };

        if ($egg['format'] !== 'plain' && !preg_match(self::NUMERIC_PATTERN, $value)) {
            throw new InstallRefusedException('version_unsupported', $loader.' '.$loaderVersion);
        }

        if ($egg['format'] === 'plain' && (strlen($value) < 3 || strlen($value) > 15)) {
            throw new InstallRefusedException('version_unsupported', $loader.' '.$loaderVersion);
        }

        $variables[$egg['loader']] = $value;

        return $variables;
    }

    /**
     * @return array{uuids: array<int, string>, files: array<int, string>, minecraft: string, loader: ?string, format: string, minecraft_max: int}
     *
     * @throws InstallRefusedException
     */
    private static function egg(string $loader): array
    {
        if (!isset(self::EGGS[$loader])) {
            throw new InstallRefusedException('loader_unsupported', $loader);
        }

        return self::EGGS[$loader];
    }
}
