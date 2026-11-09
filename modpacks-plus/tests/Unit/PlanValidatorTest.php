<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 30: every pack-supplied path, URL, hash and size is checked here before
 * anything is written to a server. These are the hostile inputs it must refuse.
 */
class PlanValidatorTest extends TestCase
{
    private const SHA512 = '94e950eda4a870e6565d44e14b90f16c0a381cd69304529c81f6bd408af13c515516b8417e005fc31093faa4b13de92436456dd25289ab2867c3a576fa948449';

    private const SHA1 = '0123456789abcdef0123456789abcdef01234567';

    /** @param  array<int, string>  $worlds */
    private function validator(?Caps $caps = null, array $worlds = ['world']): PlanValidator
    {
        return new PlanValidator($caps ?? new Caps(), $worlds);
    }

    /** @param  array<string, mixed>  $overrides */
    private function file(array $overrides = []): PlanFile
    {
        return PlanFile::fromArray(array_merge([
            'path' => 'mods/sodium.jar',
            'urls' => ['https://cdn.modrinth.com/data/AANobbMI/versions/abc/sodium.jar'],
            'size' => 1024,
            'hashes' => ['sha512' => self::SHA512, 'sha1' => self::SHA1],
            'side' => 'required',
        ], $overrides));
    }

    /**
     * @param  array<int, PlanFile>  $files
     * @param  array<int, OverrideEntry>  $overrides
     */
    private function plan(array $files, array $overrides = [], ?PackArchive $archive = null): InstallPlan
    {
        return new InstallPlan(
            provider: 'modrinth',
            packId: 'pack',
            versionId: 'ver',
            packName: 'Pack',
            versionNumber: '1.0.0',
            minecraftVersion: '1.20.1',
            loader: 'fabric',
            loaderVersion: '0.15.0',
            files: $files,
            archive: $archive,
            overrides: $overrides,
        );
    }

    private function assertRejected(callable $callback, string $reason): void
    {
        try {
            $callback();
        } catch (UnsafePlanException $exception) {
            $this->assertSame($reason, $exception->reason, $exception->getMessage());

            return;
        }

        $this->fail("Expected the plan to be rejected with [$reason].");
    }

    /** @return array<string, array{string}> */
    public static function hostilePaths(): array
    {
        return [
            'parent traversal' => ['../evil.jar'],
            'nested parent traversal' => ['mods/../../evil.jar'],
            'trailing parent' => ['mods/..'],
            'absolute unix path' => ['/etc/passwd'],
            'absolute windows root' => ['\\Windows\\evil.dll'],
            'drive letter' => ['C:\\evil.jar'],
            'drive letter with slash' => ['C:/evil.jar'],
            'drive letter without separator' => ['c:evil.jar'],
            'backslash traversal' => ['mods\\..\\..\\evil.jar'],
            'mixed separators' => ['mods/..\\evil.jar'],
            'encoded dots' => ['%2e%2e/evil.jar'],
            'encoded dots uppercase' => ['mods/%2E%2E/%2E%2E/evil.jar'],
            'encoded slash' => ['mods%2f..%2fevil.jar'],
            'null byte' => ["mods/evil.jar\0.txt"],
            'newline' => ["mods/evil\n.jar"],
            'current dir segment' => ['./mods/evil.jar'],
            'empty segment' => ['mods//evil.jar'],
            'empty' => [''],
            'invalid utf-8' => ["mods/\xC0\xAF.jar"],
        ];
    }

    #[DataProvider('hostilePaths')]
    public function test_rejects_hostile_path(string $path): void
    {
        $this->assertRejected(fn () => $this->validator()->normalisePath($path), 'unsafe_path');
    }

    #[DataProvider('hostilePaths')]
    public function test_a_hostile_file_path_rejects_the_whole_plan(string $path): void
    {
        $this->assertRejected(fn () => $this->validator()->validate($this->plan([$this->file(['path' => $path])])), 'unsafe_path');
    }

