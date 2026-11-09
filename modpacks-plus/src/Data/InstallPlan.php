<?php

namespace DrMxrcy\ModpacksPlus\Data;

use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * A provider-neutral description of what installing one pack version does: the
 * target Minecraft version and loader, the files the node downloads, the archive
 * entries written as overrides and (for providers that have one) a server pack.
 *
 * Built by a PackProvider, then checked by PlanValidator before anything is written.
 */
final class InstallPlan
{
    /**
     * @param  array<int, PlanFile>  $files
     * @param  array<int, OverrideEntry>  $overrides
     * @param  array<int, string>  $skippedClientFiles  paths dropped because the server doesn't need them
     * @param  array<int, string>  $skippedOverrides  override targets dropped because they are protected
     * @param  array<int, string>  $skippedClientOverrides  override targets dropped because only clients use them
     * @param  array<int, array{name: string, url: ?string, reason: string}>  $manualFiles  files the user downloads
     *                                                                                      (never fetched), with a link
     *                                                                                      to the source's website
     * @param  array<int, string>  $skippedPackFiles  start scripts, installers, launcher files and bundled Java not written
     *                                                (server packs, and top-level files entries of every provider)
     * @param  string|null  $serverPackRoot  the folder inside $serverPack its files are under ('' = the zip root);
     *                                       without a server pack, set when $archive itself holds server
     *                                       files at that root (ATLauncher's Configs.zip, plan 32)
     * @param  array{reason: string, detail: string}|null  $serverPackFallback  why a server pack was not used
     * @param  array<int, array{path: string, project: string}>  $clientOnlyBy  skipped client files and the Modrinth
     *                                                                          project whose server_side said so
     * @param  array<int, string>  $skippedClientFolderFiles  pack files under client-only folders (resourcepacks/,
     *                                                        shaderpacks/, …), also in $skippedClientFiles
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $packId,
        public readonly string $versionId,
        public readonly string $packName,
        public readonly string $versionNumber,
        public readonly string $minecraftVersion,
        public readonly string $loader,
        public readonly ?string $loaderVersion,
        public readonly array $files = [],
        public readonly ?PackArchive $archive = null,
        public readonly array $overrides = [],
        public readonly ?PackArchive $serverPack = null,
        public readonly array $skippedClientFiles = [],
        public readonly array $skippedOverrides = [],
        public readonly array $skippedClientOverrides = [],
        public readonly array $manualFiles = [],
        public readonly array $skippedPackFiles = [],
        public readonly ?string $serverPackRoot = null,
        public readonly ?array $serverPackFallback = null,
        public readonly array $clientOnlyBy = [],
        public readonly array $skippedClientFolderFiles = [],
    ) {}

    /**
     * Where override entries come from: null = the archive's `overrides/` and
     * `server-overrides/`; a string = the server pack's root folder ('' = its zip root), or
     * the archive's when it holds server files at a root (ATLauncher's Configs.zip). Either
     * way a root means the server-pack rules apply (no start scripts, jars or dotfiles).
     */
    public function overrideRoot(): ?string
    {
        return $this->serverPack !== null ? ($this->serverPackRoot ?? '') : $this->serverPackRoot;
    }

    /** The archive override entries are read from: the server pack when there is one. */
    public function overrideArchive(): ?PackArchive
    {
        return $this->serverPack ?? $this->archive;
    }

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        $data = array_merge([
            'provider' => $this->provider,
            'packId' => $this->packId,
            'versionId' => $this->versionId,
            'packName' => $this->packName,
            'versionNumber' => $this->versionNumber,
            'minecraftVersion' => $this->minecraftVersion,
            'loader' => $this->loader,
            'loaderVersion' => $this->loaderVersion,
            'files' => $this->files,
            'archive' => $this->archive,
            'overrides' => $this->overrides,
            'serverPack' => $this->serverPack,
            'skippedClientFiles' => $this->skippedClientFiles,
            'skippedOverrides' => $this->skippedOverrides,
            'skippedClientOverrides' => $this->skippedClientOverrides,
            'manualFiles' => $this->manualFiles,
            'skippedPackFiles' => $this->skippedPackFiles,
            'serverPackRoot' => $this->serverPackRoot,
            'serverPackFallback' => $this->serverPackFallback,
            'clientOnlyBy' => $this->clientOnlyBy,
            'skippedClientFolderFiles' => $this->skippedClientFolderFiles,
        ], $changes);

