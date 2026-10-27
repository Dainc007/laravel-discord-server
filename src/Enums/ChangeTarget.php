<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Enums;

enum ChangeTarget: string
{
    case Guild = 'guild';
    case Member = 'member';
    case Role = 'role';
    case Channel = 'channel';
}
