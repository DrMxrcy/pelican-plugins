<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;

/**
 * Picks the Docker image a loader egg allows for a Minecraft version:
 * ≤1.16 → Java 8, 1.17 → 16, 1.18–1.20.4 → 17, 1.20.5–1.21.x → 21, anything newer
 * (1.22+, the year-numbered 26.x releases) → the highest Java the egg offers.
 * Known versions need an exact match; nothing is guessed. Pure: no I/O.
 */
final class JavaImagePicker
{
    /**
     * @param  array<array-key, mixed>  $images  the egg's docker_images (label => image)
     * @return array{label: string, image: string, java: int}
     *
     * @throws InstallRefusedException `no_java_image` when none fits, `version_unsupported` for an unplaceable version
     */
    public function pick(array $images, string $minecraft): array
    {
        $required = self::requiredJava($minecraft);

        $best = null;
        foreach ($images as $label => $image) {
            if (!is_string($image)) {
                continue;
            }
            $java = self::javaOf((string) $label, $image);
            if ($java === null) {
                continue;
            }

            $candidate = ['label' => (string) $label, 'image' => $image, 'java' => $java];
            if ($required !== null && $java === $required) {
                return $candidate;
            }
            if ($required === null && ($best === null || $java > $best['java'])) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            throw new InstallRefusedException('no_java_image', 'Minecraft '.$minecraft.($required !== null ? ' (Java '.$required.')' : ''));
        }

        return $best;
    }

    /**
     * The Java major a Minecraft version needs; null for versions newer than this table
     * (take the highest the egg allows).
     *
     * @throws InstallRefusedException
     */
    public static function requiredJava(string $minecraft): ?int
    {
        if (preg_match('/^1\.(\d{1,2})(?:\.(\d{1,2}))?$/', $minecraft, $match)) {
            $minor = (int) $match[1];
            $patch = isset($match[2]) ? (int) $match[2] : 0;

            return match (true) {
                $minor <= 16 => 8,
                $minor === 17 => 16,
                $minor <= 19, $minor === 20 && $patch <= 4 => 17,
                $minor <= 21 => 21,
                default => null,
            };
        }

        // Year-numbered releases (26.1, 26.1.2): newer than the table.
        if (preg_match('/^(\d{2})\.\d{1,2}(?:\.\d{1,2})?$/', $minecraft, $match) && (int) $match[1] >= 25) {
            return null;
        }

        throw new InstallRefusedException('version_unsupported', $minecraft);
    }

    /** The Java major an image runs: from the image tag (`java_21`, `java-21`), else from the label (`Java 21`). */
    public static function javaOf(string $label, string $image): ?int
    {
        $tag = str_contains($image, ':') ? substr($image, (int) strrpos($image, ':') + 1) : '';
        if (preg_match('/^java[_-]?(\d{1,2})$/i', $tag, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/^java\s*(\d{1,2})$/i', trim($label), $match)) {
            return (int) $match[1];
        }

        return null;
    }
}
