<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\OverrideArchive;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Plan 42: the zip the node unpacks is built here from validated override entries only,
 * never copied from the pack. Hostile entries are refused, the result holds exactly the
 * expected regular files.
 */
class OverrideArchiveTest extends TestCase
{
    private string $pack;

    private string $work;

    /** Entries of the source pack (some hostile: the builder never gets them as validated entries). */
    private const PACK = [
        'modrinth.index.json' => '{}',
        'overrides/config/a.toml' => 'alpha',
        'overrides/config/sub/b.json' => '{"b":1}',
        'overrides/123' => 'numeric name',
        'overrides/ops.json' => '[{"name":"attacker"}]',
        'overrides/world/level.dat' => 'evil level',
        'overrides/.modpacks-plus/staging/overrides-1-0-1.zip' => 'planted',
        'overrides/mods/.modrinth-metadata.json' => '{}',
        'server-overrides/config/a.toml' => 'server alpha',
    ];

    protected function setUp(): void
    {
        $this->pack = (string) tempnam(sys_get_temp_dir(), 'pack');
        $zip = new ZipArchive();
        $zip->open($this->pack, ZipArchive::OVERWRITE);
        foreach (self::PACK as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $this->work = sys_get_temp_dir().'/override-archive-test-'.bin2hex(random_bytes(4)).'/overrides-0.zip';
    }

    protected function tearDown(): void
    {
        foreach ([$this->pack, $this->work] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir(dirname($this->work))) {
            rmdir(dirname($this->work));
        }
    }

    private function reader(): PackArchiveReader
    {
        return PackArchiveReader::open($this->pack, new PlanValidator(new Caps()), new Caps());
    }

    /** @param  array<int, OverrideEntry>  $entries */
    private function build(array $entries, ?Caps $caps = null): OverrideArchive
    {
        $reader = $this->reader();

        try {
            return OverrideArchive::build($entries, $reader, new PlanValidator(new Caps()), null, $caps ?? new Caps(), $this->work);
        } finally {
            $reader->close();
        }
    }

    private static function entry(string $name, string $path, ?int $size = null): OverrideEntry
    {
        return new OverrideEntry($name, $path, $size ?? strlen(self::PACK[$name] ?? ''));
    }

    /** @return array<string, array{string, int}> name => [contents, unix mode] of a built zip */
    private static function unzip(string $bytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'built');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::RDONLY);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $out[$name] = [(string) $zip->getFromIndex($i), $opsys === ZipArchive::OPSYS_UNIX ? ($attributes >> 16) : -1];
        }
        $zip->close();
        unlink($path);

        return $out;
    }

    public function test_builds_exactly_the_validated_entries_under_their_target_paths(): void
    {
        $reader = $this->reader();
        // What the plan holds after validation: the reader's overrides minus what PlanValidator drops.
        $validator = new PlanValidator(new Caps());
        $validated = array_values(array_filter($reader->overrides($validator), fn (OverrideEntry $entry) => !$validator->isProtected($entry->path) && !PlanValidator::isModrinthMetadata($entry->path)));
        $reader->close();

        $archive = $this->build($validated);

        // Strict: in zip order and as strings (a numeric name stays "123").
        $this->assertSame(['config/a.toml', 'config/sub/b.json', '123'], $archive->paths);
        $files = self::unzip($archive->contents);
        $this->assertSame([
            'config/a.toml' => ['server alpha', OverrideArchive::FILE_MODE],
            'config/sub/b.json' => ['{"b":1}', OverrideArchive::FILE_MODE],
            '123' => ['numeric name', OverrideArchive::FILE_MODE],
        ], array_intersect_key($files, array_flip($archive->paths)));
        $this->assertCount(3, $files);

        // The hostile entries of the pack are not in it, under any name.
        foreach (array_keys($files) as $name) {
            $this->assertDoesNotMatchRegularExpression('#ops\.json|world|\.modpacks-plus|modrinth-metadata|^overrides/#', (string) $name);
        }
        $this->assertFileDoesNotExist($this->work);
    }

    /** @return array<string, array{array<int, OverrideEntry>, string}> */
    public static function hostileEntries(): array
    {
        return [
            'parent path' => [[new OverrideEntry('overrides/../escape', '../escape', 1)], 'unsafe_path'],
            'absolute path' => [[new OverrideEntry('overrides/config/a.toml', '/etc/passwd', 5)], 'unsafe_path'],
            'backslash' => [[new OverrideEntry('overrides/config/a.toml', 'config\\..\\..\\x', 5)], 'unsafe_path'],
            'target not derived from the entry' => [[self::entry('overrides/config/a.toml', 'config/other.toml')], 'unsafe_path'],
            'client-overrides entry' => [[new OverrideEntry('client-overrides/config/a.toml', 'config/a.toml', 5)], 'unsafe_path'],
            'player list' => [[self::entry('overrides/ops.json', 'ops.json')], 'protected_path'],
            'world' => [[self::entry('overrides/world/level.dat', 'world/level.dat')], 'protected_path'],
            'own folder' => [[self::entry('overrides/.modpacks-plus/staging/overrides-1-0-1.zip', '.modpacks-plus/staging/overrides-1-0-1.zip')], 'protected_path'],
            'modrinth metadata' => [[self::entry('overrides/mods/.modrinth-metadata.json', 'mods/.modrinth-metadata.json')], 'protected_path'],
            'duplicate target' => [[self::entry('overrides/config/a.toml', 'config/a.toml'), new OverrideEntry('server-overrides/config/a.toml', 'config/a.toml', 12)], 'duplicate_path'],
            'negative size' => [[self::entry('overrides/config/a.toml', 'config/a.toml', -1)], 'override_too_large'],
            'empty' => [[], 'too_many_entries'],
        ];
    }

    /** @param  array<int, OverrideEntry>  $entries */
    #[DataProvider('hostileEntries')]
    public function test_hostile_entries_are_refused_and_nothing_is_left(array $entries, string $reason): void
    {
        try {
            $this->build($entries);
            $this->fail("Built an archive from hostile entries (expected $reason)");
        } catch (UnsafePlanException $exception) {
            $this->assertSame($reason, $exception->reason);
        }

        $this->assertFileDoesNotExist($this->work);
    }

    public function test_an_override_over_the_cap_is_refused(): void
    {
        try {
            $this->build([self::entry('overrides/config/a.toml', 'config/a.toml')], new Caps(maxOverrideBytes: 3));
            $this->fail('Built an archive over the override cap');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('override_too_large', $exception->reason);
        }
    }

    public function test_a_size_other_than_the_plans_is_refused(): void
    {
        try {
            $this->build([self::entry('overrides/config/a.toml', 'config/a.toml', 3)]);
            $this->fail('Built an archive with a size the plan did not declare');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('bad_archive', $exception->reason);
        }

        $this->assertFileDoesNotExist($this->work);
    }

    public function test_server_pack_entries_are_derived_from_its_root(): void
    {
        $reader = $this->reader();

        try {
            $archive = OverrideArchive::build([new OverrideEntry('overrides/config/a.toml', 'config/a.toml', 5)], $reader, new PlanValidator(new Caps()), 'overrides/', new Caps(), $this->work);
            $this->assertSame(['config/a.toml'], $archive->paths);

            // The same entry under another root is not that target.
            $this->expectException(UnsafePlanException::class);
            OverrideArchive::build([new OverrideEntry('overrides/config/a.toml', 'config/a.toml', 5)], $reader, new PlanValidator(new Caps()), 'server-overrides/', new Caps(), $this->work);
        } finally {
            $reader->close();
        }
    }

    public function test_chunks_are_bounded_by_bytes_and_entries(): void
    {
        $entries = [
            new OverrideEntry('overrides/a', 'a', 40),
            new OverrideEntry('overrides/b', 'b', 40),
            new OverrideEntry('overrides/c', 'c', 500),
            new OverrideEntry('overrides/d', 'd', 10),
            new OverrideEntry('overrides/e', 'e', 10),
            new OverrideEntry('overrides/f', 'f', 10),
        ];

        $paths = fn (array $chunks) => array_map(fn (array $chunk) => array_map(fn (OverrideEntry $entry) => $entry->path, $chunk), $chunks);

        // A single entry over the byte limit gets an archive of its own.
        $this->assertSame([['a', 'b'], ['c'], ['d', 'e'], ['f']], $paths(OverrideArchive::chunks($entries, 100, 2)));
        $this->assertSame([['a', 'b'], ['c'], ['d', 'e', 'f']], $paths(OverrideArchive::chunks($entries, 100, 10)));
        $this->assertSame([], OverrideArchive::chunks([]));
        $this->assertCount(1, OverrideArchive::chunks(array_fill(0, 936, new OverrideEntry('overrides/x', 'x', 2000))));
    }

    public function test_an_entry_over_the_single_limit_is_a_chunk_of_its_own_whatever_the_byte_limit(): void
    {
        $entries = [
            new OverrideEntry('a', 'config/a', 10),
            new OverrideEntry('b', 'mods/big.jar', 60),
            new OverrideEntry('c', 'config/c', 10),
            new OverrideEntry('d', 'config/d', 10),
        ];

        $paths = fn (array $chunks) => array_map(fn (array $chunk) => array_map(fn (OverrideEntry $entry) => $entry->path, $chunk), $chunks);

        // Plan 31 fix: a server-pack jar over the override cap is never zipped with others,
        // even when it would fit the archive's byte limit (a cap set below CHUNK_BYTES).
        $this->assertSame([['config/a'], ['mods/big.jar'], ['config/c', 'config/d']], $paths(OverrideArchive::chunks($entries, 100, 10, 50)));
        $this->assertSame([['config/a', 'mods/big.jar', 'config/c', 'config/d']], $paths(OverrideArchive::chunks($entries, 100, 10)));
    }

    /** @return array<string, array{string, bool}> */
    public static function stagingPaths(): array
    {
        return [
            'staged' => [OverrideArchive::stagingPath(12, 0, 1), true],
            'later chunk' => ['.modpacks-plus/staging/overrides-12-3-2.zip', true],
            'other folder' => ['mods/overrides-12-0-1.zip', false],
            'download staging' => ['.modpacks-plus/staging/12/overrides-12-0-1.zip', false],
            'traversal' => ['.modpacks-plus/staging/../overrides-12-0-1.zip', false],
            'no attempt' => ['.modpacks-plus/staging/overrides-12-0-0.zip', false],
            'other extension' => ['.modpacks-plus/staging/overrides-12-0-1.tar.gz', false],
            'leading slash' => ['/.modpacks-plus/staging/overrides-12-0-1.zip', false],
            'suffix' => ['.modpacks-plus/staging/overrides-12-0-1.zip.sh', false],
        ];
    }

    #[DataProvider('stagingPaths')]
    public function test_only_staged_override_archives_are_staging_paths(string $path, bool $expected): void
    {
        $this->assertSame($expected, OverrideArchive::isStagingPath($path));
    }
}
