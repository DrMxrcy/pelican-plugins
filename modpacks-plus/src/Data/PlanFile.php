<?php

namespace DrMxrcy\ModpacksPlus\Data;

/** One file of a pack the node downloads onto the server. */
final class PlanFile
{
    /**
     * On cdn.modrinth.com and vouched for by Modrinth's version_files lookup: the only
     * kind of file the node (Wings) is ever asked to pull. Size checked after the pull.
     */
    public const VERIFY_MODRINTH = 'modrinth-lookup';

    /**
     * Everything else: fetched by the panel (every redirect hop allowlisted, byte-capped),
     * hashed on the panel, and only then written to the server.
     */
    public const VERIFY_PANEL = 'panel-fetch';

    /**
     * Plan 32 security review: a file ATLauncher serves from its own CDN
     * (https://download.nodecdn.net/containers/atl/, `download: server`) for which no sha1 or
     * sha512 exists anywhere. Fetched by the panel exactly like VERIFY_PANEL (no redirects)
     * and checked against ATLauncher's md5, which adds no trust beyond ATLauncher itself (it
     * writes both the manifest and the file). Never accepted for any other host or verify value.
     */
    public const VERIFY_ATLAUNCHER_MD5 = 'atlauncher-md5';

    /** Verify values whose file the panel fetches (never the node). */
    public const PANEL_FETCHED = [self::VERIFY_PANEL, self::VERIFY_ATLAUNCHER_MD5];

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
