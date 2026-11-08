<?php

namespace DrMxrcy\ModrinthPlus\Tests\Unit;

use DrMxrcy\ModrinthPlus\Support\SearchFacets;
use PHPUnit\Framework\TestCase;

class SearchFacetsTest extends TestCase
{
    private const PROJECT_TYPES = ['project_type:mod', 'project_type:plugin'];

    private const SERVER_SIDES = ['server_side:required', 'server_side:optional', 'server_side:unknown'];

    /** @return array<int, array<int, string>> */
    private function decode(string $facets): array
    {
        /** @var array<int, array<int, string>> $decoded */
        $decoded = json_decode($facets, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function test_loader_only(): void
    {
        $this->assertSame(
            [['categories:fabric'], self::PROJECT_TYPES],
            $this->decode(SearchFacets::build('fabric', null, false)),
        );
    }

    public function test_loader_and_version(): void
    {
        $this->assertSame(
            [['categories:fabric'], ['versions:26.3'], self::PROJECT_TYPES],
            $this->decode(SearchFacets::build('fabric', '26.3', false)),
        );
    }

    public function test_hide_client_only_adds_server_side_group(): void
    {
        $this->assertSame(
            [['categories:fabric'], ['versions:26.3'], self::PROJECT_TYPES, self::SERVER_SIDES],
            $this->decode(SearchFacets::build('fabric', '26.3', true)),
        );
    }

    public function test_hide_client_only_off_has_no_server_side_facet(): void
    {
        $this->assertStringNotContainsString('server_side', SearchFacets::build('fabric', '26.3', false));
    }

    public function test_hidden_server_side_values_never_include_unsupported(): void
    {
        $this->assertStringNotContainsString('unsupported', SearchFacets::build('fabric', '26.3', true));
    }

    public function test_proxy_server_without_version(): void
    {
        $this->assertSame(
            [['categories:velocity'], self::PROJECT_TYPES, self::SERVER_SIDES],
            $this->decode(SearchFacets::build('velocity', null, true)),
        );
    }

    public function test_empty_version_is_treated_as_no_version(): void
    {
        $this->assertSame(SearchFacets::build('paper', null, true), SearchFacets::build('paper', '', true));
    }

    public function test_values_are_json_escaped(): void
    {
        $this->assertSame(
            [['categories:fab"ric'], ['versions:1.21\\x'], self::PROJECT_TYPES],
            $this->decode(SearchFacets::build('fab"ric', '1.21\\x', false)),
        );
    }

    public function test_output_is_compact_json(): void
    {
        $this->assertSame(
            '[["categories:fabric"],["project_type:mod","project_type:plugin"]]',
            SearchFacets::build('fabric', null, false),
        );
    }

    public function test_categories_form_their_own_or_group(): void
    {
        $this->assertSame(
            [['categories:fabric'], ['versions:26.3'], self::PROJECT_TYPES, ['categories:optimization', 'categories:utility']],
            $this->decode(SearchFacets::build('fabric', '26.3', false, categories: ['optimization', 'utility'])),
        );
    }

    public function test_categories_keep_loader_version_and_server_side_groups(): void
    {
        $facets = $this->decode(SearchFacets::build('fabric', '26.3', true, categories: ['optimization']));

        $this->assertContains(['categories:fabric'], $facets);
        $this->assertContains(['versions:26.3'], $facets);
        $this->assertContains(self::SERVER_SIDES, $facets);
        $this->assertContains(['categories:optimization'], $facets);
    }

    public function test_categories_never_share_the_loader_group(): void
    {
        $facets = $this->decode(SearchFacets::build('fabric', null, false, categories: ['optimization']));

        $this->assertSame(['categories:fabric'], $facets[0]);
        foreach ($facets as $group) {
            $this->assertFalse(in_array('categories:fabric', $group, true) && in_array('categories:optimization', $group, true));
        }
    }

    public function test_no_categories_adds_no_group(): void
    {
        $this->assertSame(SearchFacets::build('fabric', '26.3', true), SearchFacets::build('fabric', '26.3', true, categories: []));
    }

    public function test_categories_are_normalized(): void
    {
        $this->assertSame(['optimization', 'utility'], SearchFacets::normalizeCategories(['utility', '', 'optimization', 'utility', 7, null]));
        $this->assertSame(
            SearchFacets::build('fabric', null, false, categories: ['optimization', 'utility']),
            SearchFacets::build('fabric', null, false, categories: ['utility', 'optimization', 'utility', '']),
        );
    }
}
