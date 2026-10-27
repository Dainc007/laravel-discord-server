<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Contracts;

/**
 * Lets a model choose how it is named in Discord's audit log. Without it the
 * model's `name` attribute is used.
 */
interface NamedDiscordActor
{
    public function discordActorName(): string;
}
