<?php

namespace DrMxrcy\PlayersPlus\Enums;

enum SessionSource: string
{
    /** Opened and closed by comparing consecutive samples. */
    case Poll = 'poll';

    /** Start or end taken from join/leave lines in the server log. */
    case Log = 'log';

    public function getLabel(): string
    {
        return trans('players-plus::strings.source.' . $this->value);
    }
}
