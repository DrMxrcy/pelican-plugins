<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\OverrideEntry;
use DrMxrcy\ModpacksPlus\Data\PackArchive;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Services\InstallEngine;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 31 step 3: download hosts per provider, server-pack override rules and the
 * "download manually" list. None of item 30's rules is relaxed.
 */
class CurseForgePlanValidatorTest extends TestCase
{
    private const SHA1 = '0123456789abcdef0123456789abcdef01234567';

    private function validator(string $provider = 'curseforge', ?Caps $caps = null): PlanValidator
    {
        return new PlanValidator($caps ?? new Caps(), ['world'], PlanValidator::hostsFor($provider));
    }

    private function file(string $url, string $path = 'mods/a.jar'): PlanFile
    {
        return PlanFile::fromArray(['path' => $path, 'urls' => [$url], 'size' => 10, 'hashes' => ['sha1' => self::SHA1], 'side' => 'unknown']);
    }

    /** @param  array<string, mixed>  $changes */
    private function plan(array $changes = [], string $provider = 'curseforge'): InstallPlan
    {
        return (new InstallPlan($provider, '1', '2', 'Pack', '1.0', '1.20.1', 'forge', '47.2.0'))->with($changes);
    }

    private function assertUnsafe(callable $callback, string $reason): void
    {
        try {
            $callback();
        } catch (UnsafePlanException $exception) {
            $this->assertSame($reason, $exception->reason, $exception->getMessage());

            return;
        }

        $this->fail("Expected UnsafePlanException [$reason]");
    }

    public function test_hosts_are_per_provider(): void
    {
        $this->assertSame(['cdn.modrinth.com', 'edge.forgecdn.net', 'mediafilez.forgecdn.net'], PlanValidator::hostsFor('curseforge'));
        $this->assertSame(PlanValidator::ALLOWED_HOSTS, PlanValidator::hostsFor('modrinth'));
        // Plan 32: FTB adds its own host; a provider without hosts gets none.
        $this->assertSame(['cdn.modrinth.com', 'dist.modpacks.ch', 'edge.forgecdn.net', 'mediafilez.forgecdn.net'], PlanValidator::hostsFor('ftb'));
        $this->assertSame([], PlanValidator::hostsFor('technic'));

        $curseforge = $this->validator('curseforge');
        $modrinth = $this->validator('modrinth');
        $unknown = $this->validator('unknown');

        $forge = 'https://edge.forgecdn.net/files/1/2/a.jar';
        $this->assertTrue($curseforge->isAllowedUrl($forge));
        $this->assertTrue($curseforge->isAllowedUrl('https://mediafilez.forgecdn.net/files/1/2/a.jar'));
        $this->assertTrue($curseforge->isAllowedUrl('https://cdn.modrinth.com/data/x/versions/y/a.jar'));
        $this->assertFalse($curseforge->isAllowedUrl('https://github.com/o/r/releases/download/v1/a.jar'));
        $this->assertFalse($curseforge->isAllowedUrl('http://edge.forgecdn.net/files/1/2/a.jar'));
        $this->assertFalse($curseforge->isAllowedUrl('https://www.curseforge.com/minecraft/mc-mods/x/download/1'));
        $this->assertFalse($curseforge->isAllowedUrl('https://media.forgecdn.net/files/1/2/a.jar'));

        // A Modrinth pack can't point the panel at the CurseForge CDN, nor an unknown provider anywhere.
        $this->assertFalse($modrinth->isAllowedUrl($forge));
        $this->assertFalse($unknown->isAllowedUrl($forge));
        $this->assertFalse($unknown->isAllowedUrl('https://cdn.modrinth.com/data/x/versions/y/a.jar'));

        $this->assertUnsafe(fn () => $modrinth->validate($this->plan(['files' => [$this->file($forge)]], 'modrinth')), 'no_allowed_url');
        $this->assertSame($forge, $curseforge->validate($this->plan(['files' => [$this->file($forge)]]))->files[0]->url);
    }

    public function test_forgecdn_files_are_never_marked_for_a_node_pull(): void
    {
        $file = $this->file('https://edge.forgecdn.net/files/1/2/a.jar')->with(['verify' => PlanFile::VERIFY_MODRINTH]);

        $this->assertUnsafe(fn () => $this->validator()->validate($this->plan(['files' => [$file]])), 'no_allowed_url');
    }

    public function test_md5_alone_is_not_a_usable_hash(): void
    {
        $file = PlanFile::fromArray(['path' => 'mods/a.jar', 'urls' => ['https://edge.forgecdn.net/files/1/2/a.jar'], 'size' => 10, 'hashes' => ['md5' => md5('x')], 'side' => 'unknown']);

        $this->assertUnsafe(fn () => $this->validator()->validate($this->plan(['files' => [$file]])), 'bad_hash');
    }