    public function test_accepts_ordinary_relative_paths(): void
    {
        $validator = $this->validator();

        $this->assertSame('mods/sodium-fabric-0.5.3+mc1.20.1.jar', $validator->normalisePath('mods/sodium-fabric-0.5.3+mc1.20.1.jar'));
        $this->assertSame('config/my mod/settings.json', $validator->normalisePath('config/my mod/settings.json'));
        $this->assertSame('config/..hidden', $validator->normalisePath('config/..hidden'));
        $this->assertSame('config/über.toml', $validator->normalisePath('config/über.toml'));
    }

    /** @return array<string, array{string}> */
    public static function hostileUrls(): array
    {
        return [
            'plain http' => ['http://cdn.modrinth.com/data/x/versions/y/a.jar'],
            'uppercase scheme trick' => ['HTTPS://evil.example/a.jar'],
            'ftp' => ['ftp://cdn.modrinth.com/a.jar'],
            'file scheme' => ['file:///etc/passwd'],
            'not allowlisted' => ['https://evil.example/a.jar'],
            'allowlisted name as subdomain' => ['https://cdn.modrinth.com.evil.example/a.jar'],
            'allowlisted name as parent' => ['https://evil.cdn.modrinth.com/a.jar'],
            'userinfo' => ['https://cdn.modrinth.com@evil.example/a.jar'],
            'userinfo on allowed host' => ['https://user:pass@cdn.modrinth.com/a.jar'],
            'backslash host confusion' => ['https://evil.example\\@cdn.modrinth.com/a.jar'],
            'custom port' => ['https://cdn.modrinth.com:8443/a.jar'],
            'ip address' => ['https://127.0.0.1/a.jar'],
            'whitespace' => ['https://cdn.modrinth.com/a b.jar'],
            'no host' => ['https:///a.jar'],
            'scheme relative' => ['//cdn.modrinth.com/a.jar'],
            'not a url' => ['cdn.modrinth.com/a.jar'],
            'uppercase host' => ['https://CDN.MODRINTH.COM/a.jar'],
            'trailing dot host' => ['https://cdn.modrinth.com./a.jar'],
        ];
    }

    #[DataProvider('hostileUrls')]
    public function test_rejects_hostile_download_url(string $url): void
    {
        $this->assertFalse($this->validator()->isAllowedUrl($url));
        $this->assertRejected(fn () => $this->validator()->validate($this->plan([$this->file(['urls' => [$url]])])), 'no_allowed_url');
    }

    public function test_accepts_allowlisted_https_hosts(): void
    {
        foreach ([
            'https://cdn.modrinth.com/data/AANobbMI/versions/abc/sodium%20fabric.jar',
            'https://github.com/owner/repo/releases/download/v1/a.jar',
            'https://raw.githubusercontent.com/owner/repo/main/a.jar',
            'https://objects.githubusercontent.com/github-production-release-asset/1/2?x=y&z=1',
            'https://gitlab.com/owner/repo/-/raw/main/a.jar',
        ] as $url) {
            $this->assertTrue($this->validator()->isAllowedUrl($url), $url);
        }
    }

    public function test_uses_the_first_allowlisted_url(): void
    {
        $plan = $this->validator()->validate($this->plan([$this->file(['urls' => [
            'https://evil.example/a.jar',
            'http://cdn.modrinth.com/a.jar',
            'https://github.com/o/r/releases/download/v1/a.jar',
            'https://cdn.modrinth.com/data/x/versions/y/a.jar',
        ]])]));

        $this->assertSame('https://github.com/o/r/releases/download/v1/a.jar', $plan->files[0]->url);
    }

    public function test_a_file_without_any_url_is_rejected(): void
    {
        $this->assertRejected(fn () => $this->validator()->validate($this->plan([$this->file(['urls' => []])])), 'no_allowed_url');
    }

