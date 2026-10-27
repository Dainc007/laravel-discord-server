<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Tests\Support;

use Dainc007\LaravelDiscordServer\Contracts\NamedDiscordActor;
use Illuminate\Database\Eloquent\Model;

final class Moderator extends Model implements NamedDiscordActor
{
    protected $table = 'users';

    protected $guarded = [];

    public function discordActorName(): string
    {
        return 'Mod '.$this->getAttribute('name');
    }
}
