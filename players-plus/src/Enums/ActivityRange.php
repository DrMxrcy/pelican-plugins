<?php

namespace DrMxrcy\PlayersPlus\Enums;

/** Time span of the player-count chart. 24 h reads raw samples; longer spans read hourly rollups. */
enum ActivityRange: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function hours(): int
    {
        return match ($this) {
            self::Day => 24,
            self::Week => 24 * 7,
            self::Month => 24 * 30,
        };
    }

    public function readsRawSamples(): bool
    {
        return $this === self::Day;
    }

    public function getLabel(): string
    {
        return trans('players-plus::strings.activity.range.' . $this->value);
    }
}