    public function test_skips_files_the_server_does_not_support(): void
    {
        $plan = $this->validator()->validate($this->plan([
            $this->file(['path' => 'mods/server.jar']),
            $this->file(['path' => 'mods/optional.jar', 'side' => 'optional']),
            $this->file(['path' => 'mods/client-only.jar', 'side' => 'unsupported']),
        ]));

        $this->assertSame(['mods/server.jar', 'mods/optional.jar'], array_map(fn (PlanFile $file) => $file->path, $plan->files));
        $this->assertSame(['mods/client-only.jar'], $plan->skippedClientFiles);
    }

    public function test_a_client_only_file_is_skipped_even_with_a_bad_url(): void
    {
        // Never downloaded, so it cannot make the node fetch anything; still listed as skipped.
        $plan = $this->validator()->validate($this->plan([
            $this->file(['path' => 'mods/client-only.jar', 'side' => 'unsupported', 'urls' => ['https://evil.example/x.jar']]),
        ]));

        $this->assertSame([], $plan->files);
    }

    public function test_a_client_only_file_with_a_hostile_path_still_rejects_the_plan(): void
    {
        $this->assertRejected(fn () => $this->validator()->validate($this->plan([
            $this->file(['path' => '../client-only.jar', 'side' => 'unsupported']),
        ])), 'unsafe_path');
    }

    public function test_hash_mismatch_is_rejected(): void
    {
        $file = $this->file(['hashes' => ['sha512' => hash('sha512', 'the real jar'), 'sha1' => sha1('the real jar')]]);

        PlanValidator::assertContentMatches($file, 'the real jar');

        $this->assertRejected(fn () => PlanValidator::assertContentMatches($file, 'a tampered jar'), 'hash_mismatch');
    }

    public function test_sha512_wins_over_sha1(): void
    {
        // A matching sha1 must not rescue a wrong sha512.
        $file = $this->file(['hashes' => ['sha512' => hash('sha512', 'other'), 'sha1' => sha1('content')]]);

        $this->assertRejected(fn () => PlanValidator::assertContentMatches($file, 'content'), 'hash_mismatch');
    }

    public function test_sha1_is_used_when_there_is_no_sha512(): void
    {
        $file = $this->file(['hashes' => ['sha1' => sha1('content')]]);

        PlanValidator::assertContentMatches($file, 'content');
        PlanValidator::assertDigestMatches($file->hashes, 'sha1', sha1('content'));

        $this->assertRejected(fn () => PlanValidator::assertContentMatches($file, 'other'), 'hash_mismatch');
    }

    public function test_digest_for_another_algorithm_is_not_accepted(): void
    {
        // Comparing a sha1 digest when a sha512 is known would weaken the check.
        $file = $this->file(['hashes' => ['sha512' => hash('sha512', 'content'), 'sha1' => sha1('content')]]);

        $this->assertRejected(fn () => PlanValidator::assertDigestMatches($file->hashes, 'sha1', sha1('content')), 'hash_mismatch');
    }

    /** @return array<string, array{array<string, string>}> */
    public static function badHashes(): array
    {
        return [
            'no hashes' => [[]],
            'malformed sha512' => [['sha512' => 'zz']],
            'malformed sha1' => [['sha1' => 'not-a-hash']],
            'only md5' => [['md5' => 'd41d8cd98f00b204e9800998ecf8427e']],
        ];
    }

    /** @param  array<string, string>  $hashes */
    #[DataProvider('badHashes')]
    public function test_a_file_without_a_usable_hash_is_rejected(array $hashes): void
    {
        $this->assertRejected(fn () => $this->validator()->validate($this->plan([$this->file(['hashes' => $hashes])])), 'bad_hash');
    }

