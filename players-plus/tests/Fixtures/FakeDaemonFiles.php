<?php

namespace DrMxrcy\PlayersPlus\Tests\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Answers the daemon's list-directory and files/contents endpoints from an in-memory file
 * tree, the way Wings does (including its User-Agent, without which the panel reports a
 * 404 as a connection error).
 */
final class FakeDaemonFiles
{
    private const WINGS = ['User-Agent' => 'Pelican Wings/v1.0.0'];

    /**
     * @param  array<string, string>  $files  path => content
     * @param  array<string, int>  $sizes  path => size reported by the listing (default: real size)
     * @param  array<string, string>  $modified  path => RFC 3339 modification time reported by the listing
     */
    public static function respond(Request $request, array $files, array $sizes = [], array $modified = []): ?PromiseInterface
    {
        if (str_contains($request->url(), '/files/list-directory')) {
            $directory = trim((string) self::query($request, 'directory'), '/');
            $entries = [];
            foreach ($files as $path => $content) {
                $parent = dirname($path) === '.' ? '' : dirname($path);
                if ($parent === $directory) {
                    $entries[] = ['name' => basename($path), 'size' => $sizes[$path] ?? strlen($content), 'directory' => false, 'file' => true]
                        + (isset($modified[$path]) ? ['modified' => $modified[$path]] : []);
                }
            }

            return $entries === [] && $directory !== '' ? Http::response('', 404, self::WINGS) : Http::response($entries);
        }

        if (str_contains($request->url(), '/files/contents')) {
            $file = self::query($request, 'file');

            return $file !== null && isset($files[$file]) ? Http::response($files[$file]) : Http::response('', 404, self::WINGS);
        }

        return null;
    }

    public static function query(Request $request, string $key): ?string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return is_string($query[$key] ?? null) ? $query[$key] : null;
    }

    public static function contentReads(string $needle): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/files/contents') && str_contains((string) self::query($request, 'file'), $needle))->count();
    }

    public static function listings(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/files/list-directory'))->count();
    }
}
