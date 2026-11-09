<?php

namespace DrMxrcy\PlayersPlus\Enums;

enum SampleStatus: string
{
    /** Answered Query or Server List Ping. */
    case Online = 'online';

    /** The panel says the server is not running; it was not contacted. */
    case Offline = 'offline';

    /** Running according to the panel, but neither Query nor Ping answered. */
    case Unreachable = 'unreachable';

    public function getLabel(): string
    {
        return trans('players-plus::strings.status.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::Offline => 'gray',
            self::Unreachable => 'warning',
        };
    }
}
