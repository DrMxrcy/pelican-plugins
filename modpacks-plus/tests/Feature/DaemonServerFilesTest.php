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
            'staging' => ['world', '.modpacks-plus/staging/1/world'],
            'out of staging' => ['.modpacks-plus/staging/1/mods', 'mods'],
            'unknown own subfolder' => ['world', '.modpacks-plus/other-x/world'],
            'bare prefix' => ['world', '.modpacks-plus/preserved-/world'],
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
        $this->files()->movePreserved($this->server, 'mods', '.modpacks-plus/replaced-x/mods');
        $this->files()->movePreserved($this->server, '.modpacks-plus/previous-x/mods', 'mods');

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

    /** Plan 43: the node's `modified` time is passed on as unix seconds; unreadable ones are left out. */
    public function test_listings_carry_the_modification_time_when_the_node_gives_one(): void
    {
        $node = $this->server->node;
        Http::swap(new HttpFactory());
        Http::fake([
            '*/files/list-directory*' => Http::response([
                ['name' => 'server.jar', 'size' => 10, 'directory' => false, 'modified' => '2026-11-10T12:00:00Z'],
                ['name' => 'run.sh', 'size' => 5, 'directory' => false, 'modified' => 'not a time'],
                ['name' => 'libraries', 'size' => 0, 'directory' => true],
            ], 200, ['User-Agent' => "Pelican Wings/v1.0.0 (id:{$node->daemon_token_id})"]),
        ]);

        $this->assertSame([
            'server.jar' => ['size' => 10, 'directory' => false, 'modified' => 1_794_312_000],
            'run.sh' => ['size' => 5, 'directory' => false],
            'libraries' => ['size' => 0, 'directory' => true],
        ], $this->files()->list($this->server, ''));
    }

    /** @return array<string, array{string, array<int, mixed>, string}> archive, paths, reason */
    public static function refusedDecompressions(): array
    {
        $staged = '.modpacks-plus/staging/overrides-7-0-1.zip';

        return [
            'archive outside staging' => ['mods/pack.zip', ['config/a.toml'], 'unsafe_path'],
            'download staging folder' => ['.modpacks-plus/staging/7/overrides-7-0-1.zip', ['config/a.toml'], 'unsafe_path'],
            'archive traversal' => ['.modpacks-plus/staging/../../overrides-7-0-1.zip', ['config/a.toml'], 'unsafe_path'],
            'not a zip name' => ['.modpacks-plus/staging/overrides-7-0-1.tar.gz', ['config/a.toml'], 'unsafe_path'],
            'no entries' => [$staged, [], 'too_many_entries'],
            'entry outside the root' => [$staged, ['config/a.toml', '../escape'], 'unsafe_path'],
            'absolute entry' => [$staged, ['/etc/cron.d/x'], 'unsafe_path'],
            'non-string entry' => [$staged, [42], 'unsafe_path'],
            'player list' => [$staged, ['config/a.toml', 'ops.json'], 'protected_path'],
            'server.properties' => [$staged, ['server.properties'], 'protected_path'],
            'world' => [$staged, ['world/level.dat'], 'protected_path'],
            'nether' => [$staged, ['world_nether/DIM-1/x'], 'protected_path'],
            'own folder' => [$staged, ['.modpacks-plus/previous-x/mods/a.jar'], 'protected_path'],
            'own staging' => [$staged, ['.modpacks-plus/staging/overrides-7-0-2.zip'], 'protected_path'],
            'modrinth metadata' => [$staged, ['mods/.modrinth-metadata.json'], 'protected_path'],
            'duplicate in another case' => [$staged, ['config/a.toml', 'Config/A.toml'], 'duplicate_path'],
        ];
    }

    /** @param  array<int, string>  $paths */
    #[DataProvider('refusedDecompressions')]
    public function test_the_node_only_unpacks_staged_override_archives_of_safe_paths(string $archive, array $paths, string $reason): void
    {
        $server = $this->server;

        $this->assertRefused(fn () => $this->files()->decompress($server, $archive, $paths), $reason);
    }

    public function test_decompress_protects_the_installs_own_worlds(): void
    {
        $server = $this->server;

        $this->assertRefused(fn () => $this->files()->protecting(['saves/survival'])->decompress($server, '.modpacks-plus/staging/overrides-7-0-1.zip', ['saves/survival/level.dat']), 'protected_path');
    }

    public function test_a_write_with_a_timeout_is_guarded_like_any_write(): void
    {
        $server = $this->server;

        $this->assertRefused(fn () => $this->files()->write($server, 'ops.json', 'x', 15), 'protected_path');
        $this->assertRefused(fn () => $this->files()->write($server, '../escape', 'x', 15), 'unsafe_path');

        $this->files()->write($server, '.modpacks-plus/staging/overrides-7-0-1.zip', 'zip bytes', 15);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), "/api/servers/{$server->uuid}/files/write")
            && str_contains(urldecode($request->url()), 'file=.modpacks-plus/staging/overrides-7-0-1.zip')
            && $request->body() === 'zip bytes');
    }

    public function test_decompress_unpacks_the_staged_archive_into_the_server_root(): void
    {
        $this->files()->decompress($this->server, '.modpacks-plus/staging/overrides-7-0-1.zip', ['config/a.toml', 'kubejs/server_scripts/x.js', '123']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), "/api/servers/{$this->server->uuid}/files/decompress")
            && $request->method() === 'POST'
            && $request['root'] === '/'
            && $request['file'] === '.modpacks-plus/staging/overrides-7-0-1.zip');
    }

    #[DataProvider('protectedPaths')]
    public function test_batched_renames_refuse_protected_paths_before_anything_is_sent(string $path): void
    {
        $server = $this->server;

        // One protected pair refuses the whole batch, in either direction.
        $this->assertRefused(fn () => $this->files()->renameMany($server, [
            ['from' => 'journeymap/a.json', 'to' => '.modpacks-plus/previous-x/journeymap/a.json'],
            ['from' => $path, 'to' => '.modpacks-plus/previous-x/'.$path],
        ]), 'protected_path');
        $this->assertRefused(fn () => $this->files()->renameMany($server, [['from' => '.modpacks-plus/previous-x/a', 'to' => $path]]), 'protected_path');
    }

    #[DataProvider('unsafePaths')]
    public function test_batched_renames_refuse_paths_outside_the_root(string $path): void
    {
        $server = $this->server;

        $this->assertRefused(fn () => $this->files()->renameMany($server, [['from' => 'mods/a.jar', 'to' => $path]]), 'unsafe_path');
    }

    public function test_batched_renames_go_in_one_request_and_never_replace_anything(): void
    {
        $pairs = [];
        for ($i = 0; $i < 300; $i++) {
            $pairs[] = ['from' => 'journeymap/'.($i % 3)."/f$i.json", 'to' => '.modpacks-plus/previous-x/journeymap/'.($i % 3)."/f$i.json"];
        }

        $this->files()->renameMany($this->server, $pairs);

        Http::assertSentCount(4); // 3 target directories listed, 1 rename
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/rename') && $request['root'] === '/' && $request['files'] === $pairs);

        $node = $this->server->node;
        Http::swap(new HttpFactory());
        Http::fake([
            '*/files/list-directory*' => Http::response([['name' => 'f7.json', 'size' => 1, 'directory' => false]], 200, ['User-Agent' => "Pelican Wings/v1.0.0 (id:{$node->daemon_token_id})"]),
            '*' => Http::response([], 200, ['User-Agent' => "Pelican Wings/v1.0.0 (id:{$node->daemon_token_id})"]),
        ]);
        try {
            $this->files()->renameMany($this->server, [['from' => 'a/f7.json', 'to' => 'b/f7.json']]);
            $this->fail('expected a refusal to replace');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to replace b/f7.json', $exception->getMessage());
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