    private function serverPack(): PackArchive
    {
        return new PackArchive('https://edge.forgecdn.net/files/4000/2/Server.zip', 'Server.zip', 1000, ['sha1' => self::SHA1]);
    }

    public function test_server_pack_entries_map_from_their_root(): void
    {
        $validator = $this->validator();

        $this->assertSame('mods/a.jar', $validator->overrideTarget('Pack-Server/mods/a.jar', 'Pack-Server/'));
        $this->assertNull($validator->overrideTarget('Other/mods/a.jar', 'Pack-Server/'));
        $this->assertNull($validator->overrideTarget('Pack-Server/', 'Pack-Server/'));
        $this->assertSame('mods/a.jar', $validator->overrideTarget('mods/a.jar', ''));
        // The overrides/ convention of client packs does not apply to a server pack root.
        $this->assertSame('overrides/a.txt', $validator->overrideTarget('overrides/a.txt', ''));

        foreach (['../', 'a/b/', 'a', '/', './', '%2e%2e/'] as $root) {
            $this->assertUnsafe(fn () => $validator->overrideTarget('a/mods/x.jar', $root), 'unsafe_path');
        }
        $this->assertUnsafe(fn () => $validator->overrideTarget('Pack-Server/../../etc/passwd', 'Pack-Server/'), 'unsafe_path');
    }

    public function test_server_pack_plans_skip_launchers_and_protected_files(): void
    {
        $entries = [
            'Pack-Server/mods/a.jar' => 'mods/a.jar',
            'Pack-Server/config/c.toml' => 'config/c.toml',
            'Pack-Server/startserver.sh' => 'startserver.sh',
            'Pack-Server/start.bat' => 'start.bat',
            'Pack-Server/forge-1.20.1-47.2.0-installer.jar' => 'forge-1.20.1-47.2.0-installer.jar',
            'Pack-Server/libraries/net/x.jar' => 'libraries/net/x.jar',
            'Pack-Server/eula.txt' => 'eula.txt',
            'Pack-Server/user_jvm_args.txt' => 'user_jvm_args.txt',
            'Pack-Server/server.properties' => 'server.properties',
            'Pack-Server/world/level.dat' => 'world/level.dat',
            'Pack-Server/mods/.modrinth-metadata.json' => 'mods/.modrinth-metadata.json',
            'Pack-Server/resourcepacks/r.zip' => 'resourcepacks/r.zip',
            'Pack-Server/config/start.sh' => 'config/start.sh',
        ];
        $overrides = [];
        foreach ($entries as $entry => $path) {
            $overrides[] = new OverrideEntry($entry, $path, 5);
        }

        $plan = $this->validator()->validate($this->plan(['serverPack' => $this->serverPack(), 'serverPackRoot' => 'Pack-Server/', 'overrides' => $overrides]));

        $this->assertSame(['mods/a.jar', 'config/c.toml', 'config/start.sh'], array_map(fn (OverrideEntry $entry) => $entry->path, $plan->overrides));
        $this->assertSame(['startserver.sh', 'start.bat', 'forge-1.20.1-47.2.0-installer.jar', 'libraries/net/x.jar', 'eula.txt', 'user_jvm_args.txt'], $plan->skippedPackFiles);
        $this->assertSame(['server.properties', 'world/level.dat', 'mods/.modrinth-metadata.json'], $plan->skippedOverrides);
        $this->assertSame(['resourcepacks/r.zip'], $plan->skippedClientOverrides);

        // Idempotent and round-trips through the stored record.
        $this->assertEquals($plan, $this->validator()->validate(InstallPlan::fromArray($plan->toArray())));
    }

    public function test_a_server_pack_entry_whose_target_does_not_match_its_root_is_rejected(): void
    {
        $plan = $this->plan([
            'serverPack' => $this->serverPack(),
            'serverPackRoot' => 'Pack-Server/',
            'overrides' => [new OverrideEntry('Pack-Server/mods/a.jar', 'config/evil.toml', 5)],
        ]);

        $this->assertUnsafe(fn () => $this->validator()->validate($plan), 'unsafe_path');
    }

    public function test_a_tampered_server_pack_root_is_rejected(): void
    {
        $plan = $this->plan(['serverPack' => $this->serverPack(), 'serverPackRoot' => '../']);

        $this->assertUnsafe(fn () => $this->validator()->validate($plan), 'unsafe_path');
    }

