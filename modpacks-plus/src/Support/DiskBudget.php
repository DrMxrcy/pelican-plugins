<?php

namespace DrMxrcy\ModpacksPlus\Support;

/**
 * How much a pack may write to a server (plan 38). Pure: the caller passes the server's
 * disk limit, the usage the node reported and the caps.
 *
 * - Disk limit set and usage known: the pack must fit the free space under the limit
 *   minus headroom (old `mods/`/`config/` stay on disk under `.modpacks-plus/previous-*`,
 *   so nothing an install moves aside is counted as freed), and the configured total cap.
 * - Unlimited disk (`disk = 0`) or usage unknown (node error): the configured cap alone.
 *   Unknown usage never means unlimited, and never blocks an install on its own.
 *
 * Sizes use the panel's disk unit: 1 MB = 1024 * 1024 bytes, like Wings enforces it.
 */
final class DiskBudget
{
    public const MIB = 1024 * 1024;

    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * @param  int  $limitBytes  the server's disk limit; 0 = unlimited
     * @param  ?int  $usedBytes  what the node reports the server uses; null = unknown
     * @param  int  $capBytes  the configured whole-pack cap (always applies)
     * @param  int  $headroomBytes  space kept free under the limit (0 when unlimited)
     */
    public function __construct(
        public readonly int $limitBytes,
        public readonly ?int $usedBytes,
        public readonly int $capBytes,
        public readonly int $headroomBytes,
    ) {}

    /**
     * @param  int  $diskMb  `Server::$disk` (MB, 0 = unlimited)
     * @param  ?int  $usedBytes  `disk_bytes` from the node's resources, null when it couldn't say
     */
    public static function forServer(int $diskMb, ?int $usedBytes, Caps $caps): self
    {
        $limit = max(0, $diskMb) * self::MIB;
        $headroom = $limit > 0
            ? max(intdiv($limit * min(100, max(0, $caps->diskHeadroomPercent)), 100), max(0, $caps->diskHeadroomMinBytes))
            : 0;

        return new self($limit, $usedBytes === null ? null : max(0, $usedBytes), max(0, $caps->maxTotalBytes), $headroom);
    }

    /** Whether the server has a disk limit. */
    public function limited(): bool
    {
        return $this->limitBytes > 0;
    }

    /** Whether the node reported the server's disk usage. */
    public function usageKnown(): bool
    {
        return $this->usedBytes !== null;
    }

    /** Free space under the limit; null when unlimited or usage unknown. */
    public function freeBytes(): ?int
    {
        if (!$this->limited() || $this->usedBytes === null) {
            return null;
        }

        return max(0, $this->limitBytes - $this->usedBytes);
    }

    /** What the disk allows a pack to write (free minus headroom); null when the disk sets no bound. */
    public function diskAllowedBytes(): ?int
    {
        $free = $this->freeBytes();

        return $free === null ? null : max(0, $free - $this->headroomBytes);
    }

    /** The whole-pack limit: min(configured cap, what the disk allows). */
    public function allowedBytes(): int
    {
        $disk = $this->diskAllowedBytes();

        return $disk === null ? $this->capBytes : min($this->capBytes, $disk);
    }

    /** Whether a pack of this many bytes fits both the cap and the disk. */
    public function fits(int $packBytes): bool
    {
        return $packBytes <= $this->allowedBytes();
    }

    /** Whether a pack of this many bytes fits the disk (the configured cap is checked separately). */
    public function fitsDisk(int $packBytes): bool
    {
        $disk = $this->diskAllowedBytes();

        return $disk === null || $packBytes <= $disk;
    }

    /** Space the pack needs under the limit: the pack plus the headroom kept free. */
    public function neededBytes(int $packBytes): int
    {
        return max(0, $packBytes) + $this->headroomBytes;
    }

    /**
     * "needed / free / limit" in human sizes, language-neutral, for a not_enough_disk reason
     * (detailParams() splits it again for the translated message).
     */
    public function detail(int $packBytes): string
    {
        return self::human($this->neededBytes($packBytes)).' / '.self::human($this->freeBytes() ?? 0).' / '.self::human($this->limitBytes);
    }

    /** @return array{needed: string, free: string, limit: string} */
    public static function detailParams(string $detail): array
    {
        $parts = explode(' / ', $detail);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return ['needed' => '?', 'free' => '?', 'limit' => '?'];
        }

        return ['needed' => $parts[0], 'free' => $parts[1], 'limit' => $parts[2]];
    }

    /** 1024-based, one decimal at most: "6.2 GB", "10 GB", "512 MB". */
    public static function human(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        $rounded = round($value, 1);
        $text = fmod($rounded, 1.0) === 0.0 ? (string) (int) $rounded : number_format($rounded, 1, '.', '');

        return $text.' '.self::UNITS[$unit];
    }
}
