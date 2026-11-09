<?php

namespace DrMxrcy\ModpacksPlus\Tests\Unit;

use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Services\JavaImagePicker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan 35 step 2: a Java image the egg allows for the pack's Minecraft version.
 * ≤1.16 → 8, 1.17 → 16, 1.18–1.20.4 → 17, 1.20.5–1.21.x → 21, newer → highest allowed.
 */
class JavaImagePickerTest extends TestCase
{
    /** The images every official loader egg offers. */
    private const OFFICIAL = [
        'Java 8' => 'ghcr.io/pelican-eggs/yolks:java_8',
        'Java 11' => 'ghcr.io/pelican-eggs/yolks:java_11',
        'Java 16' => 'ghcr.io/pelican-eggs/yolks:java_16',
        'Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17',
        'Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21',
        'Java 25' => 'ghcr.io/pelican-eggs/yolks:java_25',
    ];

    /** @return array<string, array{string, int}> */
    public static function boundaries(): array
    {
        return [
            '1.7.10' => ['1.7.10', 8],
            '1.12.2' => ['1.12.2', 8],
            '1.16' => ['1.16', 8],
            '1.16.5 (last on 8)' => ['1.16.5', 8],
            '1.17 (first on 16)' => ['1.17', 16],
            '1.17.1' => ['1.17.1', 16],
            '1.18 (first on 17)' => ['1.18', 17],
            '1.19.4' => ['1.19.4', 17],
            '1.20' => ['1.20', 17],
            '1.20.4 (last on 17)' => ['1.20.4', 17],
            '1.20.5 (first on 21)' => ['1.20.5', 21],
            '1.20.6' => ['1.20.6', 21],
            '1.21.1' => ['1.21.1', 21],
            '1.21.11' => ['1.21.11', 21],
            '1.22 (newer: highest)' => ['1.22', 25],
            '26.1 (newer: highest)' => ['26.1', 25],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_picks_the_java_version_for_each_boundary(string $minecraft, int $java): void
    {
        $picked = (new JavaImagePicker())->pick(self::OFFICIAL, $minecraft);

        $this->assertSame($java, $picked['java']);
        $this->assertSame("ghcr.io/pelican-eggs/yolks:java_$java", $picked['image']);
        $this->assertSame("Java $java", $picked['label']);
    }

    public function test_newer_versions_take_the_highest_allowed_image_whatever_the_order(): void
    {
        $images = ['Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21', 'Java 25' => 'ghcr.io/pelican-eggs/yolks:java_25', 'Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17'];

        $this->assertSame(25, (new JavaImagePicker())->pick($images, '26.1')['java']);
        $this->assertSame(21, (new JavaImagePicker())->pick(['Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21'], '1.22')['java']);
    }

    public function test_refuses_when_the_egg_has_no_image_for_the_version(): void
    {
        $this->expectException(InstallRefusedException::class);

        // Java 17 would run 1.17 but the rule asks for 16: never guessed.
        (new JavaImagePicker())->pick(['Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17', 'Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21'], '1.17.1');
    }

    public function test_refuses_when_no_image_names_a_java_version(): void
    {
        $this->expectException(InstallRefusedException::class);

        (new JavaImagePicker())->pick(['Default' => 'ghcr.io/example/minecraft:latest'], '26.1');
    }

    /** @return array<string, array{string}> */
    public static function unknownVersions(): array
    {
        return [
            'latest' => ['latest'],
            'snapshot' => ['24w14a'],
            'pre-release' => ['1.21-pre1'],
            'empty' => [''],
            'beta' => ['b1.7.3'],
        ];
    }

    #[DataProvider('unknownVersions')]
    public function test_refuses_versions_it_cannot_place(string $minecraft): void
    {
        $this->expectException(InstallRefusedException::class);

        (new JavaImagePicker())->pick(self::OFFICIAL, $minecraft);
    }

    /** @return array<string, array{string, string, ?int}> */
    public static function imageNames(): array
    {
        return [
            'yolks tag' => ['Whatever', 'ghcr.io/pelican-eggs/yolks:java_21', 21],
            'label only' => ['Java 17', 'registry.example/custom:stable', 17],
            'dash tag' => ['X', 'ghcr.io/parkervcp/yolks:java-11', 11],
            'tag wins over label' => ['Java 8', 'ghcr.io/pelican-eggs/yolks:java_17', 17],
            'no version' => ['Default', 'ghcr.io/example/minecraft:latest', null],
            'not a java tag' => ['Default', 'ghcr.io/javascript/x:node_21', null],
        ];
    }

    #[DataProvider('imageNames')]
    public function test_reads_the_java_version_of_an_image(string $label, string $image, ?int $java): void
    {
        $this->assertSame($java, JavaImagePicker::javaOf($label, $image));
    }
}