    public function test_a_server_pack_override_still_obeys_the_override_cap(): void
    {
        $plan = $this->plan([
            'serverPack' => $this->serverPack(),
            'serverPackRoot' => '',
            'overrides' => [new OverrideEntry('mods/big.jar', 'mods/big.jar', 2048)],
        ]);

        $this->assertUnsafe(fn () => $this->validator('curseforge', new Caps(maxOverrideBytes: 1024))->validate($plan), 'override_too_large');
    }

    /** @return array<string, array{array<string, mixed>, array{name: string, url: ?string, reason: string}|null}> */
    public static function manualEntries(): array
    {
        return [
            'good' => [
                ['name' => 'OptiFine', 'url' => 'https://www.curseforge.com/minecraft/mc-mods/optifine/files/1', 'reason' => 'distribution_disabled'],
                ['name' => 'OptiFine', 'url' => 'https://www.curseforge.com/minecraft/mc-mods/optifine/files/1', 'reason' => 'distribution_disabled'],
            ],
            'javascript link' => [
                ['name' => 'X', 'url' => 'javascript:alert(1)', 'reason' => 'datapack'],
                ['name' => 'X', 'url' => null, 'reason' => 'datapack'],
            ],
            'other host' => [
                ['name' => 'X', 'url' => 'https://www.curseforge.com.evil.example/minecraft/x', 'reason' => 'datapack'],
                ['name' => 'X', 'url' => null, 'reason' => 'datapack'],
            ],
            'attribute break-out' => [
                ['name' => 'X', 'url' => 'https://www.curseforge.com/minecraft/x" onclick="alert(1)', 'reason' => 'datapack'],
                ['name' => 'X', 'url' => null, 'reason' => 'datapack'],
            ],
            'parent path' => [
                ['name' => 'X', 'url' => 'https://www.curseforge.com/minecraft/../linkout', 'reason' => 'datapack'],
                ['name' => 'X', 'url' => null, 'reason' => 'datapack'],
            ],
            'control characters' => [
                ['name' => "A\nB\x00", 'url' => null, 'reason' => 'unsupported_type'],
                ['name' => 'A?B?', 'url' => null, 'reason' => 'unsupported_type'],
            ],
            'unknown reason' => [['name' => 'X', 'url' => null, 'reason' => 'whatever'], null],
            'no name' => [['name' => '', 'url' => null, 'reason' => 'datapack'], null],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{name: string, url: ?string, reason: string}|null  $expected
     */
    #[DataProvider('manualEntries')]
    public function test_manual_entries_are_checked(array $entry, ?array $expected): void
    {
        $this->assertSame($expected, PlanValidator::manualFile($entry));

        $plan = InstallPlan::fromArray(['provider' => 'curseforge', 'manual_files' => [$entry]]);
        $this->assertSame($expected === null ? [] : [$expected], $plan->manualFiles);
    }

    public function test_manual_files_count_towards_the_file_cap(): void
    {
        $manual = array_fill(0, 3, ['name' => 'X', 'url' => null, 'reason' => 'distribution_disabled']);
        $plan = $this->plan(['files' => [$this->file('https://edge.forgecdn.net/files/1/2/a.jar')], 'manualFiles' => $manual]);

        $this->assertUnsafe(fn () => $this->validator('curseforge', new Caps(maxFiles: 3))->validate($plan), 'too_many_files');
    }

    /** @return array<string, array{string, bool}> */
    public static function launcherPaths(): array
    {
        return [
            'start script' => ['startserver.sh', true],
            'powershell' => ['Start.PS1', true],
            'root jar' => ['server.jar', true],
            'eula' => ['eula.txt', true],
            'libraries' => ['libraries/a/b.jar', true],
            'bundled java' => ['jre/bin/java', true],
            'dotfile' => ['.env', true],
            'dot folder' => ['.git/config', true],
            'python' => ['run.py', true],
            'node' => ['start.js', true],
            'perl' => ['x.pl', true],
            'ruby' => ['x.rb', true],
            'php' => ['index.php', true],
            'kubejs script' => ['kubejs/server_scripts/x.js', false],
            'mod jar' => ['mods/a.jar', false],
            'nested script' => ['config/start.sh', false],
            'readme' => ['README.txt', false],
        ];
    }

    #[DataProvider('launcherPaths')]
    public function test_launcher_files(string $path, bool $launcher): void
    {
        $this->assertSame($launcher, PlanValidator::isServerPackLauncherFile($path));
    }

    public function test_skipped_pack_files_are_summarised_by_top_level_entry(): void
    {
        $this->assertSame(['libraries/ (3)', 'start.sh', 'jre/ (1)'], InstallEngine::collapse(['libraries/a/b.jar', 'start.sh', 'libraries/c.jar', 'jre/bin/java', 'libraries/d']));
    }
}
