<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PackArchiveReader;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
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
}
