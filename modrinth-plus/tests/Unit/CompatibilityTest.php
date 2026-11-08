<?php

namespace DrMxrcy\ModrinthPlus\Tests\Unit;

use DrMxrcy\ModrinthPlus\Support\Compatibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CompatibilityTest extends TestCase
{
    /** @return array{id: string, version_number: string} */
    private static function version(string $id, string $number): array
    {
        return ['id' => $id, 'version_number' => $number];
    }

    /** @return array<string, array{string, array<int, mixed>|null, string}> */
    public static function classifyCases(): array
    {
        return [
            'installed version is the newest compatible one' => ['v2', [self::version('v2', '2.0.0'), self::version('v1', '1.0.0')], Compatibility::COMPATIBLE],
            'installed version is an older compatible one' => ['v1', [self::version('v2', '2.0.0'), self::version('v1', '1.0.0')], Compatibility::COMPATIBLE],
            'installed version not built for this MC version, another is' => ['v0', [self::version('v2', '2.0.0')], Compatibility::NEEDS_UPDATE],
            'no compatible build' => ['v1', [], Compatibility::INCOMPATIBLE],
            'lookup failed' => ['v1', null, Compatibility::UNKNOWN],
            'malformed version entries never match' => ['v1', ['junk', ['version_number' => '1.0.0']], Compatibility::NEEDS_UPDATE],
            'empty installed version id never matches' => ['', [['version_number' => '1.0.0']], Compatibility::NEEDS_UPDATE],
        ];
    }

    /** @param  array<int, mixed>|null  $versions */
    #[DataProvider('classifyCases')]
    public function test_classify(string $installedVersionId, ?array $versions, string $expected): void
    {
        $this->assertSame($expected, Compatibility::classify($installedVersionId, $versions));
    }

    /** @return array{project_id: string, project_title: string, version_id: string, version_number: string} */
    private function mod(string $id, string $versionId = 'v1', string $title = ''): array
    {
        return ['project_id' => $id, 'project_title' => $title ?: ucfirst($id), 'version_id' => $versionId, 'version_number' => '1.0.0'];
    }

    public function test_report_covers_every_category(): void
    {
        $report = Compatibility::report(
            [$this->mod('lithium'), $this->mod('sodium'), $this->mod('gone'), $this->mod('failed'), $this->mod('missing')],
            [
                'lithium' => [self::version('v1', '1.0.0')],
                'sodium' => [self::version('v3', '3.0.0'), self::version('v2', '2.0.0')],
                'gone' => [],
                'failed' => null,
            ],
            loaderKnown: true,
        );

        $this->assertSame([
            ['project_id' => 'lithium', 'title' => 'Lithium', 'installed_version' => '1.0.0', 'status' => Compatibility::COMPATIBLE, 'newest_version' => null],
            ['project_id' => 'sodium', 'title' => 'Sodium', 'installed_version' => '1.0.0', 'status' => Compatibility::NEEDS_UPDATE, 'newest_version' => '3.0.0'],
            ['project_id' => 'gone', 'title' => 'Gone', 'installed_version' => '1.0.0', 'status' => Compatibility::INCOMPATIBLE, 'newest_version' => null],
            ['project_id' => 'failed', 'title' => 'Failed', 'installed_version' => '1.0.0', 'status' => Compatibility::UNKNOWN, 'newest_version' => null],
            ['project_id' => 'missing', 'title' => 'Missing', 'installed_version' => '1.0.0', 'status' => Compatibility::UNKNOWN, 'newest_version' => null],
        ], $report);
    }

    public function test_unknown_loader_reports_unknown_not_incompatible(): void
    {
        // getProjectVersionsBulk() returns [] for every id when the server has no loader.
        $report = Compatibility::report([$this->mod('lithium'), $this->mod('sodium')], ['lithium' => [], 'sodium' => []], loaderKnown: false);

        $this->assertSame([Compatibility::UNKNOWN, Compatibility::UNKNOWN], array_column($report, 'status'));
    }

    public function test_empty_project_id_is_unknown(): void
    {
        $report = Compatibility::report([$this->mod('', title: 'Broken')], ['' => []], loaderKnown: true);

        $this->assertSame(Compatibility::UNKNOWN, $report[0]['status']);
        $this->assertSame('Broken', $report[0]['title']);
    }

    public function test_title_falls_back_to_project_id(): void
    {
        $mod = $this->mod('lithium');
        $mod['project_title'] = '';

        $this->assertSame('lithium', Compatibility::report([$mod], ['lithium' => []], true)[0]['title']);
    }

    public function test_newest_version_is_null_when_malformed(): void
    {
        $report = Compatibility::report([$this->mod('sodium')], ['sodium' => [['id' => 'v9']]], true);

        $this->assertSame(Compatibility::NEEDS_UPDATE, $report[0]['status']);
        $this->assertNull($report[0]['newest_version']);
    }

    public function test_problems_drops_compatible_rows(): void
    {
        $report = Compatibility::report(
            [$this->mod('lithium'), $this->mod('gone'), $this->mod('failed')],
            ['lithium' => [self::version('v1', '1.0.0')], 'gone' => [], 'failed' => null],
            true,
        );

        $this->assertSame(['gone', 'failed'], array_column(Compatibility::problems($report), 'project_id'));
        $this->assertSame([], Compatibility::problems([$report[0]]));
    }

    public function test_empty_install_list_gives_empty_report(): void
    {
        $this->assertSame([], Compatibility::report([], [], true));
    }
}
