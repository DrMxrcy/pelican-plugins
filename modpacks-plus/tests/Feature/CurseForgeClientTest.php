<?php

namespace DrMxrcy\ModpacksPlus\Tests\Feature;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\ModpacksPlusPlugin;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Tests\Support\RecordingPlugin;
use DrMxrcy\ModpacksPlus\Tests\Support\SettingsFormHarness;
use DrMxrcy\ModpacksPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan 31 step 1: the CurseForge API key. It is only sent to api.curseforge.com, never
 * follows a redirect, and never shows up in exceptions, logs, dumps or the settings form.
 */
class CurseForgeClientTest extends TestCase
{
    use RefreshDatabase;

    public const KEY = '$2a$10$SECRETkeyDoNotLeakAbcdefghijklmnopqrstuvwxyz0123';

    /** @var array<int, string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    private function client(): CurseForgeClient
    {
        return new CurseForgeClient(self::KEY);
    }

    private function assertNoLeak(string $haystack, string $where): void
    {
        $this->assertStringNotContainsString(self::KEY, $haystack, "API key leaked into $where");
        // Not even a recognisable part of it.
        $this->assertStringNotContainsString('SECRETkeyDoNotLeak', $haystack, "API key leaked into $where");
    }

    private function assertExceptionClean(\Throwable $exception): void
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $this->assertNoLeak($current->getMessage(), 'an exception message');
            $this->assertNoLeak($current->getTraceAsString(), 'an exception trace');
            if ($current instanceof PackUnavailableException) {
                $this->assertNoLeak($current->detail, 'an exception detail');
            }
        }
        $this->assertNull($exception->getPrevious(), 'transport exceptions (with the request and its headers) are never chained');
    }

    private function catchUnavailable(callable $callback): PackUnavailableException
    {
        try {
            $callback();
        } catch (PackUnavailableException $exception) {
            return $exception;
        }

        $this->fail('Expected PackUnavailableException');
    }

    public function test_the_key_is_sent_only_to_the_curseforge_api(): void
    {
        Http::fake(['api.curseforge.com/v1/*' => Http::response(['data' => []]), '*' => Http::response('unexpected', 500)]);

        $this->client()->get('mods/search', ['gameId' => 432]);
        $this->client()->post('mods/files', ['fileIds' => [1, 2]]);

        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $this->assertStringStartsWith('https://api.curseforge.com/v1/', $request->url());
            $this->assertSame([self::KEY], $request->header('x-api-key'));
        }
    }

    /** @return array<string, array{string}> */
    public static function hostilePaths(): array
    {
        return [
            'absolute url' => ['https://evil.example/v1/mods'],
            'scheme relative' => ['//evil.example/mods'],
            'parent' => ['../mods'],
            'nested parent' => ['mods/../../evil'],
            'query' => ['mods?x=1'],
            'encoded' => ['mods/%2e%2e'],
            'space' => ['mods/1 2'],
            'userinfo' => ['mods@evil.example'],
            'empty' => [''],
            'leading slash' => ['/mods'],
        ];
    }

    #[DataProvider('hostilePaths')]
    public function test_hostile_paths_never_reach_the_network(string $path): void
    {
        Http::fake();

        $exception = $this->catchUnavailable(fn () => $this->client()->get($path));

        $this->assertSame('curseforge_unavailable', $exception->reason);
        Http::assertNothingSent();
    }

