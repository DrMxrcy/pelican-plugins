<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 41: what a pasted CurseForge link or Project ID turns into. Only an integer id or a strict
 * lowercase slug ever comes out; everything else is null (and nothing is requested for it).
 */
class CurseForgePackLinkTest extends TestCase
{
    /** @return array<string, array{string, array{id: string}|array{slug: string}}> */
    public static function accepted(): array
    {
        return [
            'bare id' => ['123456', ['id' => '123456']],
            'bare id with spaces' => ["  925200\n", ['id' => '925200']],
            'largest id' => ['2147483647', ['id' => '2147483647']],
            'modpack link' => ['https://www.curseforge.com/minecraft/modpacks/all-the-mods-10', ['slug' => 'all-the-mods-10']],
            'trailing slash' => ['https://www.curseforge.com/minecraft/modpacks/all-the-mods-10/', ['slug' => 'all-the-mods-10']],
            'file link' => ['https://www.curseforge.com/minecraft/modpacks/all-the-mods-10/files/6271245', ['slug' => 'all-the-mods-10']],
            'files tab' => ['https://www.curseforge.com/minecraft/modpacks/atm10/files/all', ['slug' => 'atm10']],
            'download link' => ['https://www.curseforge.com/minecraft/modpacks/atm10/download/6271245', ['slug' => 'atm10']],
            'query and fragment' => ['https://www.curseforge.com/minecraft/modpacks/atm10?page=2#c', ['slug' => 'atm10']],
            'no scheme' => ['www.curseforge.com/minecraft/modpacks/atm10', ['slug' => 'atm10']],
            'no www' => ['https://curseforge.com/minecraft/modpacks/atm10', ['slug' => 'atm10']],
            'legacy host' => ['https://legacy.curseforge.com/minecraft/modpacks/atm10', ['slug' => 'atm10']],
            'upper case' => ['HTTPS://WWW.CURSEFORGE.COM/minecraft/modpacks/ATM10', ['slug' => 'atm10']],
            'underscore slug' => ['https://www.curseforge.com/minecraft/modpacks/my_pack', ['slug' => 'my_pack']],
            'project link' => ['https://www.curseforge.com/projects/925200', ['id' => '925200']],
        ];
    }

    /** @param  array{id: string}|array{slug: string}  $expected */
    #[DataProvider('accepted')]
    public function test_accepted(string $input, array $expected): void
    {
        $this->assertSame($expected, CurseForgePackLink::parse($input));
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'empty' => [''],
            'blank' => ["   \t"],
            'zero' => ['0'],
            'negative' => ['-5'],
            'leading zero' => ['0123'],
            'too large' => ['2147483648'],
            'huge' => [str_repeat('9', 40)],
            'float' => ['12.5'],
            'exponent' => ['1e5'],
            'hex' => ['0x1f'],
            'id with text' => ['123abc'],
            'full-width digits' => ['１２３'],
            'arabic digits' => ['١٢٣'],
            'other host' => ['https://evil.example/minecraft/modpacks/atm10'],
            'curseforge lookalike' => ['https://www.curseforge.com.evil.example/minecraft/modpacks/atm10'],
            'curseforge as path' => ['https://evil.example/www.curseforge.com/minecraft/modpacks/atm10'],
            'subdomain' => ['https://api.curseforge.com/minecraft/modpacks/atm10'],
            'credentials' => ['https://user:pass@www.curseforge.com/minecraft/modpacks/atm10'],
            'at sign' => ['https://www.curseforge.com@evil.example/minecraft/modpacks/atm10'],
            'port' => ['https://www.curseforge.com:8443/minecraft/modpacks/atm10'],
            'protocol relative' => ['//www.curseforge.com/minecraft/modpacks/atm10'],
            'ftp' => ['ftp://www.curseforge.com/minecraft/modpacks/atm10'],
            'javascript' => ['javascript:alert(1)'],
            'javascript with link' => ['javascript://www.curseforge.com/minecraft/modpacks/atm10'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'file' => ['file:///etc/passwd'],
            'backslash' => ['https://www.curseforge.com\\minecraft\\modpacks\\atm10'],
            'mod link' => ['https://www.curseforge.com/minecraft/mc-mods/jei'],
            'resource pack' => ['https://www.curseforge.com/minecraft/texture-packs/faithful'],
            'other game' => ['https://www.curseforge.com/wow/addons/details'],
            'other game modpacks' => ['https://www.curseforge.com/ark/modpacks/something'],
            'no slug' => ['https://www.curseforge.com/minecraft/modpacks/'],
            'dot slug' => ['https://www.curseforge.com/minecraft/modpacks/..'],
            'traversal' => ['https://www.curseforge.com/minecraft/modpacks/../../mods/1'],
            'percent encoded' => ['https://www.curseforge.com/minecraft/modpacks/atm%2F10'],
            'unicode slug' => ['https://www.curseforge.com/minecraft/modpacks/pаck'],
            'unicode host' => ['https://www.cursefοrge.com/minecraft/modpacks/atm10'],
            'space inside' => ['https://www.curseforge.com/minecraft/modpacks/atm 10'],
            'newline inside' => ["https://www.curseforge.com/minecraft/modpacks/atm10\nhttps://evil.example"],
            'null byte' => ["123\0456"],
            'slug starting with dash' => ['https://www.curseforge.com/minecraft/modpacks/-atm'],
            'long slug' => ['https://www.curseforge.com/minecraft/modpacks/'.str_repeat('a', 101)],
            'too long' => ['https://www.curseforge.com/minecraft/modpacks/atm10?'.str_repeat('a', 600)],
            'project link huge id' => ['https://www.curseforge.com/projects/99999999999'],
            'project link zero' => ['https://www.curseforge.com/projects/0'],
            'project link negative' => ['https://www.curseforge.com/projects/-1'],
            'too deep' => ['https://www.curseforge.com/minecraft/modpacks/atm10/a/b/c/d'],
            'html' => ['<a href="https://www.curseforge.com/minecraft/modpacks/atm10">x</a>'],
        ];
    }

    #[DataProvider('refused')]
    public function test_refused(string $input): void
    {
        $this->assertNull(CurseForgePackLink::parse($input));
    }
}
