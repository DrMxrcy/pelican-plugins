<?php

namespace DrMxrcy\ModpacksPlus\Support;

use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use ZipArchive;

/**
 * Zip-slip-safe reader for a pack archive on the panel. Opening it checks every
 * entry name (PlanValidator::checkArchiveEntries), the entry count, symlinks,
 * encryption and duplicates before anything else is read. Entry reads are capped
 * and must match the size the archive declares (zip-bomb guard). Nothing is ever
 * extracted to disk by path.
 */
final class PackArchiveReader
{
    /** @var array<string, array{index: int, size: int}> */
    private array $entries = [];

    private function __construct(private readonly ZipArchive $zip) {}

    /**
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public static function open(string $path, PlanValidator $validator, Caps $caps): self
    {
        $zip = new ZipArchive();
        if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new PackUnavailableException('bad_archive', basename($path));
        }

        $reader = new self($zip);

        try {
            if ($zip->numFiles > $caps->maxArchiveEntries) {
                throw new UnsafePlanException('too_many_entries', (string) $zip->numFiles);
            }

            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
                if ($stat === false) {
                    throw new PackUnavailableException('bad_archive', "entry $i");
                }

                $name = (string) $stat['name'];
                $names[] = $name;

                if ($stat['encryption_method'] !== 0) {
                    throw new PackUnavailableException('bad_archive', $name);
                }

                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && $opsys === ZipArchive::OPSYS_UNIX
                    && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new UnsafePlanException('unsafe_path', $name.' (symlink)');
                }

                if (isset($reader->entries[$name])) {
                    throw new UnsafePlanException('duplicate_path', $name);
                }
                $reader->entries[$name] = ['index' => $i, 'size' => (int) $stat['size']];
            }

            $validator->checkArchiveEntries($names);
        } catch (\Throwable $exception) {
            $zip->close();

            throw $exception;
        }

        return $reader;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    public function size(string $name): ?int
    {
        return $this->entries[$name]['size'] ?? null;
    }

    /**
     * Reads one entry into memory, refusing more than $maxBytes or more/less than the
     * declared size.
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public function read(string $name, int $maxBytes): string
    {
        $entry = $this->entries[$name] ?? null;
        if ($entry === null) {
            throw new PackUnavailableException('bad_archive', $name.' missing');
        }

        if ($entry['size'] > $maxBytes) {
            throw new UnsafePlanException('file_too_large', $name);
        }

        $stream = $this->zip->getStreamIndex($entry['index']);
        if ($stream === false) {
            throw new PackUnavailableException('bad_archive', $name);
        }

        $contents = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new PackUnavailableException('bad_archive', $name);
                }
                $contents .= $chunk;

                if (strlen($contents) > $entry['size'] || strlen($contents) > $maxBytes) {
                    throw new UnsafePlanException('file_too_large', $name);
                }
            }
        } finally {
            fclose($stream);
        }

        if (strlen($contents) !== $entry['size']) {
            throw new PackUnavailableException('bad_archive', $name.' size');
        }

        return $contents;
    }

    /**
     * Entries applied to the server, keyed by target: `overrides/` first, then
     * `server-overrides/` replacing the same target. `client-overrides/` is never used.
     *
     * @return array<int, OverrideEntry>
     *
     * @throws UnsafePlanException
     */
    public function overrides(PlanValidator $validator): array
    {
        $byTarget = [];
        foreach (PlanValidator::OVERRIDE_PREFIXES as $prefix) {
            foreach ($this->entries as $name => $entry) {
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }

                $target = $validator->overrideTarget($name);
                if ($target === null) {
                    continue;
                }

                // Exact key: server-overrides/ replaces the same path; entries differing only
                // in case are both kept and then refused by PlanValidator (duplicate_path).
                $byTarget[$target] = new OverrideEntry($name, $target, $entry['size']);
            }
        }

        return array_values($byTarget);
    }

    public function close(): void
    {
        $this->zip->close();
    }
}
