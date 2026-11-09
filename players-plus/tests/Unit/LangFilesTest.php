<?php

namespace DrMxrcy\PlayersPlus\Tests\Unit;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the translation files: PHP silently keeps the last of two duplicate array
 * keys, so a duplicated group (easy to get from a merge) would hide strings without
 * any error. Also keeps every language in sync with English.
 */
class LangFilesTest extends TestCase
{
    private const LANG_DIR = __DIR__.'/../../lang';

    /** @return array<string, array{string}> */
    public static function langFiles(): array
    {
        $files = [];
        foreach (glob(self::LANG_DIR.'/*/strings.php') ?: [] as $file) {
            $files[basename(dirname($file))] = [$file];
        }

        return $files;
    }

    #[DataProvider('langFiles')]
    public function test_no_duplicate_keys(string $file): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $statements = $parser->parse((string) file_get_contents($file)) ?? [];

        $return = null;
        foreach ($statements as $statement) {
            if ($statement instanceof Return_ && $statement->expr instanceof Array_) {
                $return = $statement->expr;
            }
        }
        $this->assertNotNull($return, "$file must return an array");

        $this->assertSame([], $this->duplicateKeys($return, ''), "Duplicate keys in $file");
    }

    public function test_every_language_has_the_same_keys_as_english(): void
    {
        $english = $this->flatKeys(require self::LANG_DIR.'/en/strings.php');

        foreach (self::langFiles() as $lang => [$file]) {
            $keys = $this->flatKeys(require $file);
            $this->assertSame([], array_values(array_diff($english, $keys)), "Missing in $lang");
            $this->assertSame([], array_values(array_diff($keys, $english)), "Extra in $lang");
        }
    }

    /** @return list<string> */
    private function duplicateKeys(Array_ $array, string $prefix): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($array->items as $item) {
            if (!$item->key instanceof String_) {
                continue;
            }

            $key = $prefix.$item->key->value;
            if (isset($seen[$key])) {
                $duplicates[] = $key;
            }
            $seen[$key] = true;

            if ($item->value instanceof Array_) {
                array_push($duplicates, ...$this->duplicateKeys($item->value, $key.'.'));
            }
        }

        return $duplicates;
    }

    /**
     * @param  array<mixed>  $strings
     * @return list<string>
     */
    private function flatKeys(array $strings, string $prefix = ''): array
    {
        $keys = [];
        foreach ($strings as $key => $value) {
            $keys[] = $prefix.$key;
            if (is_array($value)) {
                array_push($keys, ...$this->flatKeys($value, $prefix.$key.'.'));
            }
        }
        sort($keys);

        return $keys;
    }

    /** Sanity check that the parser walk really sees nested keys. */
    public function test_duplicate_detection_works(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $statements = $parser->parse("<?php return ['a' => ['x' => 1], 'b' => 2, 'a' => ['y' => 1, 'y' => 2]];") ?? [];
        $return = $statements[0];
        $this->assertInstanceOf(Return_::class, $return);
        $this->assertInstanceOf(Array_::class, $return->expr);

        $this->assertSame(['a', 'a.y'], $this->duplicateKeys($return->expr, ''));
        $this->assertInstanceOf(Node::class, $return);
    }
}
