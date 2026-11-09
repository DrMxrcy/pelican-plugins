<?php

namespace DrMxrcy\PlayersPlus\Enums;

/**
 * Every console command the plugin may send, and nothing else: the command word is fixed
 * here, only the (validated) player name and the (sanitised) reason come from the user.
 */
enum ModerationAction: string
{
    case Kick = 'kick';
    case Ban = 'ban';
    case Pardon = 'pardon';
    case WhitelistAdd = 'whitelist_add';
    case WhitelistRemove = 'whitelist_remove';
    case Op = 'op';
    case Deop = 'deop';

    /** The vanilla command in front of the player name. */
    public function command(): string
    {
        return match ($this) {
            self::Kick => 'kick',
            self::Ban => 'ban',
            self::Pardon => 'pardon',
            self::WhitelistAdd => 'whitelist add',
            self::WhitelistRemove => 'whitelist remove',
            self::Op => 'op',
            self::Deop => 'deop',
        };
    }

    public function takesReason(): bool
    {
        return $this === self::Kick || $this === self::Ban;
    }

    public function getLabel(): string
    {
        return trans('players-plus::strings.moderation.actions.' . $this->value);
    }
}