    public function test_too_many_files_is_rejected(): void
    {
        $files = [];
        for ($i = 0; $i < 4; $i++) {
            $files[] = $this->file(['path' => "mods/m$i.jar"]);
        }

        $this->validator(new Caps(maxFiles: 4))->validate($this->plan($files));
        $this->assertRejected(fn () => $this->validator(new Caps(maxFiles: 3))->validate($this->plan($files)), 'too_many_files');
    }

    public function test_client_only_files_do_not_count_towards_the_file_cap(): void
    {
        $plan = $this->validator(new Caps(maxFiles: 1))->validate($this->plan([
            $this->file(['path' => 'mods/a.jar']),
            $this->file(['path' => 'mods/b.jar', 'side' => 'unsupported']),
        ]));

        $this->assertCount(1, $plan->files);
    }

    public function test_a_file_over_the_single_file_cap_is_rejected(): void
    {
        $this->assertRejected(fn () => $this->validator(new Caps(maxFileBytes: 1000))->validate($this->plan([$this->file(['size' => 1001])])), 'file_too_large');
    }

    public function test_a_negative_size_is_rejected(): void
    {
        $this->assertRejected(fn () => $this->validator()->validate($this->plan([$this->file(['size' => -1])])), 'file_too_large');
    }

    public function test_a_pack_over_the_total_cap_is_rejected(): void
    {
        $caps = new Caps(maxFileBytes: 1000, maxTotalBytes: 2500);
        $files = [
            $this->file(['path' => 'mods/a.jar', 'size' => 1000]),
            $this->file(['path' => 'mods/b.jar', 'size' => 1000]),
        ];

        $this->validator($caps)->validate($this->plan($files, [new OverrideEntry('overrides/config/a.toml', 'config/a.toml', 500)]));
        $this->assertRejected(
            fn () => $this->validator($caps)->validate($this->plan($files, [new OverrideEntry('overrides/config/a.toml', 'config/a.toml', 501)])),
            'total_too_large',
        );
    }

    public function test_an_archive_over_the_archive_cap_is_rejected(): void
    {
        $archive = new PackArchive('https://cdn.modrinth.com/data/x/versions/y/pack.mrpack', 'pack.mrpack', 2000, ['sha512' => self::SHA512]);

        $this->assertRejected(fn () => $this->validator(new Caps(maxArchiveBytes: 1999))->validate($this->plan([], [], $archive)), 'archive_too_large');
    }

    public function test_an_archive_from_a_non_allowlisted_host_is_rejected(): void
    {
        $archive = new PackArchive('https://evil.example/pack.mrpack', 'pack.mrpack', 10, ['sha512' => self::SHA512]);

        $this->assertRejected(fn () => $this->validator()->validate($this->plan([], [], $archive)), 'no_allowed_url');
    }

    public function test_an_override_over_the_single_file_cap_is_rejected(): void
    {
        $this->assertRejected(
            fn () => $this->validator(new Caps(maxFileBytes: 10))->validate($this->plan([], [new OverrideEntry('overrides/big.bin', 'big.bin', 11)])),
            'file_too_large',
        );
    }

    public function test_a_panel_fetched_file_over_the_panel_fetch_cap_is_rejected(): void
    {
        $caps = new Caps(maxPanelFetchBytes: 100);

        $this->validator($caps)->validate($this->plan([$this->file(['size' => 5000, 'verify' => PlanFile::VERIFY_MODRINTH])]));
        $this->assertRejected(
            fn () => $this->validator($caps)->validate($this->plan([$this->file(['size' => 5000, 'verify' => PlanFile::VERIFY_PANEL])])),
            'panel_fetch_too_large',
        );
    }

    public function test_an_override_over_the_override_cap_is_rejected(): void
    {
        $caps = new Caps(maxOverrideBytes: 1000);

        $this->validator($caps)->validate($this->plan([], [new OverrideEntry('overrides/config/a.toml', 'config/a.toml', 1000)]));
        $this->assertRejected(
            fn () => $this->validator($caps)->validate($this->plan([], [new OverrideEntry('overrides/config/a.toml', 'config/a.toml', 1001)])),
            'override_too_large',
        );
    }

