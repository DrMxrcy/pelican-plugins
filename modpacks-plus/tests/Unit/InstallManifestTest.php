<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Services\InstallManifest;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use PHPUnit\Framework\TestCase;

/** Plan 36 step 1: building and re-checking the installed-files manifest. */
class InstallManifestTest extends TestCase
{
    private function plan(): InstallPlan
    {
        return new InstallPlan(
            provider: 'curseforge', packId: '1', versionId: '2', packName: 'P', versionNumber: '1', minecraftVersion: '1.20.1', loader: 'fabric', loaderVersion: null,
            files: [
                new PlanFile('mods/a.jar', [], 3, ['sha1' => sha1('aaa')]),
                new PlanFile('mods/b.jar', [], 3, ['sha1' => sha1('bbb'), 'sha512' => hash('sha512', 'bbb')]),
                new PlanFile('config/shared.toml', [], 3, ['sha1' => sha1('dl!')]),
            ],
            overrides: [
                new OverrideEntry('overrides/config/shared.toml', 'config/shared.toml', 2),
                new OverrideEntry('overrides/config/x.toml', 'config/x.toml', 1),
                new OverrideEntry('overrides/ops.json', 'ops.json', 2),
            ],
        );
    }

    public function test_build_uses_the_strongest_hash_and_lets_overrides_replace_downloads(): void
    {
        $validator = new PlanValidator(new Caps());
        $hashes = [
            'config/shared.toml' => ['size' => 2, 'hash' => 'sha512:'.hash('sha512', 'ov')],
            'config/x.toml' => ['size' => 1, 'hash' => 'sha512:'.hash('sha512', 'x')],
            'ops.json' => ['size' => 2, 'hash' => 'sha512:'.hash('sha512', '[]')],
        ];

        $manifest = InstallManifest::build($this->plan(), $validator, $hashes, ['config/x.toml']);

        $this->assertSame([
            ['path' => 'config/shared.toml', 'size' => 2, 'hash' => 'sha512:'.hash('sha512', 'ov'), 'kept' => false],
            ['path' => 'config/x.toml', 'size' => 1, 'hash' => 'sha512:'.hash('sha512', 'x'), 'kept' => true],
            ['path' => 'mods/a.jar', 'size' => 3, 'hash' => 'sha1:'.sha1('aaa'), 'kept' => false],
            ['path' => 'mods/b.jar', 'size' => 3, 'hash' => 'sha512:'.hash('sha512', 'bbb'), 'kept' => false],
        ], $manifest);
    }

    public function test_split_and_match(): void
    {
        $this->assertSame(['sha1', sha1('a')], InstallManifest::splitHash('SHA1:'.strtoupper(sha1('a'))));
        $this->assertNull(InstallManifest::splitHash('md5:'.md5('a')));
        $this->assertNull(InstallManifest::splitHash('sha1:zz'));
        $this->assertNull(InstallManifest::splitHash(sha1('a')));
        $this->assertTrue(InstallManifest::matches('sha512:'.hash('sha512', 'x'), 'x'));
        $this->assertFalse(InstallManifest::matches('sha512:'.hash('sha512', 'x'), 'y'));
        $this->assertFalse(InstallManifest::matches('garbage', 'x'));
    }

    public function test_entries_drops_anything_malformed(): void
    {
        $this->assertNull(InstallManifest::entries(null));
        $this->assertNull(InstallManifest::entries('x'));
        $this->assertSame([], InstallManifest::entries([]));

        $entries = InstallManifest::entries([
            ['path' => 'mods/a.jar', 'size' => 1, 'hash' => 'sha1:'.sha1('a'), 'kept' => true],
            ['path' => '/etc/passwd', 'size' => 1, 'hash' => 'sha1:'.sha1('a')],
            ['path' => 'mods/../../x', 'size' => 1, 'hash' => 'sha1:'.sha1('a')],
            ['path' => 'mods/b.jar', 'size' => '1', 'hash' => 'sha1:'.sha1('a')],
            ['path' => 'mods/c.jar', 'size' => 1],
        ]);

        $this->assertSame(['mods/a.jar' => ['path' => 'mods/a.jar', 'size' => 1, 'hash' => 'sha1:'.sha1('a'), 'kept' => true]], $entries);
    }
}
