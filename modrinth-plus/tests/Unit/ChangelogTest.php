<?php

namespace DrMxrcy\ModrinthPlus\Tests\Unit;

use DrMxrcy\ModrinthPlus\Support\Changelog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 17: picking the versions an update skips over and rendering their (untrusted, markdown)
 * changelogs safely.
 */
class ChangelogTest extends TestCase
{
    /** @return list<array{id: string, version_number: string}> newest first, v{$count} .. v1 */
    private static function versions(int $count): array
    {
        $versions = [];
        for ($i = $count; $i >= 1; $i--) {
            $versions[] = ['id' => "v$i", 'version_number' => "$i.0.0"];
        }

        return $versions;
    }

    public function test_between_returns_every_newer_version_newest_first(): void
    {
        $between = Changelog::between(self::versions(4), 'v1');

        $this->assertSame(['v4', 'v3', 'v2'], array_column($between['versions'], 'id'));
        $this->assertSame(0, $between['more']);
    }

    public function test_between_caps_at_the_limit_and_counts_the_rest(): void
    {
        $between = Changelog::between(self::versions(14), 'v1');

        $this->assertSame(['v13', 'v12'], array_slice(array_column($between['versions'], 'id'), 1, 2));
        $this->assertCount(Changelog::LIMIT, $between['versions']);
        $this->assertSame('v14', $between['versions'][0]['id']);
        $this->assertSame(3, $between['more']);
    }

    public function test_between_is_empty_when_installed_is_the_newest(): void
    {
        $this->assertSame(['versions' => [], 'more' => 0], Changelog::between(self::versions(3), 'v3'));
    }

    public function test_between_shows_only_the_target_when_the_installed_version_is_not_listed(): void
    {
        // Installed for another Minecraft version: which listed versions are "newer" is unknown.
        $between = Changelog::between(self::versions(3), 'other');

        $this->assertSame(['v3'], array_column($between['versions'], 'id'));
        $this->assertSame(0, $between['more']);
    }

    public function test_between_skips_malformed_entries(): void
    {
        $between = Changelog::between([['id' => 'v3'], 'junk', ['id' => 'v2'], ['id' => 'v1']], 'v1');

        $this->assertSame(['v3', 'v2'], array_column($between['versions'], 'id'));
    }

    public function test_between_of_no_versions_is_empty(): void
    {
        $this->assertSame(['versions' => [], 'more' => 0], Changelog::between([], 'v1'));
    }

    public function test_render_turns_markdown_into_html(): void
    {
        $html = Changelog::render("- Fixed **crash**\n- Added `thing`")->toHtml();

        $this->assertStringContainsString('<li>Fixed <strong>crash</strong></li>', $html);
        $this->assertStringContainsString('<code>thing</code>', $html);
    }

