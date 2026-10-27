<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Facades;

use Dainc007\DiscordRestClient\Contracts\ClientContract;
use Dainc007\DiscordRestClient\Testing\ClientFake;
use Dainc007\LaravelDiscordServer\DiscordServer as DiscordServerService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static DiscordServerService actingAs(?\Illuminate\Database\Eloquent\Model $actor)
 * @method static DiscordServerService because(?string $reason)
 * @method static DiscordServerService forGuild(string $guildId)
 * @method static ClientContract client()
 * @method static string guildId()
 * @method static string configuredGuildId()
 * @method static void addRole(string $userId, string $roleId)
 * @method static void removeRole(string $userId, string $roleId)
 * @method static array{added: list<string>, removed: list<string>} syncRoles(string $userId, list<string> $wanted, list<string> $managed)
 * @method static \Dainc007\DiscordRestClient\Data\Member setNickname(string $userId, ?string $nickname)
 * @method static \Dainc007\DiscordRestClient\Data\Member timeout(string $userId, ?\DateTimeInterface $until)
 * @method static void kick(string $userId)
 * @method static void ban(string $userId, int $deleteMessageSeconds = 0)
 * @method static void unban(string $userId)
 * @method static \Dainc007\DiscordRestClient\Data\Role createRole(string $name, list<\Dainc007\DiscordRestClient\Enums\Permission>|null $permissions = null, int $color = 0, bool $hoist = false, bool $mentionable = false)
 * @method static \Dainc007\DiscordRestClient\Data\Role editRole(string $roleId, array<string, mixed> $attributes)
 * @method static void deleteRole(string $roleId)
 * @method static \Dainc007\DiscordRestClient\Data\Channel createChannel(string $name, \Dainc007\DiscordRestClient\Enums\ChannelType $type = \Dainc007\DiscordRestClient\Enums\ChannelType::GuildText, array<string, mixed> $attributes = [], list<\Dainc007\DiscordRestClient\Data\PermissionOverwrite> $permissionOverwrites = [])
 * @method static \Dainc007\DiscordRestClient\Data\Channel editChannel(string $channelId, array<string, mixed> $attributes)
 * @method static \Dainc007\DiscordRestClient\Data\Channel deleteChannel(string $channelId)
 * @method static mixed record(string|\BackedEnum $action, \Closure $change, ?\Dainc007\LaravelDiscordServer\Enums\ChangeTarget $targetType = null, ?string $targetId = null, ?string $targetName = null, array<string, mixed> $details = [])
 * @method static \Dainc007\LaravelDiscordServer\RecordedChange<mixed> recordChange(string|\BackedEnum $action, \Closure $change, ?\Dainc007\LaravelDiscordServer\Enums\ChangeTarget $targetType = null, ?string $targetId = null, ?string $targetName = null, array<string, mixed> $details = [])
 * @method static string auditReason()
 *
 * @see DiscordServerService
 */
final class DiscordServer extends Facade
{
    /**
     * Swap the Discord client for a fake: no HTTP, calls are recorded, and
     * queued responses are returned in order. The change log still works.
     * A server id is filled in when none is configured, so tests need no .env.
     *
     * @param  list<mixed>  $responses  See the client's FakeTransport for accepted shapes
     */
    public static function fake(array $responses = []): ClientFake
    {
        $fake = new ClientFake($responses, config()->boolean('discord-server.members_intent', false));

        // Bound as scoped, like the real client: when a queue worker or Octane
        // resets scoped services, the binding hands back the same fake.
        app()->forgetInstance(ClientContract::class);
        app()->scoped(ClientContract::class, static fn (): ClientFake => $fake);

        if (DiscordServerService::configuredGuildId() === '') {
            config(['discord-server.guild_id' => '100000000000000000']);
        }

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return DiscordServerService::class;
    }
}
