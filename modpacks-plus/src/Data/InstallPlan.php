<?php

namespace DrMxrcy\ModpacksPlus\Data;

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
    ) {}

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
        );
    }
}
