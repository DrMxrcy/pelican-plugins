<?php

namespace DrMxrcy\ModrinthPlus\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;

/**
 * Changelogs shown before an update (plan 17): which versions an update skips over, and their
 * changelogs rendered for the page.
 *
 * A changelog is markdown written by the project's author, so it is untrusted. It is rendered by
 * CommonMark with raw HTML stripped (`html_input: strip`) and unsafe link schemes dropped
 * (`allow_unsafe_links: false`: javascript:, vbscript:, file: and non-image data:), so the only
 * HTML on the page is what CommonMark itself writes for markdown syntax, with its text escaped.
 */
final class Changelog
{
    /** How many changelogs the update dialog shows at most. */
    public const LIMIT = 10;

    /** Longer changelogs are cut (in bytes of markdown) and end in "…". */
    public const MAX_BYTES = 20_000;

    /**
     * The versions an update from the installed version to the newest one brings in, newest
     * first, capped at $limit; `more` counts the ones left out.
     *
     * $versions is the compatible version list, newest first, as getProjectVersions() returns it.
     * When the installed version isn't in that list (installed for another Minecraft version or
     * loader) which versions are newer is unknown, so only the update's target is returned.
     *
     * @param  array<int, mixed>  $versions
     * @return array{versions: list<array<string, mixed>>, more: int}
     */
    public static function between(array $versions, ?string $installedVersionId, int $limit = self::LIMIT): array
    {
        $versions = array_values(array_filter($versions, fn ($version) => is_array($version) && is_string($version['id'] ?? null)));

        $installedIndex = array_search($installedVersionId, array_column($versions, 'id'), true);

        $newer = $installedIndex === false ? array_slice($versions, 0, 1) : array_slice($versions, 0, $installedIndex);

        return [
            'versions' => array_slice($newer, 0, max(0, $limit)),
            'more' => max(0, count($newer) - max(0, $limit)),
        ];
    }

    /**
     * The changelog as safe HTML, or null when there is none: Modrinth often sends "" or null,
     * and a changelog of only raw HTML renders to nothing once that is stripped.
     */
    public static function render(?string $changelog): ?HtmlString
    {
        if ($changelog === null || trim($changelog) === '') {
            return null;
        }

        if (strlen($changelog) > self::MAX_BYTES) {
            // mb_strcut never splits a multibyte character.
            $changelog = rtrim(mb_strcut($changelog, 0, self::MAX_BYTES, 'UTF-8')) . '…';
        }

        $html = Str::markdown($changelog, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'external_link' => [
                // Only absolute http(s) links are left (see webLinksOnly()), all of them external:
                // new tab, no opener, no referrer, nofollow.
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ], [new ExternalLinkExtension(), self::webLinksOnly()]);

        // An image alone is content; anything else needs visible text.
        if (trim(html_entity_decode(strip_tags($html, '<img>'))) === '') {
            return null;
        }

        return new HtmlString($html);
    }

    /**
     * Keeps only absolute http(s) links and images. Relative, fragment, protocol-relative,
     * mailto:, data: and every other scheme (including entity-encoded ones, decoded by the parser
     * before this runs) lose the link and keep their text (an image keeps its alt text), so
     * nothing in a changelog can navigate the panel tab or point at the panel itself.
     * Remote images still load from the author's host, as on Modrinth, but never wider than the
     * dialog.
     */
    private static function webLinksOnly(): ExtensionInterface
    {
        return new class implements ExtensionInterface
        {
            public function register(EnvironmentBuilderInterface $environment): void
            {
                $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
                    $resources = [];
                    foreach ($event->getDocument()->iterator() as $node) {
                        if ($node instanceof Link || $node instanceof Image) {
                            $resources[] = $node;
                        }
                    }

                    foreach ($resources as $node) {
                        if (preg_match('#^https?://[^/\\\\]#i', $node->getUrl()) === 1) {
                            if ($node instanceof Image) {
                                $node->data->set('attributes/style', 'max-width: 100%; height: auto;');
                            }

                            continue;
                        }

                        foreach (iterator_to_array($node->children(), false) as $child) {
                            $node->insertBefore($child);
                        }
                        $node->detach();
                    }
                });
            }
        };
    }
}
