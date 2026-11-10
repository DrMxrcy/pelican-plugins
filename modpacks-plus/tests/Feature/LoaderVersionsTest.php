<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Services\LoaderVersions;
use DrMxrcy\ModpacksPlus\Tests\Support\LoaderMeta;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Plan 40 step 1: the version lists of the "Minecraft version" picker. Parsing per host,
 * releases only, caps, cache, outage, and only the allowlisted URLs ever requested.
 * (Booted for Http::fake and the cache, so it lives with the feature tests.)
 */
class LoaderVersionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    private function fakeAll(array $overrides = []): void
    {
        Http::fake(array_merge(LoaderMeta::stubs(), $overrides));
    }

    private function assertOnlyAllowlistedRequests(): void
    {
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $this->assertContains($request->url(), LoaderVersions::URLS);
        }
    }

    public function test_fabric_offers_stable_releases_with_its_stable_loader_builds(): void
    {
        $this->fakeAll();

        $versions = (new LoaderVersions())->versions('fabric');

        $this->assertSame(['26.3', '26.2', '26.1.2', '1.21.1', '1.20.1'], array_keys($versions));
        $this->assertSame(['0.20.1', '0.19.5'], $versions['26.2']);
        $this->assertOnlyAllowlistedRequests();
    }

    public function test_quilt_offers_stable_releases_without_loader_versions(): void
    {
        $this->fakeAll();

        $versions = (new LoaderVersions())->versions('quilt');

        $this->assertSame(['26.3', '26.2', '26.1.2', '1.21.1', '1.20.1'], array_keys($versions));
        $this->assertSame([], $versions['26.3']);
        Http::assertNotSent(fn (Request $request) => $request->url() === LoaderVersions::FABRIC_LOADER_URL);
    }

    public function test_neoforge_maps_release_builds_to_minecraft_versions_and_drops_betas(): void
    {
        $this->fakeAll();

        $versions = (new LoaderVersions())->versions('neoforge');

        // 1.20.4 is not in the (faked) Mojang releases, 1.21.4 has only a beta.
        $this->assertSame(['26.3', '26.1.2', '1.21.1'], array_keys($versions));
        $this->assertSame(['26.3.0.102', '26.3.0.101'], $versions['26.3']);
        $this->assertSame(['26.1.2.126', '26.1.2.125'], $versions['26.1.2']);
        $this->assertSame(['21.1.264', '21.1.77'], $versions['1.21.1']);
    }

    public function test_neoforge_minecraft_mapping(): void
    {
        $this->assertSame('1.21.1', LoaderVersions::neoforgeMinecraft('21.1.77'));
        $this->assertSame('1.21', LoaderVersions::neoforgeMinecraft('21.0.167'));
        $this->assertSame('1.21.11', LoaderVersions::neoforgeMinecraft('21.11.48'));
        $this->assertSame('26.3', LoaderVersions::neoforgeMinecraft('26.3.0.102'));
        $this->assertSame('26.1.2', LoaderVersions::neoforgeMinecraft('26.1.2.126'));
        $this->assertNull(LoaderVersions::neoforgeMinecraft('21.4.0-beta'));
        $this->assertNull(LoaderVersions::neoforgeMinecraft('26.1.0.0-alpha.1+snapshot-1'));
        $this->assertNull(LoaderVersions::neoforgeMinecraft('1.20.1-47.1.7'));
    }

    public function test_forge_keeps_plain_minecraft_forge_entries_with_the_prefix(): void
    {
        $this->fakeAll();

        $versions = (new LoaderVersions())->versions('forge');

        $this->assertSame(['26.3', '1.20.1'], array_keys($versions));
        $this->assertSame(['26.3-66.1.5', '26.3-66.1.4'], $versions['26.3']);
        $this->assertSame(['1.20.1-47.4.29', '1.20.1-47.3.0'], $versions['1.20.1']);
        $this->assertOnlyAllowlistedRequests();
    }

    public function test_lists_are_cached_across_requests(): void
    {
        $this->fakeAll();

        (new LoaderVersions())->versions('fabric');
        (new LoaderVersions())->versions('fabric');
        (new LoaderVersions())->versions('quilt');

        // Mojang, Fabric game, Fabric loader, Quilt game: once each.
        Http::assertSentCount(4);

        $this->travel(LoaderVersions::CACHE_SECONDS + 1)->seconds();
        (new LoaderVersions())->versions('fabric');
        Http::assertSentCount(7);
    }

    public function test_an_outage_is_versions_unavailable_with_the_host_and_is_not_asked_again_at_once(): void
    {
        $this->fakeAll([LoaderVersions::FABRIC_GAME_URL => Http::response('down', 503)]);

        foreach ([1, 2] as $attempt) {
            try {
                (new LoaderVersions())->versions('fabric');
                $this->fail('expected versions_unavailable');
            } catch (PackUnavailableException $exception) {
                $this->assertSame('versions_unavailable', $exception->reason);
                $this->assertSame('meta.fabricmc.net', $exception->detail);
            }
        }
        Http::assertSentCount(2);

        // Other loaders are unaffected.
        $this->assertArrayHasKey('26.3', (new LoaderVersions())->versions('neoforge'));
        $this->assertTrue(trans()->has('modpacks-plus::strings.reasons.versions_unavailable'));
    }

    /** @return array<string, array{mixed}> */
    public static function brokenAnswers(): array
    {
        return [
            'not json' => [fn () => Http::response('<html>')],
            'wrong shape' => [fn () => Http::response(['versions' => 'x'])],
            'redirect' => [fn () => Http::response('', 302, ['Location' => 'https://evil.example/manifest.json'])],
            'too large by header' => [fn () => Http::response('{}', 200, ['Content-Length' => (string) (LoaderVersions::MAX_BYTES + 1)])],
            'too large body' => [fn () => Http::response(str_repeat(' ', LoaderVersions::MAX_BYTES + 1))],
            'encoded' => [fn () => Http::response('{}', 200, ['Content-Encoding' => 'gzip'])],
            'connection error' => [fn () => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenAnswers')]
    public function test_a_broken_answer_is_unavailable_and_nothing_else_is_requested(\Closure $answer): void
    {
        $this->fakeAll([LoaderVersions::MOJANG_URL => $answer()]);

        try {
            (new LoaderVersions())->versions('forge');
            $this->fail('expected versions_unavailable');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('versions_unavailable', $exception->reason);
            $this->assertSame('piston-meta.mojang.com', $exception->detail);
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example'));
        $this->assertOnlyAllowlistedRequests();
    }

    public function test_offers_checks_a_choice_against_the_lists(): void
    {
        $this->fakeAll();
        $versions = new LoaderVersions();

        $this->assertTrue($versions->offers('fabric', '26.2', '0.20.1'));
        $this->assertFalse($versions->offers('fabric', '26.2', '0.20.0'), 'unstable loader build');
        $this->assertFalse($versions->offers('fabric', '26.2', null));
        $this->assertFalse($versions->offers('fabric', '26.4-snapshot-7', '0.20.1'));
        $this->assertFalse($versions->offers('fabric', '1.0', '0.20.1'));
        $this->assertTrue($versions->offers('quilt', '26.3', null));
        $this->assertFalse($versions->offers('quilt', '26.3', '0.1'));
        $this->assertTrue($versions->offers('neoforge', '1.21.1', '21.1.77'));
        $this->assertFalse($versions->offers('neoforge', '1.21.1', '21.1.77; rm -rf /'));
        $this->assertTrue($versions->offers('forge', '1.20.1', '1.20.1-47.3.0'));
        $this->assertFalse($versions->offers('forge', '1.20.1', '47.3.0'));
    }

    public function test_an_unknown_loader_requests_nothing(): void
    {
        $this->fakeAll();

        try {
            (new LoaderVersions())->versions('../paper');
            $this->fail('expected loader_unsupported');
        } catch (PackUnavailableException $exception) {
            $this->assertSame('loader_unsupported', $exception->reason);
        }

        Http::assertNothingSent();
    }
}
