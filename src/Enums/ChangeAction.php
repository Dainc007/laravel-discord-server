<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Enums;

/**
 * The changes this package makes itself. record() also takes any other
 * string or backed enum for an app's own changes.
 */
enum ChangeAction: string
{
    case AddRole = 'member.add_role';
    case RemoveRole = 'member.remove_role';
    case SetNickname = 'member.set_nickname';
    case Timeout = 'member.timeout';
    case Kick = 'member.kick';
    case Ban = 'member.ban';
    case Unban = 'member.unban';
    case CreateRole = 'role.create';
    case EditRole = 'role.edit';
    case DeleteRole = 'role.delete';
    case CreateChannel = 'channel.create';
    case EditChannel = 'channel.edit';
    case DeleteChannel = 'channel.delete';
}
