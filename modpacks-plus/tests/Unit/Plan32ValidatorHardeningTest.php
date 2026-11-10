<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Support\ByteLimitFilter;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\JobClock;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 32 security review: md5 is accepted only for an ATLauncher-CDN file marked
 * VERIFY_ATLAUNCHER_MD5; top-level scripts, jars and dotfiles in `files` are skipped for
 * every provider; the job clock and the byte-limit sink filter behave.
 */
class Plan32ValidatorHardeningTest extends TestCase
{
    private const ATL = 'https://download.nodecdn.net/containers/atl/packs/P/files/a.jar';

    protected function tearDown(): void
    {
        JobClock::stop();

        parent::tearDown();
    }

    private static function plan(string $provider, PlanFile ...$files): InstallPlan
    {
        return new InstallPlan($provider, 'p', 'v', 'Pack', '1', '1.20.1', 'forge', '47.2.0', files: $files);
    }

    private static function validator(string $provider): PlanValidator
    {
        return new PlanValidator(new Caps(), ['world'], PlanValidator::hostsFor($provider));
    }

    public function test_an_md5_file_on_atlaunchers_cdn_is_accepted(): void
    {
        $plan = self::validator('atlauncher')->validate(self::plan('atlauncher', new PlanFile('mods/a.jar', [self::ATL], 3, ['md5' => strtoupper(md5('abc'))], 'required', null, PlanFile::VERIFY_ATLAUNCHER_MD5)));

        $this->assertSame(['md5' => md5('abc')], $plan->files[0]->hashes);
        $this->assertSame(['md5', md5('abc')], PlanValidator::fileHash($plan->files[0]));
    }

    public function test_an_md5_file_in_an_atlauncher_partner_container_is_accepted(): void
    {
        // Plan 32 step 6: Pixelmon's server jar, linked `download: direct` from ATLauncher's CDN.
        $url = 'https://download.nodecdn.net/containers/reforged/server/release/9.4.2/Pixelmon-1.21.1-9.4.2-server.jar';
        $plan = self::validator('atlauncher')->validate(self::plan('atlauncher', new PlanFile('mods/p.jar', [$url], 3, ['md5' => md5('abc')], 'required', null, PlanFile::VERIFY_ATLAUNCHER_MD5)));

        $this->assertSame(['md5', md5('abc')], PlanValidator::fileHash($plan->files[0]));
    }

    /** @return array<string, array{string, bool}> */
    public static function md5Urls(): array
    {
        $base = 'https://download.nodecdn.net/containers/';

        return [
            'atl container' => [$base.'atl/packs/P/files/a.jar', true],
            'partner container' => [$base.'reforged/server/release/9.4.2/Pixelmon-1.21.1-9.4.2-server.jar', true],
            'percent-encoded name' => [$base.'atl/packs/A%20B/x%2By.jar', true],
            'http' => ['http://download.nodecdn.net/containers/reforged/a.jar', false],
            'other host' => ['https://cdn.example.com/containers/reforged/a.jar', false],
            'look-alike host' => ['https://download.nodecdn.net.evil.com/containers/reforged/a.jar', false],
            'sub-host' => ['https://evil.download.nodecdn.net/containers/reforged/a.jar', false],
            'upper-case host' => ['https://DOWNLOAD.nodecdn.net/containers/reforged/a.jar', false],
            'userinfo' => ['https://download.nodecdn.net@evil.com/containers/reforged/a.jar', false],
            'userinfo on the host' => ['https://x@download.nodecdn.net/containers/reforged/a.jar', false],
            'port' => ['https://download.nodecdn.net:443/containers/reforged/a.jar', false],
            'query' => [$base.'reforged/a.jar?x=1', false],
            'fragment' => [$base.'reforged/a.jar#x', false],
            'not a container' => ['https://download.nodecdn.net/other/reforged/a.jar', false],
            'no file in the container' => [$base.'reforged/', false],
            'container only' => [$base.'reforged', false],
            'empty container' => [$base.'/a.jar', false],
            'dot container' => [$base.'../atl/a.jar', false],
            'traversal' => [$base.'reforged/../atl/a.jar', false],
            'dot segment' => [$base.'reforged/./a.jar', false],
            'encoded traversal' => [$base.'reforged/%2e%2e/atl/a.jar', false],
            'encoded slash' => [$base.'reforged/x%2F..%2Fa.jar', false],
            'double encoding' => [$base.'reforged/%252e%252e/a.jar', false],
            'encoded backslash' => [$base.'reforged/..%5Ca.jar', false],
            'encoded NUL' => [$base.'reforged/a.jar%00.zip', false],
            'double slash' => [$base.'reforged//a.jar', false],
            'trailing newline' => [$base."reforged/a.jar\n", false],
            'backslash' => [$base.'reforged\\a.jar', false],
        ];
    }

    #[DataProvider('md5Urls')]
    public function test_md5_is_only_accepted_for_a_plain_file_url_in_an_atlauncher_cdn_container(string $url, bool $accepted): void
    {
        $this->assertSame($accepted, PlanValidator::isAtlauncherMd5Url($url));
    }

