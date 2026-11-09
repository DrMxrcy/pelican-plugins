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
}
