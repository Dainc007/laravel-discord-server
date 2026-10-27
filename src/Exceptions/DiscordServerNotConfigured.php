<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Exceptions;

use LogicException;

/**
 * Thrown before any request when the bot token or the server id is missing.
 */
final class DiscordServerNotConfigured extends LogicException
{
    public static function missingToken(): self
    {
        return new self('Set DISCORD_BOT_TOKEN (config discord-server.bot_token) to talk to Discord.');
    }

    public static function missingGuild(): self
    {
        return new self('Set DISCORD_GUILD_ID (config discord-server.guild_id), or pass a server with forGuild().');
    }
}
