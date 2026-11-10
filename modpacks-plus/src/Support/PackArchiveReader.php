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
        $contents = '';
        $this->stream($name, $maxBytes, function (string $chunk) use (&$contents): void {
            $contents .= $chunk;
        });

        return $contents;
    }

    /**
     * Plan 31 fix: copies one entry to $destination (a new file, mode 0600; an existing one is
     * refused) without holding it in memory, with read()'s checks. On any failure the partial
     * file is removed.
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public function extract(string $name, int $maxBytes, string $destination): void
    {
        $sink = @fopen($destination, 'xb');
        if ($sink === false) {
            throw new PackUnavailableException('bad_archive', $name);
        }
        @chmod($destination, 0600);

        try {
            $this->stream($name, $maxBytes, function (string $chunk) use ($sink, $name): void {
                if (fwrite($sink, $chunk) !== strlen($chunk)) {
                    throw new PackUnavailableException('bad_archive', $name);
                }
            });
            if (!fclose($sink)) {
                $sink = null;

                throw new PackUnavailableException('bad_archive', $name);
            }
            $sink = null;
        } catch (\Throwable $exception) {
            if (is_resource($sink)) {
                fclose($sink);
            }
            @unlink($destination);

            throw $exception;
        }
    }

    /**
     * Plan 31 fix: the digest of one entry ($algorithm, e.g. sha512) without holding it in
     * memory, with read()'s checks.
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public function hash(string $name, int $maxBytes, string $algorithm): string
    {
        $context = hash_init($algorithm);
        $this->stream($name, $maxBytes, function (string $chunk) use ($context): void {
            hash_update($context, $chunk);
        });

        return hash_final($context);
    }

    /**
     * Feeds one entry to $sink in pieces, refusing more than $maxBytes or more/less than the
     * declared size (zip-bomb guard: a lying header is caught while reading, not after).
     *
     * @param  callable(string): void  $sink
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    private function stream(string $name, int $maxBytes, callable $sink): void
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

        $read = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new PackUnavailableException('bad_archive', $name);
                }
                $read += strlen($chunk);

                if ($read > $entry['size'] || $read > $maxBytes) {
                    throw new UnsafePlanException('file_too_large', $name);
                }
                if ($chunk !== '') {
                    $sink($chunk);
                }
            }
        } finally {
            fclose($stream);
        }

        if ($read !== $entry['size']) {
            throw new PackUnavailableException('bad_archive', $name.' size');
        }
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
