<?php

namespace DrMxrcy\ModpacksPlus\Data;

/** One file of a pack the node downloads onto the server. */
final class PlanFile
{
    /** Hash checked through Modrinth's version_files lookup before the pull; size checked after. */
    public const VERIFY_MODRINTH = 'modrinth-lookup';

    /** File read back through the panel after the pull and hashed there. */
    public const VERIFY_READBACK = 'readback';

    /**
     * @param  array<int, string>  $urls  candidate download URLs, in the pack's order
     * @param  array<string, string>  $hashes  algorithm => lowercase hex digest (sha512, sha1)
     * @param  string  $side  the pack's env.server value: required, optional, unsupported or unknown
     * @param  string|null  $url  the URL that will be used (set by PlanValidator)
     * @param  string|null  $verify  how the download is verified (VERIFY_*), set while preparing
     */
    public function __construct(
        public readonly string $path,
        public readonly array $urls,
        public readonly int $size,
        public readonly array $hashes,
        public readonly string $side = 'required',
        public readonly ?string $url = null,
        public readonly ?string $verify = null,
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
            path: is_string($data['path'] ?? null) ? $data['path'] : '',
            urls: array_values(array_filter(is_array($data['urls'] ?? null) ? $data['urls'] : [], 'is_string')),
            size: is_numeric($data['size'] ?? null) ? (int) $data['size'] : -1,
            hashes: $hashes,
            side: is_string($data['side'] ?? null) ? $data['side'] : 'unknown',
            url: is_string($data['url'] ?? null) ? $data['url'] : null,
            verify: is_string($data['verify'] ?? null) ? $data['verify'] : null,
        );
    }

    /** @return array{path: string, urls: array<int, string>, size: int, hashes: array<string, string>, side: string, url: ?string, verify: ?string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'urls' => $this->urls,
            'size' => $this->size,
            'hashes' => $this->hashes,
            'side' => $this->side,
            'url' => $this->url,
            'verify' => $this->verify,
        ];
    }

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }

    /** Parent directory relative to the server root ('' for the root itself). */
    public function directory(): string
    {
        $directory = dirname($this->path);

        return $directory === '.' ? '' : $directory;
    }

    public function filename(): string
    {
        return basename($this->path);
    }
}
