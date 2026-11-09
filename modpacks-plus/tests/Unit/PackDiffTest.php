<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Services\PackDiff;
use PHPUnit\Framework\TestCase;

/** Plan 36 step 2: what an update does with each file. */
class PackDiffTest extends TestCase
{
    /** @return array{path: string, size: int, hash: string} */
    private static function entry(string $path, string $contents): array
    {
        return ['path' => $path, 'size' => strlen($contents), 'hash' => 'sha1:'.sha1($contents)];
    }

    /** @return array{size: int, hash: ?string} */
    private static function disk(string $contents, bool $hashed = true): array
    {
        return ['size' => strlen($contents), 'hash' => $hashed ? 'sha1:'.sha1($contents) : null];
    }

    /**
     * @param  array<string, string>  $files  path => contents
     * @return array<string, array{path: string, size: int, hash: string}>
     */
    private static function manifest(array $files): array
    {
        $out = [];
        foreach ($files as $path => $contents) {
            $out[$path] = self::entry($path, $contents);
        }

        return $out;
    }

    public function test_add_remove_change_and_unchanged(): void
    {
        $old = self::manifest(['mods/kept.jar' => 'k1', 'mods/dropped.jar' => 'd1', 'config/a.toml' => 'a=1']);
        $new = self::manifest(['mods/kept.jar' => 'k1', 'mods/new.jar' => 'n1', 'config/a.toml' => 'a=2']);
        $disk = ['mods/kept.jar' => self::disk('k1', false), 'mods/dropped.jar' => self::disk('d1'), 'config/a.toml' => self::disk('a=1'), 'mods/new.jar' => null];

        $plan = PackDiff::plan($old, $new, $disk, false);

        $this->assertSame(['path' => 'mods/kept.jar', 'kind' => 'unchanged', 'modified' => false, 'action' => 'keep'], $plan['mods/kept.jar']);
        $this->assertSame(['path' => 'mods/dropped.jar', 'kind' => 'removed', 'modified' => false, 'action' => 'aside'], $plan['mods/dropped.jar']);
        $this->assertSame(['path' => 'config/a.toml', 'kind' => 'changed', 'modified' => false, 'action' => 'aside_write'], $plan['config/a.toml']);
        $this->assertSame(['path' => 'mods/new.jar', 'kind' => 'added', 'modified' => false, 'action' => 'write'], $plan['mods/new.jar']);
        $this->assertSame(['config/a.toml', 'mods/dropped.jar', 'mods/kept.jar', 'mods/new.jar'], array_keys($plan));
    }

    public function test_user_modified_files_are_kept_unless_overwrite_is_ticked(): void
    {
        $old = self::manifest(['config/a.toml' => 'a=1', 'config/gone.toml' => 'g', 'config/same.toml' => 's']);
        $new = self::manifest(['config/a.toml' => 'a=2', 'config/same.toml' => 's']);
        // Same-size edit (caught by hash), another-size edit, an edit of an unchanged file.
        $disk = ['config/a.toml' => self::disk('a=9'), 'config/gone.toml' => self::disk('edited'), 'config/same.toml' => self::disk('s-edited', false)];

        $kept = PackDiff::plan($old, $new, $disk, false);
        foreach (['config/a.toml', 'config/gone.toml', 'config/same.toml'] as $path) {
            $this->assertTrue($kept[$path]['modified'], $path);
            $this->assertSame('keep_modified', $kept[$path]['action'], $path);
        }

        $overwritten = PackDiff::plan($old, $new, $disk, true);
        $this->assertSame('aside_write', $overwritten['config/a.toml']['action']);
        $this->assertSame('aside', $overwritten['config/gone.toml']['action']);
        $this->assertSame('aside_write', $overwritten['config/same.toml']['action']);

        $summary = PackDiff::summary($kept);
        $this->assertSame(['config/a.toml', 'config/gone.toml', 'config/same.toml'], $summary['kept_modified']);
        $this->assertSame([], $summary['overwritten']);
        $this->assertSame(['config/a.toml', 'config/gone.toml', 'config/same.toml'], PackDiff::summary($overwritten)['overwritten']);
    }

    public function test_missing_files_are_written_again_and_absent_removals_need_nothing(): void
    {
        $old = self::manifest(['mods/a.jar' => 'a', 'mods/b.jar' => 'b', 'mods/c.jar' => 'c']);
        $new = self::manifest(['mods/a.jar' => 'a', 'mods/b.jar' => 'b2']);

        $plan = PackDiff::plan($old, $new, [], false);

        $this->assertSame('write', $plan['mods/a.jar']['action']);
        $this->assertSame('write', $plan['mods/b.jar']['action']);
        $this->assertSame('none', $plan['mods/c.jar']['action']);
        $this->assertFalse($plan['mods/c.jar']['modified']);
    }

    public function test_a_file_at_an_added_path_is_not_ours_and_moves_aside(): void
    {
        $plan = PackDiff::plan([], self::manifest(['config/new.toml' => 'pack']), ['config/new.toml' => self::disk('user made this')], false);

        $this->assertSame('aside_write', $plan['config/new.toml']['action']);
        $this->assertFalse($plan['config/new.toml']['modified']);
        $this->assertSame(['config/new.toml'], PackDiff::summary($plan)['not_ours']);
    }

    public function test_a_size_only_check_counts_as_unmodified_at_the_same_size(): void
    {
        $old = self::manifest(['mods/big.jar' => 'aaaa']);
        $new = self::manifest(['mods/big.jar' => 'bbbb']);

        $this->assertSame('aside_write', PackDiff::plan($old, $new, ['mods/big.jar' => self::disk('cccc', false)], false)['mods/big.jar']['action']);
        $this->assertSame('keep_modified', PackDiff::plan($old, $new, ['mods/big.jar' => self::disk('ccccc', false)], false)['mods/big.jar']['action']);
    }

    public function test_only_files_about_to_move_at_the_old_size_need_a_hash(): void
    {
        $old = self::manifest(['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5']);
        $new = self::manifest(['a' => '1', 'b' => 'x', 'e' => '6']);
        $disk = ['a' => self::disk('1', false), 'b' => self::disk('2', false), 'c' => self::disk('3', false), 'd' => null, 'e' => self::disk('55', false)];

        // a: unchanged; b: changed, same size; c: removed, same size; d: absent; e: other size.
        $this->assertSame(['b', 'c'], PackDiff::needsHash($old, $new, $disk));
    }

    public function test_hash_comparison_ignores_case(): void
    {
        $old = ['m' => ['path' => 'm', 'size' => 1, 'hash' => 'SHA1:'.strtoupper(sha1('x'))]];
        $new = ['m' => ['path' => 'm', 'size' => 1, 'hash' => 'sha1:'.sha1('x')]];

        $plan = PackDiff::plan($old, $new, ['m' => ['size' => 1, 'hash' => 'sha1:'.sha1('x')]], false);

        $this->assertSame('unchanged', $plan['m']['kind']);
        $this->assertSame('keep', $plan['m']['action']);
    }
}
