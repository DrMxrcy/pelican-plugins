<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Tests\Traits\Integration\CreatesTestModels;
use DrMxrcy\ModpacksPlus\Console\Commands\CheckPackUpdatesCommand;
use DrMxrcy\ModpacksPlus\Contracts\ServerFiles;
use DrMxrcy\ModpacksPlus\Contracts\ServerPower;
use DrMxrcy\ModpacksPlus\Data\InstallPlan;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Providers\ModpacksPlusServiceProvider;
use DrMxrcy\ModpacksPlus\Services\PropertiesPreserver;
use DrMxrcy\ModpacksPlus\Tests\Support\Mrpack;
use DrMxrcy\ModpacksPlus\Tests\Support\RecordingPlugin;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 36 step 5: the scheduled update check. Metadata only, never a node or game server,
 * one notification per new version, nothing at all when the setting is off.
 */
class PackUpdateCheckTest extends TestCase
{
    use CreatesTestModels;
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> the pack's versions on the fake Modrinth, newest first */
    private array $versions = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modpacks-plus.update_check', 'daily');
        // Any daemon or game-server contact fails the test (mocks without expectations throw).
        $this->app->instance(ServerFiles::class, Mockery::mock(ServerFiles::class));
        $this->app->instance(ServerPower::class, Mockery::mock(ServerPower::class));
        cache()->flush();

