<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use App\Models\Server;
use DrMxrcy\ModpacksPlus\Support\LoaderLaunchFiles;
use DrMxrcy\ModpacksPlus\Tests\Support\FakeServerFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Plan 43: the launch files each loader egg's install script leaves behind, checked with
 * two directory listings at most and no file reads. Fixtures follow the official
 * pelican-eggs/minecraft scripts.
 */
class LoaderLaunchFilesTest extends TestCase
{
    /** @return array<string, array{string, array<string, string>, ?string}> */
    public static function installed(): array
    {
        return [
            'neoforge' => ['neoforge', [
                'run.sh' => '#!/bin/sh',
                'unix_args.txt' => '-p',
                'libraries/net/neoforged/neoforge/21.1.77/unix_args.txt' => '-p',
            ], 'server.jar'],
            'neoforge 1.20.1 (forge artifact), link only' => ['neoforge', [
                'unix_args.txt' => '-p',
                'libraries/net/neoforged/forge/1.20.1-47.1.106/unix_args.txt' => '-p',
            ], null],
            'forge 1.17+' => ['forge', [
                'unix_args.txt' => '-p',
                'run.sh' => '#!/bin/sh',
                'libraries/net/minecraftforge/forge/1.20.1-47.3.0/unix_args.txt' => '-p',
            ], 'server.jar'],
            'forge before 1.17 (jar moved to SERVER_JARFILE)' => ['forge', [
                'forge.jar' => 'jar',
                'libraries/net/minecraft/launchwrapper/1.12/launchwrapper-1.12.jar' => 'jar',
            ], 'forge.jar'],
            'fabric, official script (renamed to server.jar)' => ['fabric', [
                'server.jar' => 'launch',
                'minecraft-server.jar' => 'vanilla',
                'fabric-server-launcher.properties' => 'serverJar=minecraft-server.jar',
            ], 'server.jar'],
            'fabric, launcher jar kept' => ['fabric', ['fabric-server-launch.jar' => 'launch'], null],
            'quilt, official script (renamed to server.jar)' => ['quilt', [
                'server.jar' => 'launch',
                'minecraft.jar' => 'vanilla',
                'quilt-server-launcher.properties' => 'serverJar=minecraft.jar',
            ], 'server.jar'],
            'quilt, launcher jar kept' => ['quilt', ['quilt-server-launch.jar' => 'launch'], null],
        ];
    }

    /** @param  array<string, string>  $files */
    #[DataProvider('installed')]
    public function test_an_installed_loader_passes_with_two_listings_at_most(string $loader, array $files, ?string $jar): void
    {
        $fake = (new FakeServerFiles())->seed($files + ['world/level.dat' => 'level']);

        $this->assertNull(LoaderLaunchFiles::missing($fake, new Server(), $loader, $jar));
        $this->assertLessThanOrEqual(2, count($fake->callsOf('list')));
        $this->assertSame([], $fake->callsOf('read'));
    }

    /** @return array<string, array{string, array<string, string>, ?string, string}> */
    public static function incomplete(): array
    {
        return [
            'neoforge, nothing' => ['neoforge', [], 'server.jar', 'unix_args.txt or run.sh + libraries/net/neoforged'],
            'neoforge, run.sh but no libraries' => ['neoforge', ['run.sh' => 'x'], null, 'libraries/net/neoforged'],
            'neoforge, forge libraries only' => ['neoforge', ['run.sh' => 'x', 'libraries/net/minecraftforge/forge/x' => 'x'], null, 'libraries/net/neoforged'],
            'forge, nothing' => ['forge', [], 'server.jar', 'unix_args.txt or run.sh + libraries/net/minecraftforge; or server.jar'],
            'forge, leftover fabric launcher' => ['forge', ['fabric-server-launch.jar' => 'x'], null, 'unix_args.txt or run.sh + libraries/net/minecraftforge'],
            'fabric, only the previous loader\'s files' => ['fabric', ['run.sh' => 'x', 'unix_args.txt' => 'x', 'libraries/net/neoforged/x' => 'x'], 'server.jar', 'fabric-server-launch.jar or server.jar'],
            'quilt, nothing' => ['quilt', [], null, 'quilt-server-launch.jar'],
            'unsafe SERVER_JARFILE is ignored' => ['quilt', ['x' => 'y'], '../server.jar', 'quilt-server-launch.jar'],
        ];
    }

