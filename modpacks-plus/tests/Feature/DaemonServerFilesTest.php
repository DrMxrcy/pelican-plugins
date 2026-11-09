<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\Server;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Services\DaemonServerFiles;
use DrMxrcy\ModpacksPlus\Services\DaemonServerPower;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The real daemon adapter (not the test fake): its own guard must refuse paths outside
 * the root, changes to protected paths and any non-CDN pull before a request leaves
 * the panel, whatever the engine above it does.
 */
class DaemonServerFilesTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->server] = $this->generateTestAccount();
        $node = $this->server->node;

        Http::swap(new HttpFactory());
        // Wings answers carry its User-Agent; the panel checks it against the node token.
        Http::fake(['*' => Http::response([], 200, ['User-Agent' => "Pelican Wings/v1.0.0 (id:{$node->daemon_token_id})"])]);
    }

    private function files(): DaemonServerFiles
    {
        return new DaemonServerFiles();
    }

    private function assertRefused(callable $callback, string $reason): void
    {
        try {
            $callback();
            $this->fail("Expected the daemon call to be refused with [$reason]");
        } catch (UnsafePlanException $exception) {
            $this->assertSame($reason, $exception->reason, $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'parent' => ['../escape'],
            'nested parent' => ['mods/../../escape'],
            'absolute' => ['/etc/passwd'],
            'backslash' => ['mods\\..\\escape'],
            'encoded' => ['%2e%2e/escape'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_refuses_paths_outside_the_root(string $path): void
    {
        $server = $this->server;

        $this->assertRefused(fn () => $this->files()->write($server, $path, 'x'), 'unsafe_path');
        $this->assertRefused(fn () => $this->files()->delete($server, $path), 'unsafe_path');
        $this->assertRefused(fn () => $this->files()->read($server, $path, 10), 'unsafe_path');
        $this->assertRefused(fn () => $this->files()->rename($server, 'mods', $path), 'unsafe_path');
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
            'nether' => ['world_nether/DIM-1/x'],
        ];
    }

    #[DataProvider('protectedPaths')]
    public function test_refuses_changing_protected_paths(string $path): void
    {
        $server = $this->server;

        $this->assertRefused(fn () => $this->files()->write($server, $path, 'x'), 'protected_path');
        $this->assertRefused(fn () => $this->files()->delete($server, $path), 'protected_path');
        $this->assertRefused(fn () => $this->files()->createDirectory($server, $path), 'protected_path');
        // Not even into the plugin's own folder.
        $this->assertRefused(fn () => $this->files()->rename($server, $path, '.modpacks-plus/previous-x/'.basename($path)), 'protected_path');
        $this->assertRefused(fn () => $this->files()->rename($server, 'mods/a.jar', $path), 'protected_path');
    }

    public function test_protects_the_installs_own_worlds(): void
    {
        $server = $this->server;
        $files = $this->files()->protecting(['saves/survival']);

        $this->assertRefused(fn () => $files->write($server, 'saves/survival/level.dat', 'x'), 'protected_path');
        $this->assertRefused(fn () => $files->rename($server, 'saves', '.modpacks-plus/previous-x/saves'), 'protected_path');
        $this->assertRefused(fn () => $files->delete($server, 'saves/survival_nether'), 'protected_path');
        $this->assertRefused(fn () => $files->createDirectory($server, 'saves/survival/datapacks'), 'protected_path');

        // A directory that merely contains the world may be created (it exists already).
        $files->createDirectory($server, 'saves');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/create-directory'));
    }

    public function test_allows_ordinary_and_own_folder_changes(): void
    {
        $this->files()->write($this->server, 'mods/a.jar', 'jar');
        $this->files()->write($this->server, '.modpacks-plus/staging/1/mods/a.jar', 'jar');
        $this->files()->rename($this->server, 'mods', '.modpacks-plus/previous-x/mods');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/write') && str_contains(urldecode($request->url()), 'file=mods/a.jar'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/rename')
            && $request['files'] === [['from' => 'mods', 'to' => '.modpacks-plus/previous-x/mods']]);
    }

    /** @return array<string, array{string}> */
    public static function nonCdnUrls(): array
    {
        return [
            'github' => ['https://github.com/o/r/releases/download/v1/a.jar'],
            'raw github' => ['https://raw.githubusercontent.com/o/r/main/a.jar'],
            'gitlab' => ['https://gitlab.com/o/r/-/raw/main/a.jar'],
            'plain http cdn' => ['http://cdn.modrinth.com/data/x/versions/y/a.jar'],
            'evil' => ['https://evil.example/a.jar'],
        ];
    }

    #[DataProvider('nonCdnUrls')]
    public function test_the_node_never_pulls_anything_but_the_modrinth_cdn(string $url): void
    {
        $server = $this->server;

        $this->assertRefused(fn () => $this->files()->pull($server, $url, '.modpacks-plus/staging/1/mods', 'a.jar', 30), 'no_allowed_url');
    }

    public function test_pulls_from_the_cdn_into_a_safe_target_only(): void
    {
        $server = $this->server;
        $url = 'https://cdn.modrinth.com/data/x/versions/y/a.jar';

        $this->assertRefused(fn () => $this->files()->pull($server, $url, '', 'ops.json', 30), 'protected_path');
        $this->assertRefused(fn () => $this->files()->pull($server, $url, '..', 'a.jar', 30), 'unsafe_path');
        $this->assertRefused(fn () => $this->files()->pull($server, $url, 'mods', '../a.jar', 30), 'unsafe_path');

        $this->files()->pull($server, $url, '.modpacks-plus/staging/1/mods', 'a.jar', 30);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/pull')
            && $request['url'] === $url
            && $request['root'] === '/.modpacks-plus/staging/1/mods'
            && $request['file_name'] === 'a.jar'
            && $request['foreground'] === true);
    }

    /** @return array<string, array{string, string}> */
    public static function refusedPreservedMoves(): array
    {
        return [
            'both outside the own folder' => ['world', 'backups/world'],
            'both inside' => ['.modpacks-plus/preserved-x/world', '.modpacks-plus/other/world'],
            'the own folder itself out' => ['.modpacks-plus', 'elsewhere'],
            'into the own folder root' => ['world', '.modpacks-plus'],
            'top level of the own folder' => ['world', '.modpacks-plus/world'],
            'own subfolder out' => ['.modpacks-plus/staging', 'staging'],
            'over the own folder' => ['.modpacks-plus/preserved-x/world', '.modpacks-plus'],
        ];
    }

    #[DataProvider('refusedPreservedMoves')]
    public function test_preserved_moves_only_go_between_the_root_and_the_own_folder(string $from, string $to): void
    {
        $server = $this->server;

        $this->assertRefused(fn () => $this->files()->movePreserved($server, $from, $to), 'protected_path');
    }

    #[DataProvider('unsafePaths')]
    public function test_preserved_moves_refuse_paths_outside_the_root(string $path): void
    {
        $server = $this->server;

        $this->assertRefused(fn () => $this->files()->movePreserved($server, $path, '.modpacks-plus/preserved-x/w'), 'unsafe_path');
        $this->assertRefused(fn () => $this->files()->movePreserved($server, '.modpacks-plus/preserved-x/w', $path), 'unsafe_path');
    }

    public function test_preserved_moves_take_worlds_aside_and_back(): void
    {
        $this->files()->movePreserved($this->server, 'world', '.modpacks-plus/preserved-x/world');
        $this->files()->movePreserved($this->server, '.modpacks-plus/preserved-x/ops.json', 'ops.json');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/rename')
            && $request['files'] === [['from' => 'world', 'to' => '.modpacks-plus/preserved-x/world']]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/rename')
            && $request['files'] === [['from' => '.modpacks-plus/preserved-x/ops.json', 'to' => 'ops.json']]);
    }

    public function test_preserved_moves_never_replace_anything(): void
    {
        $node = $this->server->node;
        Http::swap(new HttpFactory());
        Http::fake([
            '*/files/list-directory*' => Http::response([['name' => 'world', 'size' => 0, 'directory' => true]], 200, ['User-Agent' => "Pelican Wings/v1.0.0 (id:{$node->daemon_token_id})"]),
            '*' => Http::response([], 200, ['User-Agent' => "Pelican Wings/v1.0.0 (id:{$node->daemon_token_id})"]),
        ]);

        try {
            $this->files()->movePreserved($this->server, '.modpacks-plus/preserved-x/world', 'world');
            $this->fail('expected a refusal to replace');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to replace world', $exception->getMessage());
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/files/rename'));
    }

    public function test_server_power_reads_the_state_and_sends_stop(): void
    {
        $node = $this->server->node;
        Http::swap(new HttpFactory());
        Http::fake(['*' => Http::response(['state' => 'running'], 200, ['User-Agent' => "Pelican Wings/v1.0.0 (id:{$node->daemon_token_id})"])]);

        $power = new DaemonServerPower();
        $this->assertSame('running', $power->state($this->server));
        $power->stop($this->server);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), "/api/servers/{$this->server->uuid}/power") && $request['action'] === 'stop');
    }
}