        // The panel skips plugin loading under test; register the command PluginService would.
        Application::starting(fn ($artisan) => $artisan->resolve(CheckPackUpdatesCommand::class));
        $this->app->make(Kernel::class)->setArtisan(null);
    }

    /** @return array<string, mixed> a Modrinth version of the test pack */
    private static function version(string $id, string $number, string $loader = 'fabric', string $minecraft = '1.20.1'): array
    {
        $version = Mrpack::version('x');
        $version['id'] = $id;
        $version['version_number'] = $number;
        $version['loaders'] = [$loader];
        $version['game_versions'] = [$minecraft];
        $version['date_published'] = '2026-01-0'.substr($id, -1).'T00:00:00Z';

        return $version;
    }

    private function fakeModrinth(): void
    {
        Http::swap(new HttpFactory());
        Http::fake(['api.modrinth.com/v2/project/'.Mrpack::PACK_ID.'/version' => fn () => Http::response($this->versions)]);
    }

    /** @return array{Server, PackInstall} */
    private function installed(string $provider = 'modrinth', string $packId = Mrpack::PACK_ID): array
    {
        [, $server] = $this->generateTestAccount();
        $plan = new InstallPlan(provider: $provider, packId: $packId, versionId: 'VERSID01', packName: 'Test Pack', versionNumber: '1.0.0', minecraftVersion: '1.20.1', loader: 'fabric', loaderVersion: null);
        $install = PackInstall::query()->create([
            'server_id' => $server->id, 'provider' => $provider, 'pack_id' => $packId, 'version_id' => 'VERSID01',
            'name' => 'Test Pack', 'version_number' => '1.0.0', 'status' => PackInstall::STATUS_INSTALLED, 'seq' => 2, 'plan' => $plan->toArray(),
        ]);

        return [$server, $install];
    }

    private function check(): void
    {
        $this->artisan(CheckPackUpdatesCommand::class)->assertSuccessful();
    }

    private function notifications(): int
    {
        return DB::table('notifications')->count();
    }

    public function test_marks_the_update_and_notifies_the_owner_once_per_version(): void
    {
        [$server, $install] = $this->installed();
        $this->versions = [self::version('VERSID02', '2.0.0'), self::version('VERSID01', '1.0.0')];
        $this->fakeModrinth();

        $this->check();

        $install->refresh();
        $this->assertTrue($install->update_available);
        $this->assertSame('VERSID02', $install->latest_version_id);
        $this->assertSame('2.0.0', $install->latest_version_number);
        $this->assertSame('VERSID02', $install->notified_version);
        $this->assertNotNull($install->update_checked_at);
        $this->assertSame(1, $this->notifications());
        $row = DB::table('notifications')->first();
        $this->assertSame($server->owner_id, (int) $row->notifiable_id);
        $this->assertStringContainsString('2.0.0', (string) $row->data);

        // Second run, same version: no duplicate.
        $this->check();
        $this->assertSame(1, $this->notifications());

        // A newer version: one more.
        array_unshift($this->versions, self::version('VERSID03', '3.0.0'));
        cache()->flush();
        $this->fakeModrinth();
        $this->check();
        $this->assertSame(2, $this->notifications());
        $this->assertSame('VERSID03', $install->fresh()?->notified_version);
    }

    public function test_the_off_setting_asks_nothing_and_schedules_nothing(): void
    {
        config()->set('modpacks-plus.update_check', 'off');
        [, $install] = $this->installed();
        $this->versions = [self::version('VERSID02', '2.0.0')];
        $this->fakeModrinth();

        $this->check();

        Http::assertNothingSent();
        $this->assertFalse($install->fresh()?->update_available);
        $this->assertSame(0, $this->notifications());
        $this->assertSame([], $this->scheduledChecks());
    }

    /** @return array<string, array{string, string}> */
    public static function frequencies(): array
    {
        return ['daily' => ['daily', '0 0 * * *'], 'weekly' => ['weekly', '0 0 * * 0']];
    }

    #[DataProvider('frequencies')]
    public function test_the_check_is_scheduled_as_set(string $frequency, string $cron): void
    {
        config()->set('modpacks-plus.update_check', $frequency);
        $this->assertSame([$cron], $this->scheduledChecks());
    }

    /** @return array<int, string> cron expressions of the scheduled update checks */
    private function scheduledChecks(): array
    {
        $schedule = new Schedule();
        ModpacksPlusServiceProvider::schedule($schedule);

        return array_values(array_map(fn ($event) => $event->expression, array_filter($schedule->events(), fn ($event) => str_contains((string) $event->command, 'modpacks-plus:check-updates'))));
    }

    public function test_only_versions_for_the_installed_loader_and_minecraft_version_count(): void
    {
        [, $install] = $this->installed();
        $this->versions = [self::version('VERSID03', '3.0.0', 'neoforge', '1.21.1'), self::version('VERSID02', '2.0.0', 'fabric', '1.21.1'), self::version('VERSID01', '1.0.0')];
        $this->fakeModrinth();

        $this->check();

        $this->assertFalse($install->fresh()?->update_available);
        $this->assertSame(0, $this->notifications());
    }

    public function test_only_metadata_is_read_and_no_node_is_contacted(): void
    {
        [, $install] = $this->installed();
        $this->versions = [self::version('VERSID02', '2.0.0'), self::version('VERSID01', '1.0.0')];
        $this->fakeModrinth();

        $this->check();

        $this->assertTrue($install->fresh()?->update_available);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.modrinth.com/v2/project/'.Mrpack::PACK_ID.'/version');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'cdn.modrinth.com') || str_ends_with($request->url(), '.mrpack'));
    }

    public function test_the_per_run_cap_leaves_out_the_oldest_installs(): void
    {
        config()->set('modpacks-plus.update_check_max_packs', 1);
        [, $old] = $this->installed('modrinth', 'OLDPACK1');
        [, $new] = $this->installed();
        $this->versions = [self::version('VERSID02', '2.0.0'), self::version('VERSID01', '1.0.0')];
        $this->fakeModrinth();

        $this->check();

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'OLDPACK1'));
        $this->assertTrue($new->fresh()?->update_available);
        $this->assertNull($old->fresh()?->update_checked_at);
    }

    public function test_one_request_per_pack_however_many_servers_run_it(): void
    {
        $this->installed();
        $this->installed();
        $this->installed();
        $this->versions = [self::version('VERSID02', '2.0.0'), self::version('VERSID01', '1.0.0')];
        $this->fakeModrinth();

        $this->check();

        Http::assertSentCount(1);
        $this->assertSame(3, PackInstall::query()->where('update_available', true)->count());
        $this->assertSame(3, $this->notifications());
    }

    public function test_the_notification_carries_no_hidden_value(): void
    {
        [$server, $install] = $this->installed();
        $server->loadMissing('egg');
        $hidden = EggVariable::factory()->create(['egg_id' => $server->egg_id, 'env_variable' => 'RCON_PASSWORD', 'default_value' => '', 'user_viewable' => false]);
        ServerVariable::query()->create(['server_id' => $server->id, 'variable_id' => $hidden->getKey(), 'variable_value' => 'hidden-secret-4711']);
        $server->forceFill(['startup' => 'java -Dsecret=startup-secret-4712 -jar server.jar', 'image' => 'ghcr.io/private/image-secret-4713'])->save();
        config()->set('modpacks-plus.curseforge.api_key', 'cf-key-secret-4714-abcdefgh');
        $this->versions = [self::version('VERSID02', '2.0.0'), self::version('VERSID01', '1.0.0')];
        $this->fakeModrinth();

        $this->check();

        $data = (string) DB::table('notifications')->value('data');
        $this->assertStringContainsString('Test Pack', $data);
        $this->assertStringContainsString('2.0.0', $data);
        foreach (['hidden-secret-4711', 'startup-secret-4712', 'image-secret-4713', 'cf-key-secret-4714', 'RCON_PASSWORD', '.modpacks-plus', 'VERSID02'] as $secret) {
            $this->assertStringNotContainsString($secret, $data);
        }
        $this->assertTrue($install->fresh()?->update_available);
    }

    public function test_html_from_pack_metadata_never_reaches_the_notification(): void
    {
        [$server, $install] = $this->installed();
        $install->forceFill(['name' => 'Pack<a href="https://evil.example/x">click</a><img src="https://evil.example/p.png" style="x">'])->save();
        $server->forceFill(['name' => '<b>srv</b>'])->save();
        $hostile = self::version('VERSID02', '<img src=https://evil.example/v.png>');
        $this->versions = [$hostile, self::version('VERSID01', '1.0.0')];
        $this->fakeModrinth();

        $this->check();

        $data = json_decode((string) DB::table('notifications')->value('data'), true);
        $text = (string) ($data['title'] ?? '').' '.(string) ($data['body'] ?? '');
        foreach (['<a', '<img', '<b>'] as $raw) {
            $this->assertStringNotContainsString($raw, $text);
        }
        $this->assertStringContainsString('&lt;a href=', $text);
        $this->assertStringContainsString('&lt;img src=', $text);
    }

    public function test_curseforge_without_a_key_or_the_mirror_is_never_asked(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', null);
        config()->set('modpacks-plus.curseforge.mirror', false);
        [, $install] = $this->installed('curseforge', '123456');
        Http::fake();

        $this->check();

        Http::assertNothingSent();
        $this->assertFalse($install->fresh()?->update_available);
    }

    public function test_an_unreachable_source_skips_the_pack_quietly(): void
    {
        [, $install] = $this->installed();
        Http::swap(new HttpFactory());
        Http::fake(['api.modrinth.com/*' => Http::response('down', 503)]);

        $this->check();

        $fresh = $install->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->update_available);
        $this->assertNull($fresh->update_checked_at);
        $this->assertSame(0, $this->notifications());
    }

    public function test_only_the_newest_installed_pack_of_a_server_is_checked(): void
    {
        [$server, $old] = $this->installed();
        $newer = $old->replicate();
        $newer->forceFill(['pack_id' => 'OTHERPK1', 'name' => 'Other'])->save();
        Http::swap(new HttpFactory());
        Http::fake(['api.modrinth.com/v2/project/OTHERPK1/version' => Http::response([])]);

        $this->check();

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), Mrpack::PACK_ID));
        $this->assertSame($server->id, $newer->server_id);
    }

    public function test_settings_store_only_a_known_frequency_and_valid_keys(): void
    {
        $plugin = new RecordingPlugin();

        $plugin->saveSettings(['update_check' => 'weekly', 'preserved_properties' => "motd, pvp\nbad/key, evil=1\nquery.port"]);
        $this->assertSame('weekly', $plugin->written['MODPACKS_PLUS_UPDATE_CHECK']);
        $this->assertSame('motd,pvp,query.port', $plugin->written['MODPACKS_PLUS_PRESERVED_PROPERTIES']);

        $plugin->saveSettings(['update_check' => 'hourly"; rm -rf /', 'preserved_properties' => '']);
        $this->assertSame('off', $plugin->written['MODPACKS_PLUS_UPDATE_CHECK']);
        // An emptied list goes back to the defaults.
        $this->assertSame(implode(',', PropertiesPreserver::DEFAULT_KEYS), $plugin->written['MODPACKS_PLUS_PRESERVED_PROPERTIES']);

        $plugin->saveSettings([]);
        $this->assertArrayNotHasKey('MODPACKS_PLUS_UPDATE_CHECK', $plugin->written);
        $this->assertArrayNotHasKey('MODPACKS_PLUS_PRESERVED_PROPERTIES', $plugin->written);

        $this->assertSame('daily', $plugin->getSettingsFormData()['update_check']);
        $this->assertStringStartsWith('level-name, level-seed, motd', $plugin->getSettingsFormData()['preserved_properties']);
    }
}
