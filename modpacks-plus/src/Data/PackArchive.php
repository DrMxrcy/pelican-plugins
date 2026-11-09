<?php

namespace DrMxrcy\ModpacksPlus\Data;

/** A pack archive (Modrinth .mrpack, or a provider's server pack) read by the panel. */
final class PackArchive
{
    /** File name of a plan's archive in the install's panel work folder. */
    public const WORK_FILE = 'pack.mrpack';

    /** File name of a plan's server pack in the install's panel work folder. */
    public const SERVER_PACK_WORK_FILE = 'server-pack.zip';

    /** @param  array<string, string>  $hashes  algorithm => lowercase hex digest */
    public function __construct(
        public readonly string $url,
        public readonly string $filename,
        public readonly int $size,
        public readonly array $hashes,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $hashes = [];
        foreach (is_array($data['hashes'] ?? null) ? $data['hashes'] : [] as $algorithm => $digest) {
            if (is_string($algorithm) && is_string($digest)) {
                $hashes[strtolower($algorithm)] = $digest;
            }
        }

        return new self(
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            filename: is_string($data['filename'] ?? null) ? $data['filename'] : '',
            size: is_numeric($data['size'] ?? null) ? (int) $data['size'] : -1,
            hashes: $hashes,
        );
    }

    /** @return array{url: string, filename: string, size: int, hashes: array<string, string>} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'filename' => $this->filename, 'size' => $this->size, 'hashes' => $this->hashes];
    }
}
