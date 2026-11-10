<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class PackArchiveReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'mrpack');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_a_symlink_entry_is_rejected(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->path, ZipArchive::OVERWRITE);
        $zip->addFromString('modrinth.index.json', '{}');
        $zip->addFromString('overrides/config/link', '/etc/passwd');
        // Unix mode 0120777: a symbolic link.
        $zip->setExternalAttributesName('overrides/config/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();

        try {
            PackArchiveReader::open($this->path, new PlanValidator(new Caps()), new Caps());
            $this->fail('A symlink entry was accepted');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('unsafe_path', $exception->reason);
            $this->assertStringContainsString('symlink', $exception->detail);
        }
    }

    public function test_a_regular_file_with_unix_permissions_is_accepted(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->path, ZipArchive::OVERWRITE);
        $zip->addFromString('overrides/config/a.toml', 'a');
        $zip->setExternalAttributesName('overrides/config/a.toml', ZipArchive::OPSYS_UNIX, 0100644 << 16);
        $zip->close();

        $reader = PackArchiveReader::open($this->path, new PlanValidator(new Caps()), new Caps());
        $this->assertSame('a', $reader->read('overrides/config/a.toml', 10));
        $reader->close();
    }

    public function test_an_entry_is_extracted_and_hashed_without_holding_it_and_within_the_cap(): void
    {
        $body = random_bytes(200000);
        $zip = new ZipArchive();
        $zip->open($this->path, ZipArchive::OVERWRITE);
        $zip->addFromString('mods/big.jar', $body);
        $zip->close();

        $destination = $this->path.'.out';
        $reader = PackArchiveReader::open($this->path, new PlanValidator(new Caps()), new Caps());

        try {
            $this->assertSame(hash('sha512', $body), $reader->hash('mods/big.jar', 200000, 'sha512'));

            $reader->extract('mods/big.jar', 200000, $destination);
            $this->assertSame($body, file_get_contents($destination));
            $this->assertSame(0600, fileperms($destination) & 0777);

            // Over the cap: refused, and nothing is left at the destination.
            unlink($destination);
            foreach ([fn () => $reader->extract('mods/big.jar', 199999, $destination), fn () => $reader->hash('mods/big.jar', 199999, 'sha512')] as $call) {
                try {
                    $call();
                    $this->fail('An entry over the cap was read');
                } catch (UnsafePlanException $exception) {
                    $this->assertSame('file_too_large', $exception->reason);
                }
                $this->assertFileDoesNotExist($destination);
            }
        } finally {
            $reader->close();
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }

    /**
     * A zip whose one deflated entry declares $declared bytes (local and central header) but
     * holds $actual.
     */
    private function lyingZip(int $actual, int $declared): void
    {
        $zip = new ZipArchive();
        $zip->open($this->path, ZipArchive::OVERWRITE);
        $zip->addFromString('mods/big.jar', str_repeat('A', $actual));
        $zip->setCompressionName('mods/big.jar', ZipArchive::CM_DEFLATE);
        $zip->close();

        $bytes = (string) file_get_contents($this->path);
        $local = strpos($bytes, "PK\x03\x04");
        $central = strpos($bytes, "PK\x01\x02");
        $this->assertIsInt($local);
        $this->assertIsInt($central);
        $bytes = substr_replace($bytes, pack('V', $declared), $local + 22, 4);
        $bytes = substr_replace($bytes, pack('V', $declared), $central + 24, 4);
        file_put_contents($this->path, $bytes);
    }

    /** @return array<string, array{int, int}> */
    public static function lyingSizes(): array
    {
        return [
            'more than declared (zip bomb)' => [100000, 1000],
            'truncated (less than declared)' => [1000, 100000],
        ];
    }

    #[DataProvider('lyingSizes')]
    public function test_an_entry_whose_size_lies_is_never_extracted_nor_hashed(int $actual, int $declared): void
    {
        $this->lyingZip($actual, $declared);
        $destination = $this->path.'.out';
        $reader = PackArchiveReader::open($this->path, new PlanValidator(new Caps()), new Caps());

        try {
            $this->assertSame($declared, $reader->size('mods/big.jar'));
            foreach ([
                'extract' => fn () => $reader->extract('mods/big.jar', 1024 * 1024, $destination),
                'hash' => fn () => $reader->hash('mods/big.jar', 1024 * 1024, 'sha512'),
                'read' => fn () => $reader->read('mods/big.jar', 1024 * 1024),
            ] as $name => $call) {
                try {
                    $call();
                    $this->fail("$name accepted an entry of $actual bytes declared as $declared");
                } catch (UnsafePlanException|PackUnavailableException $exception) {
                    $this->assertContains($exception->reason, ['file_too_large', 'bad_archive'], $name);
                }
                $this->assertFileDoesNotExist($destination, $name);
            }
        } finally {
            $reader->close();
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }
}
