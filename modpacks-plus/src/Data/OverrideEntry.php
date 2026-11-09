<?php

namespace DrMxrcy\ModpacksPlus\Data;

/** One archive entry (overrides/ or server-overrides/) written to `path` on the server. */
final class OverrideEntry
{
    public function __construct(
        public readonly string $entry,
        public readonly string $path,
        public readonly int $size,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            entry: is_string($data['entry'] ?? null) ? $data['entry'] : '',
            path: is_string($data['path'] ?? null) ? $data['path'] : '',
            size: is_numeric($data['size'] ?? null) ? (int) $data['size'] : -1,
        );
    }

    /** @return array{entry: string, path: string, size: int} */
    public function toArray(): array
    {
        return ['entry' => $this->entry, 'path' => $this->path, 'size' => $this->size];
    }
}
