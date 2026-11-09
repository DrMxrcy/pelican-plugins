<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every reason and log key the code produces must exist in English, so a failed
 * install never shows a raw key. (LangFilesTest keeps the other languages in sync.)
 */
class UsedLangKeysTest extends TestCase
{
    private const SRC = __DIR__.'/../../src';

    /** @return array<string, string> file => contents */
    private function sources(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    public function test_reason_and_log_keys_exist(): void
    {
        /** @var array<string, mixed> $strings */
        $strings = require __DIR__.'/../../lang/en/strings.php';
        $missing = [];

        foreach ($this->sources() as $path => $code) {
            preg_match_all("/(?:UnsafePlanException|PackUnavailableException|StepFailedException|InstallRefusedException)\\('([a-z_]+)'/", $code, $reasons);
            preg_match_all("/->fail\\(\\\$install, '([a-z_]+)'/", $code, $failures);
            foreach (array_merge($reasons[1], $failures[1]) as $key) {
                if (!isset($strings['reasons'][$key])) {
                    $missing[] = "reasons.$key (".basename($path).')';
                }
            }

            preg_match_all("/addLog\\('[a-z]+', '([a-z_]+)'/", $code, $logs);
            foreach ($logs[1] as $key) {
                if (!isset($strings['log'][$key])) {
                    $missing[] = "log.$key (".basename($path).')';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }
}