    public function test_redirects_are_never_followed(): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/1' => Http::response('', 302, ['Location' => 'http://evil.example/steal']),
            '*' => Http::response(['data' => []]),
        ]);

        $exception = $this->catchUnavailable(fn () => $this->client()->get('mods/1'));

        $this->assertSame('curseforge_unavailable', $exception->reason);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example'));
    }

    public function test_transport_errors_never_carry_the_key(): void
    {
        // A transport error that (like some do) quotes the request headers.
        Http::fake(fn () => throw new \RuntimeException('cURL error: x-api-key: '.self::KEY));

        $exception = $this->catchUnavailable(fn () => $this->client()->get('mods/1'));

        $this->assertSame('curseforge_unavailable', $exception->reason);
        $this->assertExceptionClean($exception);

        report($exception);
        foreach ($this->logged as $line) {
            $this->assertNoLeak($line, 'the log');
        }
    }

    /** @return array<string, array{int, string}> */
    public static function failures(): array
    {
        return [
            'unauthorised' => [401, 'curseforge_key_rejected'],
            'forbidden' => [403, 'curseforge_key_rejected'],
            'not found' => [404, 'curseforge_not_found'],
            'rate limited' => [429, 'curseforge_unavailable'],
            'server error' => [500, 'curseforge_unavailable'],
            'bad gateway' => [502, 'curseforge_unavailable'],
        ];
    }

    #[DataProvider('failures')]
    public function test_error_responses_map_to_reasons_without_the_key(int $status, string $reason): void
    {
        // A hostile or broken API echoing the key back in its body.
        Http::fake(['*' => Http::response('{"error":"bad key '.self::KEY.'"}', $status)]);

        $exception = $this->catchUnavailable(fn () => $this->client()->get('mods/1'));

        $this->assertSame($reason, $exception->reason);
        $this->assertExceptionClean($exception);
    }

    public function test_without_a_key_nothing_is_requested(): void
    {
        Http::fake();
        config()->set('modpacks-plus.curseforge.api_key', null);

        $client = CurseForgeClient::fromConfig();
        $this->assertFalse($client->configured());
        $this->assertSame('curseforge_not_configured', $this->catchUnavailable(fn () => $client->get('mods/1'))->reason);

        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function badKeys(): array
    {
        return [
            'newline (env injection)' => ["abcdefghijklmnop\nAPP_KEY=x"],
            'quote' => ['abcdefghijklmnop"x'],
            'space' => ['abcdefgh ijklmnop'],
            'too short' => ['abc'],
            'brace (env interpolation)' => ['abcdefghijklmnop${APP_KEY}'],
        ];
    }

    #[DataProvider('badKeys')]
    public function test_malformed_keys_are_never_used(string $key): void
    {
        Http::fake();
        config()->set('modpacks-plus.curseforge.api_key', $key);

        $this->assertFalse(CurseForgeClient::isValidKey($key));
        $this->assertFalse(CurseForgeClient::fromConfig()->configured());
        Http::assertNothingSent();
    }

    public function test_dumps_never_show_the_key(): void
    {
        $this->assertNoLeak(print_r($this->client(), true), 'print_r');

        ob_start();
        var_dump($this->client());
        $this->assertNoLeak((string) ob_get_clean(), 'var_dump');
    }

    public function test_settings_form_data_never_contains_the_key(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', self::KEY);

        $data = (new ModpacksPlusPlugin())->getSettingsFormData();

        $this->assertArrayHasKey('curseforge_api_key', $data);
        $this->assertNull($data['curseforge_api_key']);
        $this->assertNoLeak((string) json_encode($data), 'the settings form data');
        $this->assertTrue(ModpacksPlusPlugin::curseForgeKeySaved());
    }

    public function test_the_rendered_settings_form_never_shows_the_key(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', self::KEY);
        Filament::setCurrentPanel('admin');

        $component = Livewire::test(SettingsFormHarness::class);

        $this->assertNoLeak($component->html(), 'the rendered settings form');
        $this->assertNoLeak((string) json_encode($component->get('data')), 'the settings form state');
        $component->assertSee(trans('modpacks-plus::strings.settings.curseforge_api_key_placeholder'));
        $component->assertSee(trans('modpacks-plus::strings.settings.curseforge_api_key_remove'));

        // Even a form filled with the key (a future bug) renders empty.
        $component->set('data.curseforge_api_key', null);
        $this->assertNoLeak($component->html(), 'the rendered settings form');

        Filament::setCurrentPanel(null);
    }

    public function test_the_form_says_nothing_is_saved_without_a_key(): void
    {
        config()->set('modpacks-plus.curseforge.api_key', null);
        Filament::setCurrentPanel('admin');

        Livewire::test(SettingsFormHarness::class)
            ->assertDontSee(trans('modpacks-plus::strings.settings.curseforge_api_key_placeholder'))
            ->assertDontSee(trans('modpacks-plus::strings.settings.curseforge_api_key_remove'));

        Filament::setCurrentPanel(null);
    }

    private function recordingPlugin(): RecordingPlugin
    {
        return new RecordingPlugin();
    }

    public function test_saving_a_blank_key_keeps_the_saved_one(): void
    {
        $plugin = $this->recordingPlugin();

        $plugin->saveSettings(['curseforge_api_key' => null]);
        $this->assertArrayNotHasKey(CurseForgeClient::KEY_ENV, $plugin->written);

        $plugin->saveSettings(['curseforge_api_key' => '   ']);
        $this->assertArrayNotHasKey(CurseForgeClient::KEY_ENV, $plugin->written);
    }

    public function test_saving_a_new_key_writes_it_prefixed(): void
    {
        $plugin = $this->recordingPlugin();

        $plugin->saveSettings(['curseforge_api_key' => '  '.self::KEY.' ']);

        $this->assertSame(self::KEY, $plugin->written[CurseForgeClient::KEY_ENV] ?? null);
        $this->assertSame('MODPACKS_PLUS_CURSEFORGE_API_KEY', CurseForgeClient::KEY_ENV);
        foreach ($this->logged as $line) {
            $this->assertNoLeak($line, 'the log');
        }
    }

    public function test_a_malformed_key_is_not_saved_or_echoed(): void
    {
        $plugin = $this->recordingPlugin();
        $bad = "SECRETkeyDoNotLeak\nAPP_DEBUG=true";

        $plugin->saveSettings(['curseforge_api_key' => $bad]);

        $this->assertArrayNotHasKey(CurseForgeClient::KEY_ENV, $plugin->written);
        // The notice is a fixed text: the rejected value is never echoed.
        Notification::assertNotified(trans('modpacks-plus::strings.settings.curseforge_api_key_invalid'));
        $this->assertStringNotContainsString('SECRETkeyDoNotLeak', trans('modpacks-plus::strings.settings.curseforge_api_key_invalid'));
    }

    public function test_removing_the_key_clears_it(): void
    {
        $plugin = $this->recordingPlugin();

        $plugin->saveSettings(['curseforge_api_key' => self::KEY, 'curseforge_api_key_remove' => true]);

        $this->assertSame('', $plugin->written[CurseForgeClient::KEY_ENV] ?? null);
    }
}