    /** @return array<string, array{string, string, array<string, string>, ?string}> */
    public static function refusedMd5(): array
    {
        $md5 = ['md5' => md5('abc')];

        return [
            'not a container' => ['atlauncher', 'https://download.nodecdn.net/other/a.jar', $md5, PlanFile::VERIFY_ATLAUNCHER_MD5],
            'traversal out of a container' => ['atlauncher', 'https://download.nodecdn.net/containers/reforged/../../a.jar', $md5, PlanFile::VERIFY_ATLAUNCHER_MD5],
            'query on the cdn' => ['atlauncher', 'https://download.nodecdn.net/containers/reforged/a.jar?x=1', $md5, PlanFile::VERIFY_ATLAUNCHER_MD5],
            'curseforge cdn' => ['atlauncher', 'https://edge.forgecdn.net/files/1/2/a.jar', $md5, PlanFile::VERIFY_ATLAUNCHER_MD5],
            'modrinth cdn' => ['atlauncher', 'https://cdn.modrinth.com/data/a/versions/b/a.jar', $md5, PlanFile::VERIFY_ATLAUNCHER_MD5],
            'ftb host' => ['ftb', 'https://dist.modpacks.ch/modpacks/1/a.jar', $md5, PlanFile::VERIFY_ATLAUNCHER_MD5],
            'stronger hash present' => ['atlauncher', self::ATL, $md5 + ['sha1' => sha1('abc')], PlanFile::VERIFY_ATLAUNCHER_MD5],
            'malformed md5' => ['atlauncher', self::ATL, ['md5' => 'zz'], PlanFile::VERIFY_ATLAUNCHER_MD5],
            'md5 on a panel file' => ['atlauncher', self::ATL, $md5, PlanFile::VERIFY_PANEL],
            'md5 on a modrinth file' => ['modrinth', 'https://cdn.modrinth.com/data/a/versions/b/a.jar', $md5, PlanFile::VERIFY_MODRINTH],
            'md5 with no verify' => ['atlauncher', self::ATL, $md5, null],
        ];
    }

    /** @param  array<string, string>  $hashes */
    #[DataProvider('refusedMd5')]
    public function test_md5_is_refused_anywhere_else(string $provider, string $url, array $hashes, ?string $verify): void
    {
        try {
            self::validator($provider)->validate(self::plan($provider, new PlanFile('mods/a.jar', [$url], 3, $hashes, 'required', null, $verify)));
            $this->fail('md5 was accepted');
        } catch (UnsafePlanException $exception) {
            $this->assertSame('bad_hash', $exception->reason);
        }
    }

    public function test_top_level_scripts_jars_and_dotfiles_in_files_are_skipped_for_every_provider(): void
    {
        $sha1 = ['sha1' => sha1('x')];
        foreach (['modrinth' => 'https://cdn.modrinth.com/data/a/versions/b/', 'curseforge' => 'https://edge.forgecdn.net/files/1/2/', 'ftb' => 'https://dist.modpacks.ch/m/', 'atlauncher' => 'https://download.nodecdn.net/containers/atl/'] as $provider => $base) {
            $plan = self::validator($provider)->validate(self::plan(
                $provider,
                new PlanFile('mods/ok.jar', [$base.'ok.jar'], 1, $sha1),
                new PlanFile('run.sh', [$base.'run.sh'], 1, $sha1),
                new PlanFile('server.jar', [$base.'server.jar'], 1, $sha1),
                new PlanFile('.env', [$base.'env'], 1, $sha1),
                new PlanFile('libraries/x.jar', [$base.'x.jar'], 1, $sha1),
            ));

            $this->assertSame(['mods/ok.jar'], array_map(fn (PlanFile $file) => $file->path, $plan->files), $provider);
            $this->assertSame(['run.sh', 'server.jar', '.env', 'libraries/x.jar'], $plan->skippedPackFiles, $provider);
        }
    }

    public function test_the_own_folder_in_files_still_rejects_the_pack(): void
    {
        $this->expectExceptionObject(new UnsafePlanException('protected_path', '.modpacks-plus/x.jar'));
        self::validator('modrinth')->validate(self::plan('modrinth', new PlanFile('.modpacks-plus/x.jar', ['https://cdn.modrinth.com/data/a/versions/b/x.jar'], 1, ['sha1' => sha1('x')])));
    }

    public function test_the_job_clock_bounds_every_request(): void
    {
        $this->assertSame(30, JobClock::timeout(30));
        $this->assertFalse(JobClock::exhausted());

        JobClock::start(20);
        $this->assertLessThanOrEqual(15, JobClock::timeout(30));
        $this->assertGreaterThanOrEqual(14, JobClock::timeout(30));
        $this->assertSame(10, JobClock::timeout(10));
        $this->assertFalse(JobClock::exhausted());

        JobClock::start(6);
        $this->assertSame(1, JobClock::timeout(30));
        $this->assertTrue(JobClock::exhausted());
    }

    public function test_the_byte_limit_filter_fails_the_write_past_the_limit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mp-limit');
        $handle = fopen((string) $path, 'w+b');
        $state = ByteLimitFilter::attach($handle, 5);

        $this->assertSame(3, fwrite($handle, 'abc'));
        $this->assertFalse($state->exceeded);
        $this->assertNotSame(3, @fwrite($handle, 'def'));
        $this->assertTrue($state->exceeded);
        fclose($handle);

        $this->assertLessThanOrEqual(5, filesize((string) $path));
        @unlink((string) $path);
    }
}