    /** @return array<string, array{string}> */
    public static function clientOnlyOverrides(): array
    {
        return [
            'resource pack' => ['resourcepacks/Faithful.zip'],
            'shader pack' => ['shaderpacks/Complementary.zip'],
            'screenshot' => ['screenshots/2026-01-01.png'],
            'options' => ['options.txt'],
            'optifine options' => ['optionsof.txt'],
            'shader options' => ['optionsshaders.txt'],
        ];
    }

    #[DataProvider('clientOnlyOverrides')]
    public function test_client_only_overrides_are_skipped_whatever_their_size(string $path): void
    {
        $plan = $this->validator(new Caps(maxOverrideBytes: 10, maxFileBytes: 100))->validate($this->plan([], [
            new OverrideEntry("overrides/$path", $path, 5000),
            new OverrideEntry('overrides/config/a.toml', 'config/a.toml', 5),
        ]));

        $this->assertSame(['config/a.toml'], array_map(fn (OverrideEntry $entry) => $entry->path, $plan->overrides));
        $this->assertSame([$path], $plan->skippedClientOverrides);
        $this->assertSame([], $plan->skippedOverrides);
        // Skipped entries are never written, so they don't count towards the total either.
        $this->assertSame(5, $plan->totalBytes());

        $again = $this->validator(new Caps(maxOverrideBytes: 10, maxFileBytes: 100))->validate($plan);
        $this->assertSame($plan->toArray(), $again->toArray());
    }

    public function test_an_oversized_override_elsewhere_still_rejects_the_pack(): void
    {
        foreach (['config/big.json', 'mods/resourcepacks/x.zip', 'resourcepacksx/a.zip', 'kubejs/options.txt'] as $path) {
            $this->assertRejected(
                fn () => $this->validator(new Caps(maxOverrideBytes: 10))->validate($this->plan([], [new OverrideEntry("overrides/$path", $path, 11)])),
                'override_too_large',
            );
        }
    }

    public function test_client_only_skipping_never_relaxes_path_checks(): void
    {
        $this->assertRejected(
            fn () => $this->validator()->validate($this->plan([], [new OverrideEntry('overrides/resourcepacks/../../x', 'resourcepacks/../../x', 1)])),
            'unsafe_path',
        );
        $this->assertRejected(
            fn () => $this->validator()->validate($this->plan([], [new OverrideEntry('overrides/resourcepacks/a.zip', '../resourcepacks/a.zip', 1)])),
            'unsafe_path',
        );
    }

    public function test_only_cdn_files_may_be_marked_for_a_node_pull(): void
    {
        $this->assertRejected(
            fn () => $this->validator()->validate($this->plan([$this->file([
                'urls' => ['https://github.com/o/r/releases/download/v1/a.jar'],
                'verify' => PlanFile::VERIFY_MODRINTH,
            ])])),
            'no_allowed_url',
        );
    }

    public function test_too_many_archive_entries_is_rejected(): void
    {
        $names = ['modrinth.index.json', 'overrides/a.txt', 'overrides/b.txt'];

        $this->validator(new Caps(maxArchiveEntries: 3))->checkArchiveEntries($names);
        $this->assertRejected(fn () => $this->validator(new Caps(maxArchiveEntries: 2))->checkArchiveEntries($names), 'too_many_entries');
    }

    /** @return array<string, array{string}> */
    public static function zipSlipEntries(): array
    {
        return [
            'overrides parent' => ['overrides/../evil'],
            'server overrides parent' => ['server-overrides/../../evil'],
            'client overrides parent' => ['client-overrides/../evil'],
            'absolute' => ['/overrides/evil'],
            'backslash' => ['overrides\\..\\evil'],
            'drive' => ['C:/evil'],
            'encoded' => ['overrides/%2e%2e/evil'],
        ];
    }