        return new self(...$data);
    }

    /** Bytes written to the server: downloads plus extracted overrides. */
    public function totalBytes(): int
    {
        $total = 0;
        foreach ($this->files as $file) {
            $total += max(0, $file->size);
        }
        foreach ($this->overrides as $override) {
            $total += max(0, $override->size);
        }

        return $total;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'pack_id' => $this->packId,
            'version_id' => $this->versionId,
            'pack_name' => $this->packName,
            'version_number' => $this->versionNumber,
            'minecraft_version' => $this->minecraftVersion,
            'loader' => $this->loader,
            'loader_version' => $this->loaderVersion,
            'files' => array_map(fn (PlanFile $file) => $file->toArray(), $this->files),
            'archive' => $this->archive?->toArray(),
            'overrides' => array_map(fn (OverrideEntry $entry) => $entry->toArray(), $this->overrides),
            'server_pack' => $this->serverPack?->toArray(),
            'skipped_client_files' => $this->skippedClientFiles,
            'skipped_overrides' => $this->skippedOverrides,
            'skipped_client_overrides' => $this->skippedClientOverrides,
            'manual_files' => $this->manualFiles,
            'skipped_pack_files' => $this->skippedPackFiles,
            'server_pack_root' => $this->serverPackRoot,
            'server_pack_fallback' => $this->serverPackFallback,
            'client_only_by' => $this->clientOnlyBy,
            'skipped_client_folder_files' => $this->skippedClientFolderFiles,
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';
        $list = fn (string $key): array => is_array($data[$key] ?? null) ? array_values($data[$key]) : [];

        return new self(
            provider: $string('provider'),
            packId: $string('pack_id'),
            versionId: $string('version_id'),
            packName: $string('pack_name'),
            versionNumber: $string('version_number'),
            minecraftVersion: $string('minecraft_version'),
            loader: $string('loader'),
            loaderVersion: is_string($data['loader_version'] ?? null) ? $data['loader_version'] : null,
            files: array_map(fn ($file) => PlanFile::fromArray(is_array($file) ? $file : []), $list('files')),
            archive: is_array($data['archive'] ?? null) ? PackArchive::fromArray($data['archive']) : null,
            overrides: array_map(fn ($entry) => OverrideEntry::fromArray(is_array($entry) ? $entry : []), $list('overrides')),
            serverPack: is_array($data['server_pack'] ?? null) ? PackArchive::fromArray($data['server_pack']) : null,
            skippedClientFiles: array_values(array_filter($list('skipped_client_files'), 'is_string')),
            skippedOverrides: array_values(array_filter($list('skipped_overrides'), 'is_string')),
            skippedClientOverrides: array_values(array_filter($list('skipped_client_overrides'), 'is_string')),
            manualFiles: array_values(array_filter(array_map(fn ($entry) => is_array($entry) ? PlanValidator::manualFile($entry) : null, $list('manual_files')))),
            skippedPackFiles: array_values(array_filter($list('skipped_pack_files'), 'is_string')),
            serverPackRoot: is_string($data['server_pack_root'] ?? null) ? $data['server_pack_root'] : null,
            serverPackFallback: is_array($data['server_pack_fallback'] ?? null) && is_string($data['server_pack_fallback']['reason'] ?? null)
                ? ['reason' => $data['server_pack_fallback']['reason'], 'detail' => is_string($data['server_pack_fallback']['detail'] ?? null) ? $data['server_pack_fallback']['detail'] : '']
                : null,
            clientOnlyBy: array_values(array_filter(array_map(
                fn ($entry) => is_array($entry) && is_string($entry['path'] ?? null) && is_string($entry['project'] ?? null)
                    && PlanValidator::isSafeRelativePath($entry['path']) && preg_match('/^[A-Za-z0-9_\-.]{1,64}$/', $entry['project'])
                    ? ['path' => $entry['path'], 'project' => $entry['project']] : null,
                $list('client_only_by'),
            ))),
            skippedClientFolderFiles: array_values(array_filter($list('skipped_client_folder_files'), 'is_string')),
        );
    }
}
