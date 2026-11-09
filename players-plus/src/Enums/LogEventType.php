<?php

namespace DrMxrcy\PlayersPlus\Enums;

enum LogEventType: string
{
    /** `<name> joined the game` */
    case Join = 'join';

    /** `<name> left the game` */
    case Leave = 'leave';

    /** `UUID of player <name> is <uuid>`, logged just before the join */
    case Uuid = 'uuid';
}