    #[DataProvider('zipSlipEntries')]
    public function test_zip_slip_entries_are_rejected(string $entry): void
    {
        $this->assertRejected(fn () => $this->validator()->checkArchiveEntries(['modrinth.index.json', $entry]), 'unsafe_path');
        $this->assertRejected(fn () => $this->validator()->overrideTarget($entry), 'unsafe_path');
    }

    public function test_override_targets(): void
    {
        $validator = $this->validator();

        $this->assertSame('config/a.toml', $validator->overrideTarget('overrides/config/a.toml'));
        $this->assertSame('config/a.toml', $validator->overrideTarget('server-overrides/config/a.toml'));
        $this->assertNull($validator->overrideTarget('client-overrides/options.txt'));
        $this->assertNull($validator->overrideTarget('modrinth.index.json'));
        $this->assertNull($validator->overrideTarget('overrides/'));
        $this->assertNull($validator->overrideTarget('overrides/config/'));
        $this->assertNull($validator->overrideTarget('overridesfoo/a.txt'));
    }

    /** @return array<string, array{string}> */
    public static function protectedPaths(): array
    {
        return [
            'ops' => ['ops.json'],
            'whitelist' => ['whitelist.json'],
            'banned players' => ['banned-players.json'],
            'banned ips' => ['banned-ips.json'],
            'usercache' => ['usercache.json'],
            'server properties' => ['server.properties'],
            'world' => ['world/level.dat'],
            'world case' => ['World/region/r.0.0.mca'],
            'nether' => ['world_nether/DIM-1/x'],
            'end' => ['world_the_end/DIM1/x'],
            'custom level name' => ['survival/level.dat'],
            'custom level nether' => ['survival_nether/x'],
            'own folder' => ['.modpacks-plus/previous-x/mods/a.jar'],
        ];
    }

    #[DataProvider('protectedPaths')]
    public function test_a_file_aimed_at_a_protected_path_rejects_the_plan(string $path): void
    {
        $validator = $this->validator(worlds: ['world', 'survival']);

        $this->assertTrue($validator->isProtected($path));
        $this->assertRejected(fn () => $validator->validate($this->plan([$this->file(['path' => $path])])), 'protected_path');
    }

    #[DataProvider('protectedPaths')]
    public function test_an_override_aimed_at_a_protected_path_is_skipped(string $path): void
    {
        $plan = $this->validator(worlds: ['world', 'survival'])->validate($this->plan([], [
            new OverrideEntry("overrides/$path", $path, 10),
            new OverrideEntry('overrides/config/ok.toml', 'config/ok.toml', 10),
        ]));

        $this->assertSame(['config/ok.toml'], array_map(fn (OverrideEntry $entry) => $entry->path, $plan->overrides));
        $this->assertSame([$path], $plan->skippedOverrides);
    }

    public function test_a_nested_level_name_protects_the_world_its_dimensions_and_its_parents(): void
    {
        $validator = $this->validator(worlds: ['saves/world']);

        $this->assertTrue($validator->isProtected('saves/world'));
        $this->assertTrue($validator->isProtected('saves/world/level.dat'));
        $this->assertTrue($validator->isProtected('Saves/World/region/r.0.0.mca'));
        $this->assertTrue($validator->isProtected('saves/world_nether/DIM-1/x'));
        $this->assertTrue($validator->isProtected('saves/world_the_end/DIM1/x'));
        // Moving or replacing a parent folder would take the world with it.
        $this->assertTrue($validator->isProtected('saves'));
        // ...but only paths at or inside the world count as "within" it (creating `saves` is harmless).
        $this->assertFalse($validator->isWithinProtected('saves'));
        $this->assertTrue($validator->isWithinProtected('saves/world/region'));
        $this->assertFalse($validator->isProtected('saves/other/x'));
        $this->assertFalse($validator->isProtected('savesx/world'));

        $this->assertRejected(fn () => $validator->validate($this->plan([$this->file(['path' => 'saves/world/level.dat'])])), 'protected_path');
        $this->assertRejected(fn () => $validator->validate($this->plan([$this->file(['path' => 'saves'])])), 'protected_path');

        $plan = $validator->validate($this->plan([], [new OverrideEntry('overrides/saves/world/datapacks/x.zip', 'saves/world/datapacks/x.zip', 1)]));
        $this->assertSame([], $plan->overrides);
        $this->assertSame(['saves/world/datapacks/x.zip'], $plan->skippedOverrides);
    }

