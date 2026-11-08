<?php

namespace DrMxrcy\ModrinthPlus\Tests\Unit;

use DrMxrcy\ModrinthPlus\Support\DependencyResolver;
use PHPUnit\Framework\TestCase;

class DependencyResolverTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $dependencies
     * @return array<string, mixed>
     */
    private static function version(string $id, string $projectId, array $dependencies = []): array
    {
        return ['id' => $id, 'project_id' => $projectId, 'version_number' => $id, 'dependencies' => $dependencies];
    }

    /** @return array<string, mixed> */
    private static function dep(?string $projectId, string $type = 'required', ?string $versionId = null, ?string $fileName = null): array
    {
        return ['project_id' => $projectId, 'version_id' => $versionId, 'file_name' => $fileName, 'dependency_type' => $type];
    }

    /**
     * A lookup over a fixed catalogue that records which projects were asked for.
     *
     * @param  array<string, array<int, array<string, mixed>>|null>  $catalogue
     * @param  array<int, string>  $calls
     */
    private static function lookup(array $catalogue, array &$calls = []): \Closure
    {
        return function (string $projectId) use ($catalogue, &$calls) {
            $calls[] = $projectId;

            return array_key_exists($projectId, $catalogue) ? $catalogue[$projectId] : [];
        };
    }

    /**
     * @param  array{install: array<int, array{project_id: string, version: array<string, mixed>, required_by: string}>, unresolved: array<int, array<string, mixed>>}  $result
     * @return array<int, string>
     */
    private static function installedPairs(array $result): array
    {
        return array_map(fn (array $entry) => "{$entry['project_id']}@{$entry['version']['id']}<{$entry['required_by']}", $result['install']);
    }

    public function test_no_dependencies(): void
    {
        $calls = [];
        $result = DependencyResolver::resolve('root', self::version('r1', 'root'), self::lookup([], $calls), []);

        $this->assertSame([], $result['install']);
        $this->assertSame([], $result['unresolved']);
        $this->assertSame([], $calls);
    }

    public function test_missing_dependencies_key_counts_as_none(): void
    {
        $result = DependencyResolver::resolve('root', ['id' => 'r1'], self::lookup([]), []);

        $this->assertSame(['install' => [], 'unresolved' => []], $result);
    }

    public function test_one_level_picks_the_newest_compatible_version(): void
    {
        $root = self::version('r1', 'root', [self::dep('fabric-api')]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([
            'fabric-api' => [self::version('f2', 'fabric-api'), self::version('f1', 'fabric-api')],
        ]), []);

        $this->assertSame(['fabric-api@f2<root'], self::installedPairs($result));
        $this->assertSame([], $result['unresolved']);
    }

    public function test_optional_embedded_and_incompatible_dependencies_are_ignored(): void
    {
        $calls = [];
        $root = self::version('r1', 'root', [
            self::dep('opt', 'optional'),
            self::dep('emb', 'embedded'),
            self::dep('inc', 'incompatible'),
        ]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([
            'opt' => [self::version('o1', 'opt')],
            'emb' => [self::version('e1', 'emb')],
            'inc' => [self::version('i1', 'inc')],
        ], $calls), []);

        $this->assertSame(['install' => [], 'unresolved' => []], $result);
        $this->assertSame([], $calls);
    }

    public function test_transitive_dependencies_are_installed_in_order(): void
    {
        $root = self::version('r1', 'root', [self::dep('a')]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([
            'a' => [self::version('a1', 'a', [self::dep('b')])],
            'b' => [self::version('b1', 'b', [self::dep('c')])],
            'c' => [self::version('c1', 'c')],
        ]), []);

        $this->assertSame(['a@a1<root', 'b@b1<a', 'c@c1<b'], self::installedPairs($result));
    }

    public function test_cycle_terminates_and_never_reinstalls_the_root(): void
    {
        $calls = [];
        $root = self::version('r1', 'root', [self::dep('a')]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([
            'a' => [self::version('a1', 'a', [self::dep('b'), self::dep('root')])],
            'b' => [self::version('b1', 'b', [self::dep('a')])],
        ], $calls), []);

        $this->assertSame(['a@a1<root', 'b@b1<a'], self::installedPairs($result));
        $this->assertSame(['a', 'b'], $calls);
    }

    public function test_the_same_dependency_required_twice_is_installed_once(): void
    {
        $root = self::version('r1', 'root', [self::dep('a'), self::dep('b'), self::dep('a')]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([
            'a' => [self::version('a1', 'a')],
            'b' => [self::version('b1', 'b', [self::dep('a')])],
        ]), []);

        $this->assertSame(['a@a1<root', 'b@b1<root'], self::installedPairs($result));
    }

    public function test_a_shared_dependency_records_every_parent_that_requires_it(): void
    {
        // Diamond: root needs a and c, both need b. b is installed once but both parents are kept,
        // so the page can still install b when a turns out to be unusable.
        $root = self::version('r1', 'root', [self::dep('a'), self::dep('c')]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([
            'a' => [self::version('a1', 'a', [self::dep('b')])],
            'c' => [self::version('c1', 'c', [self::dep('b'), self::dep(null, fileName: 'lib.jar'), self::dep('down')])],
            'b' => [self::version('b1', 'b', [self::dep(null, fileName: 'lib.jar'), self::dep('down')])],
            'down' => null,
        ]), []);

        $this->assertSame(['a@a1<root', 'c@c1<root', 'b@b1<a'], self::installedPairs($result));
        $this->assertSame(
            ['a' => ['root'], 'c' => ['root'], 'b' => ['a', 'c']],
            array_column($result['install'], 'required_by_all', 'project_id'),
        );
        $this->assertSame(
            [['lib.jar', ['c', 'b']], ['down', ['c', 'b']]],
            array_map(fn (array $entry) => [$entry['file_name'] ?? $entry['project_id'], $entry['required_by_all']], $result['unresolved']),
        );
    }

    public function test_already_installed_dependencies_are_skipped_without_a_lookup(): void
    {
        $calls = [];
        $root = self::version('r1', 'root', [self::dep('fabric-api'), self::dep('cloth')]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([
            'fabric-api' => [self::version('f1', 'fabric-api')],
            'cloth' => [self::version('c1', 'cloth')],
        ], $calls), ['fabric-api']);

        $this->assertSame(['cloth@c1<root'], self::installedPairs($result));
        $this->assertSame(['cloth'], $calls);
    }

    public function test_pinned_version_id_is_preferred_when_compatible(): void
    {
        $root = self::version('r1', 'root', [self::dep('a', versionId: 'a1')]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([
            'a' => [self::version('a3', 'a'), self::version('a2', 'a'), self::version('a1', 'a')],
        ]), []);

        $this->assertSame(['a@a1<root'], self::installedPairs($result));
    }

    public function test_pinned_version_id_that_is_not_compatible_falls_back_to_the_newest(): void
    {
        $root = self::version('r1', 'root', [self::dep('a', versionId: 'old-for-other-mc')]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([
            'a' => [self::version('a3', 'a'), self::version('a2', 'a')],
        ]), []);

        $this->assertSame(['a@a3<root'], self::installedPairs($result));
    }

    public function test_file_name_only_and_version_id_only_dependencies_are_manual(): void
    {
        $calls = [];
        $root = self::version('r1', 'root', [
            self::dep(null, fileName: 'some-lib.jar'),
            self::dep(null, versionId: 'v-only'),
            self::dep(null, fileName: 'some-lib.jar'),
        ]);

        $result = DependencyResolver::resolve('root', $root, self::lookup([], $calls), []);

        $this->assertSame([], $result['install']);
        $this->assertSame([
            ['project_id' => null, 'version_id' => null, 'file_name' => 'some-lib.jar', 'reason' => DependencyResolver::REASON_MANUAL, 'required_by' => 'root', 'required_by_all' => ['root']],
            ['project_id' => null, 'version_id' => 'v-only', 'file_name' => null, 'reason' => DependencyResolver::REASON_MANUAL, 'required_by' => 'root', 'required_by_all' => ['root']],
        ], $result['unresolved']);
        $this->assertSame([], $calls);
    }

    public function test_no_compatible_version_and_failed_lookups_are_unresolved(): void
    {
        $root = self::version('r1', 'root', [self::dep('none'), self::dep('down')]);

        $result = DependencyResolver::resolve('root', $root, self::lookup(['none' => [], 'down' => null]), []);

        $this->assertSame([], $result['install']);
        $this->assertSame(
            [['none', DependencyResolver::REASON_NO_COMPATIBLE_VERSION], ['down', DependencyResolver::REASON_LOOKUP_FAILED]],
            array_map(fn (array $entry) => [$entry['project_id'], $entry['reason']], $result['unresolved']),
        );
    }

    public function test_cap_stops_runaway_graphs(): void
    {
        $calls = [];
        $catalogue = [];
        for ($i = 0; $i < 40; $i++) {
            $catalogue["p$i"] = [self::version("v$i", "p$i", [self::dep('p'.($i + 1))])];
        }

        $result = DependencyResolver::resolve('root', self::version('r1', 'root', [self::dep('p0')]), self::lookup($catalogue, $calls), [], 3);

        $this->assertSame(['p0@v0<root', 'p1@v1<p0', 'p2@v2<p1'], self::installedPairs($result));
        $this->assertSame([['p3', DependencyResolver::REASON_LIMIT]], array_map(fn (array $entry) => [$entry['project_id'], $entry['reason']], $result['unresolved']));
        $this->assertSame(['p0', 'p1', 'p2'], $calls);
    }

    public function test_default_cap_is_25(): void
    {
        $catalogue = [];
        $deps = [];
        for ($i = 0; $i < 30; $i++) {
            $catalogue["p$i"] = [self::version("v$i", "p$i")];
            $deps[] = self::dep("p$i");
        }

        $result = DependencyResolver::resolve('root', self::version('r1', 'root', $deps), self::lookup($catalogue), []);

        $this->assertCount(25, $result['install']);
        $this->assertCount(5, $result['unresolved']);
    }

    public function test_malformed_entries_are_ignored(): void
    {
        $root = ['id' => 'r1', 'dependencies' => ['junk', ['dependency_type' => 'required', 'project_id' => 42], self::dep('a')]];

        $result = DependencyResolver::resolve('root', $root, self::lookup(['a' => [self::version('a1', 'a')]]), []);

        $this->assertSame(['a@a1<root'], self::installedPairs($result));
    }

    public function test_incompatible_installed_lists_only_installed_incompatible_projects(): void
    {
        $version = self::version('r1', 'root', [
            self::dep('optifine', 'incompatible'),
            self::dep('not-installed', 'incompatible'),
            self::dep('fabric-api'),
        ]);

        $this->assertSame(['optifine'], DependencyResolver::incompatibleInstalled($version, ['optifine', 'fabric-api']));
        $this->assertSame([], DependencyResolver::incompatibleInstalled(self::version('r1', 'root'), ['optifine']));
    }
}
