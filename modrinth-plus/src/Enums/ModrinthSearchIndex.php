<?php

namespace DrMxrcy\ModrinthPlus\Enums;

use Filament\Support\Contracts\HasLabel;

/** Sort order of Modrinth's /search endpoint (its `index` parameter). */
enum ModrinthSearchIndex: string implements HasLabel
{
    case Relevance = 'relevance';
    case Downloads = 'downloads';
    case Follows = 'follows';
    case Newest = 'newest';
    case Updated = 'updated';

    public function getLabel(): string
    {
        return trans('modrinth-plus::strings.sort.'.$this->value);
    }

    /** Relevance only means something with a search term; without one the most downloaded come first. */
    public static function defaultFor(?string $search): self
    {
        return filled($search) ? self::Relevance : self::Downloads;
    }
}