    public function test_a_world_inside_a_move_aside_folder_protects_that_folder(): void
    {
        // level-name=config/world: moving config/ aside would move the world.
        $this->assertTrue($this->validator(worlds: ['config/world'])->isProtected('config'));
        $this->assertFalse($this->validator(worlds: ['config/world'])->isProtected('config/sodium.toml'));
    }

    /** @return array<string, array{string}> */
    public static function unsafeLevelNames(): array
    {
        return [
            'empty' => [''],
            'parent traversal' => ['../world'],
            'nested traversal' => ['saves/../../world'],
            'dot' => ['.'],
            'absolute' => ['/srv/world'],
            'backslash' => ['saves\\world'],
            'drive letter' => ['C:/world'],
            'encoded' => ['%2e%2e/world'],
            'trailing slash' => ['world/'],
        ];
    }

    #[DataProvider('unsafeLevelNames')]
    public function test_an_unsafe_level_name_fails_closed(string $levelName): void
    {
        $this->assertRejected(fn () => $this->validator(worlds: [$levelName]), 'unsafe_level_name');
    }

    public function test_nested_files_named_like_protected_ones_are_allowed(): void
    {
        $validator = $this->validator();

        $this->assertFalse($validator->isProtected('config/ops.json'));
        $this->assertFalse($validator->isProtected('mods/world.jar'));
        $this->assertFalse($validator->isProtected('worldedit/config.yml'));
    }

    public function test_an_override_with_a_hostile_target_rejects_the_plan(): void
    {
        $this->assertRejected(
            fn () => $this->validator()->validate($this->plan([], [new OverrideEntry('overrides/x', '../x', 1)])),
            'unsafe_path',
        );
    }

    public function test_two_files_with_the_same_path_are_rejected(): void
    {
        $this->assertRejected(fn () => $this->validator()->validate($this->plan([
            $this->file(['path' => 'mods/a.jar']),
            $this->file(['path' => 'mods/a.jar']),
        ])), 'duplicate_path');
    }

    public function test_validation_is_idempotent_and_round_trips(): void
    {
        $validator = $this->validator();
        $plan = $validator->validate($this->plan(
            [$this->file(), $this->file(['path' => 'mods/client.jar', 'side' => 'unsupported'])],
            [new OverrideEntry('overrides/config/a.toml', 'config/a.toml', 5), new OverrideEntry('overrides/ops.json', 'ops.json', 5)],
            new PackArchive('https://cdn.modrinth.com/data/x/versions/y/pack.mrpack', 'pack.mrpack', 10, ['sha512' => self::SHA512]),
        ));

        $again = $validator->validate(InstallPlan::fromArray(json_decode((string) json_encode($plan->toArray()), true)));

        $this->assertEquals($plan->toArray(), $again->toArray());
    }

    public function test_resolves_under_root(): void
    {
        $this->assertTrue(PlanValidator::resolvesUnderRoot('mods/a.jar'));
        $this->assertFalse(PlanValidator::resolvesUnderRoot('mods/../../a.jar'));
        $this->assertFalse(PlanValidator::resolvesUnderRoot('..'));
        $this->assertFalse(PlanValidator::resolvesUnderRoot('/a'));
        $this->assertFalse(PlanValidator::resolvesUnderRoot('.'));
    }
}