    /** @return array<string, array{?string}> */
    public static function emptyChangelogs(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ["  \n "],
            // Raw HTML is stripped, so these render to nothing: show the fallback, not a blank entry.
            'html comment only' => ['<!-- x -->'],
            'script only' => ['<script>alert(1)</script>'],
            'div only' => ['<div class="x">  </div>'],
            'html only, several blocks' => ["<div>a</div>\n\n<p>b</p>\n\n<!-- c -->"],
        ];
    }

    #[DataProvider('emptyChangelogs')]
    public function test_render_returns_null_for_an_empty_changelog(?string $changelog): void
    {
        $this->assertNull(Changelog::render($changelog));
    }

    /** @return array<string, array{string, list<string>}> input, strings that must not appear in the output */
    public static function hostileChangelogs(): array
    {
        return [
            'script tag' => ['Hi <script>alert(1)</script> there', ['<script', '</script']],
            'script block' => ["<script>\nalert(1)\n</script>", ['<script', '</script']],
            'img onerror' => ['<img src=x onerror="alert(1)">', ['<img', 'onerror']],
            'raw html block' => ["<div onclick=\"alert(1)\">x</div>\n\n<iframe src=\"https://evil.test\"></iframe>", ['<div', 'onclick', '<iframe']],
            // Harmless as visible text; what matters is that no href/src carries the scheme.
            'javascript link' => ['[click](javascript:alert(1))', ['href="javascript:', 'javascript:alert']],
            'javascript autolink' => ['<javascript:alert(1)>', ['href="javascript:']],
            'javascript reference link' => ["[click][x]\n\n[x]: javascript:alert(1)", ['href="javascript:']],
            'vbscript link' => ['[click](vbscript:msgbox(1))', ['href="vbscript:']],
            'data link' => ['[click](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)', ['href="data:']],
            'javascript image' => ['![x](javascript:alert(1))', ['src="javascript:']],
            'entity-encoded javascript scheme' => ['[click](&#106;avascript:alert(1))', ['href="javascript:', 'href="&#106;']],
            'hex entity-encoded javascript scheme' => ['[click](&#x6A;avascript:alert(1))', ['href="javascript:', 'href="&#x6a;']],
            'quote injection in a link title' => ['[click](https://example.com "a\" onmouseover=\"alert(1)")', ['" onmouseover="', '"onmouseover="']],
            'quote injection in a link url' => ['[click](<https://example.com/" onmouseover="alert(1)>)', ['" onmouseover="']],
            'quote injection in an image url' => ['![x](<https://example.com/a.png" onerror="alert(1)>)', ['" onerror="']],
            'data image' => ['![x](data:image/png;base64,iVBORw0KGgo=)', ['<img', 'data:image']],
            'mailto link' => ['[mail](mailto:someone@example.com)', ['<a ', 'href=']],
            'email autolink' => ['someone@example.com', ['<a ', 'href=']],
            'relative link' => ['[admin](/admin)', ['<a ', 'href=']],
            'fragment link' => ['[x](#top)', ['<a ', 'href=']],
            'protocol-relative link' => ['[x](//evil.example/x)', ['<a ', 'href=']],
            'relative image' => ['![x](/storage/x.png)', ['<img']],
            'inline style' => ['<span style="position:fixed">x</span>', ['<span', 'style=']],
        ];
    }

    /** @param  list<string>  $forbidden */
    #[DataProvider('hostileChangelogs')]
    public function test_render_strips_raw_html_and_unsafe_links(string $changelog, array $forbidden): void
    {
        $html = strtolower(Changelog::render($changelog)?->toHtml() ?? '');

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_render_opens_links_in_a_new_tab_without_referrer(): void
    {
        $html = Changelog::render('See [the wiki](https://example.com/wiki).')->toHtml();

        $this->assertStringContainsString('href="https://example.com/wiki"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/rel="[^"]*noreferrer[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/rel="[^"]*nofollow[^"]*"/', $html);
    }

    public function test_unlinked_text_survives(): void
    {
        $html = Changelog::render('Go to [the admin page](/admin) or mail someone@example.com.')->toHtml();

        $this->assertStringContainsString('the admin page', $html);
        $this->assertStringContainsString('someone@example.com', $html);
    }

    public function test_remote_images_stay_but_are_constrained(): void
    {
        $html = Changelog::render('![shot](https://cdn.example.com/shot.png)')->toHtml();

        $this->assertStringContainsString('src="https://cdn.example.com/shot.png"', $html);
        $this->assertStringContainsString('max-width: 100%', $html);
        $this->assertStringContainsString('height: auto', $html);
    }

    public function test_an_image_only_changelog_is_not_empty(): void
    {
        $this->assertNotNull(Changelog::render('![shot](https://cdn.example.com/shot.png)'));
    }

    public function test_every_link_opens_in_a_new_tab(): void
    {
        $html = Changelog::render("[a](https://a.example) and <https://b.example> and www.c.example\n\n[d][r]\n\n[r]: http://d.example")->toHtml();

        preg_match_all('/<a [^>]*>/', $html, $tags);
        $this->assertCount(4, $tags[0]);
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('target="_blank"', $tag);
            $this->assertMatchesRegularExpression('/rel="(?=[^"]*nofollow)(?=[^"]*noopener)(?=[^"]*noreferrer)[^"]*"/', $tag);
        }
    }

    /** @return array<string, array{string, list<string>}> a hostile tail cut by the truncation, strings that must not appear */
    public static function cutConstructs(): array
    {
        return [
            'cut inside a script tag' => ['<script>alert(1)</script>', ['<script']],
            'cut inside an img tag' => ['<img src=x onerror="alert(1)">', ['<img', 'onerror']],
            'cut inside a javascript link' => ['[x](javascript:alert(1))', ['href="javascript:', '<a ']],
            'cut inside a link url' => ['[x](https://example.com/a-very-long-path)', ['<a ']],
        ];
    }

    /** @param  list<string>  $forbidden */
    #[DataProvider('cutConstructs')]
    public function test_truncation_inside_a_construct_stays_safe(string $tail, array $forbidden): void
    {
        // The cut lands a few bytes into $tail.
        $html = strtolower(Changelog::render(str_repeat('a', Changelog::MAX_BYTES - 6) . ' ' . $tail)->toHtml());

        $this->assertStringContainsString('…', $html);
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_render_truncates_long_changelogs(): void
    {
        $html = Changelog::render(str_repeat('a', Changelog::MAX_BYTES + 5000))->toHtml();

        $this->assertStringContainsString('…', $html);
        $this->assertLessThan(Changelog::MAX_BYTES + 100, strlen($html));
    }

    public function test_truncation_never_splits_a_multibyte_character(): void
    {
        $html = Changelog::render(str_repeat('ä', Changelog::MAX_BYTES))->toHtml();

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        $this->assertStringEndsWith('…</p>', trim($html));
    }
}