    /** @param  array<string, string>  $files */
    #[DataProvider('incomplete')]
    public function test_missing_launch_files_are_named(string $loader, array $files, ?string $jar, string $missing): void
    {
        $fake = (new FakeServerFiles())->seed($files + ['world/level.dat' => 'level']);

        $this->assertSame($missing, LoaderLaunchFiles::missing($fake, new Server(), $loader, $jar));
        $this->assertLessThanOrEqual(2, count($fake->callsOf('list')));
        $this->assertSame([], $fake->callsOf('read'));
    }

    public function test_a_directory_named_like_the_launch_file_does_not_count(): void
    {
        $fake = (new FakeServerFiles())->seed(['fabric-server-launch.jar/' => '']);

        $this->assertSame('fabric-server-launch.jar', LoaderLaunchFiles::missing($fake, new Server(), 'fabric', null));
    }

    public function test_a_launch_file_older_than_the_reinstall_does_not_count(): void
    {
        $since = 1_800_000_000;
        $fake = (new FakeServerFiles())->seed(['server.jar' => 'old', 'fabric-server-launch.jar' => 'old']);
        $fake->modified = ['server.jar' => $since - LoaderLaunchFiles::CLOCK_SKEW - 1, 'fabric-server-launch.jar' => $since - 3600];

        $this->assertSame(
            ['missing' => 'fabric-server-launch.jar or server.jar (only older than the reinstall: fabric-server-launch.jar, server.jar)', 'undated' => false],
            LoaderLaunchFiles::check($fake, new Server(), 'fabric', 'server.jar', $since),
        );

        // Within the clock skew: written by the reinstall.
        $fake->modified['server.jar'] = $since - LoaderLaunchFiles::CLOCK_SKEW;
        $this->assertSame(['missing' => null, 'undated' => false], LoaderLaunchFiles::check($fake, new Server(), 'fabric', 'server.jar', $since));
    }

    public function test_a_fresh_run_script_passes_while_the_old_link_is_stale(): void
    {
        $since = 1_800_000_000;
        $fake = (new FakeServerFiles())->seed(['unix_args.txt' => 'x', 'run.sh' => 'x', 'libraries/net/minecraftforge/forge/x' => 'x']);
        $fake->modified['unix_args.txt'] = $since - 3600;
        $fake->modified['run.sh'] = $since + 30;

        $this->assertSame(['missing' => null, 'undated' => false], LoaderLaunchFiles::check($fake, new Server(), 'forge', null, $since));

        $fake->modified['run.sh'] = $since - 3600;
        $this->assertSame(
            'unix_args.txt or run.sh (only older than the reinstall: unix_args.txt, run.sh)',
            LoaderLaunchFiles::check($fake, new Server(), 'forge', null, $since)['missing'],
        );
    }

    public function test_a_launch_file_without_a_time_counts_as_undated(): void
    {
        $fake = (new FakeServerFiles())->seed(['server.jar' => 'x']);
        $fake->reportsModified = false;

        $this->assertSame(['missing' => null, 'undated' => true], LoaderLaunchFiles::check($fake, new Server(), 'quilt', 'server.jar', 1_800_000_000));
    }

    public function test_an_unknown_loader_is_not_checked(): void
    {
        $fake = new FakeServerFiles();

        $this->assertNull(LoaderLaunchFiles::missing($fake, new Server(), 'paper', null));
        $this->assertSame([], $fake->calls);
    }

    public function test_a_daemon_error_propagates_instead_of_reporting_files_missing(): void
    {
        $fake = new FakeServerFiles();
        $fake->beforeList = fn () => throw new RuntimeException('Daemon error while listing');

        $this->expectException(RuntimeException::class);
        LoaderLaunchFiles::missing($fake, new Server(), 'neoforge', null);
    }
}
