<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer;

use BackedEnum;
use Closure;
use Dainc007\DiscordRestClient\Contracts\ClientContract;
use Dainc007\DiscordRestClient\Data\Channel;
use Dainc007\DiscordRestClient\Data\Member;
use Dainc007\DiscordRestClient\Data\PermissionOverwrite;
use Dainc007\DiscordRestClient\Data\Role;
use Dainc007\DiscordRestClient\Enums\ChannelType;
use Dainc007\DiscordRestClient\Enums\Permission;
use Dainc007\DiscordRestClient\Exceptions\DiscordException;
use Dainc007\LaravelDiscordServer\Contracts\NamedDiscordActor;
use Dainc007\LaravelDiscordServer\Enums\ChangeAction;
use Dainc007\LaravelDiscordServer\Enums\ChangeTarget;
use Dainc007\LaravelDiscordServer\Exceptions\DiscordServerNotConfigured;
use Dainc007\LaravelDiscordServer\Models\DiscordChange;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes changes on a Discord server. Every write goes through record(): it
 * sends an audit reason Discord shows in its own audit log ("My App: Anna:
 * linked account") and writes a row to the change log, also when Discord
 * refuses the change.
 *
 *     DiscordServer::actingAs($user)->because('Linked account')->addRole($discordUserId, $roleId);
 *
 * Each with-method returns a copy, so a configured instance can be kept and reused.
 */
final readonly class DiscordServer
{
    public function __construct(
        private Container $container,
        private ?Model $actor = null,
        private ?string $reason = null,
        private ?string $guildId = null,
    ) {}

    /**
     * Who makes the following changes; logged and named in Discord's audit log.
     */
    public function actingAs(?Model $actor): self
    {
        return new self($this->container, $actor, $this->reason, $this->guildId);
    }

    /**
     * Why; logged and shown in Discord's audit log.
     */
    public function because(?string $reason): self
    {
        return new self($this->container, $this->actor, $reason, $this->guildId);
    }

    /**
     * Use another server than the configured one.
     */
    public function forGuild(string $guildId): self
    {
        return new self($this->container, $this->actor, $this->reason, $guildId);
    }

    /**
     * The REST client, for reads (guilds()->roles(), members()->search(), ...).
     * Writes made on it directly are not logged.
     */
    public function client(): ClientContract
    {
        return $this->container->make(ClientContract::class);
    }

    public function guildId(): string
    {
        $guildId = trim($this->guildId ?? self::configuredGuildId());

        if ($guildId === '') {
            throw DiscordServerNotConfigured::missingGuild();
        }

        return $guildId;
    }

    /**
     * The configured server id. A number typed into a published config file
     * counts too: Discord ids are written as strings, but an id is still an id.
     */
    public static function configuredGuildId(): string
    {
        $guildId = config('discord-server.guild_id', '');

        return is_int($guildId) || is_string($guildId) ? trim((string) $guildId) : '';
    }

    public function addRole(string $userId, string $roleId): void
    {
        $this->record(
            ChangeAction::AddRole,
            static fn (ClientContract $discord, string $guildId, string $reason) => $discord->members()->addRole($guildId, $userId, $roleId, $reason),
            ChangeTarget::Member,
            $userId,
            details: ['role_id' => $roleId],
        );
    }

    public function removeRole(string $userId, string $roleId): void
    {
        $this->record(
            ChangeAction::RemoveRole,
            static fn (ClientContract $discord, string $guildId, string $reason) => $discord->members()->removeRole($guildId, $userId, $roleId, $reason),
            ChangeTarget::Member,
            $userId,
            details: ['role_id' => $roleId],
        );
    }

    /**
     * Give a member every role in $wanted and take away every role in $managed
     * that is not wanted. Roles outside both lists are left alone, so roles
     * given by hand in Discord survive. Each added or removed role is its own
     * logged change.
     *
     * @param  list<string>  $wanted
     * @param  list<string>  $managed
     * @return array{added: list<string>, removed: list<string>}
     */
    public function syncRoles(string $userId, array $wanted, array $managed): array
    {
        $current = $this->client()->members()->get($this->guildId(), $userId)->roleIds;

        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_intersect(array_diff($managed, $wanted), $current));

        foreach ($added as $roleId) {
            $this->addRole($userId, $roleId);
        }

        foreach ($removed as $roleId) {
            $this->removeRole($userId, $roleId);
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @param  string|null  $nickname  null resets it to the user's own name
     */
    public function setNickname(string $userId, ?string $nickname): Member
    {
        return $this->record(
            ChangeAction::SetNickname,
            static fn (ClientContract $discord, string $guildId, string $reason): Member => $discord->members()->setNickname($guildId, $userId, $nickname, $reason),
            ChangeTarget::Member,
            $userId,
            details: ['nickname' => $nickname],
        );
    }

    /**
     * @param  DateTimeInterface|null  $until  null lifts the timeout
     */
    public function timeout(string $userId, ?DateTimeInterface $until): Member
    {
        return $this->record(
            ChangeAction::Timeout,
            static fn (ClientContract $discord, string $guildId, string $reason): Member => $discord->members()->timeout($guildId, $userId, $until, $reason),
            ChangeTarget::Member,
            $userId,
            details: ['until' => $until?->format(DateTimeInterface::ATOM)],
        );
    }

    public function kick(string $userId): void
    {
        $this->record(
            ChangeAction::Kick,
            static fn (ClientContract $discord, string $guildId, string $reason) => $discord->members()->kick($guildId, $userId, $reason),
            ChangeTarget::Member,
            $userId,
        );
    }

    public function ban(string $userId, int $deleteMessageSeconds = 0): void
    {
        $this->record(
            ChangeAction::Ban,
            static fn (ClientContract $discord, string $guildId, string $reason) => $discord->members()->ban($guildId, $userId, $deleteMessageSeconds, $reason),
            ChangeTarget::Member,
            $userId,
            details: ['delete_message_seconds' => $deleteMessageSeconds],
        );
    }

    public function unban(string $userId): void
    {
        $this->record(
            ChangeAction::Unban,
            static fn (ClientContract $discord, string $guildId, string $reason) => $discord->members()->unban($guildId, $userId, $reason),
            ChangeTarget::Member,
            $userId,
        );
    }

    /**
     * @param  list<Permission>|null  $permissions  null keeps Discord's default for new roles
     */
    public function createRole(string $name, ?array $permissions = null, int $color = 0, bool $hoist = false, bool $mentionable = false): Role
    {
        return $this->record(
            ChangeAction::CreateRole,
            static fn (ClientContract $discord, string $guildId, string $reason): Role => $discord->roles()->create($guildId, $name, $permissions, $color, $hoist, $mentionable, reason: $reason),
            ChangeTarget::Role,
            targetName: $name,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes  Discord fields: name, permissions (list of Permission), color, hoist, ...
     */
    public function editRole(string $roleId, array $attributes): Role
    {
        return $this->record(
            ChangeAction::EditRole,
            static fn (ClientContract $discord, string $guildId, string $reason): Role => $discord->roles()->edit($guildId, $roleId, $attributes, $reason),
            ChangeTarget::Role,
            $roleId,
            is_string($attributes['name'] ?? null) ? $attributes['name'] : null,
            ['fields' => array_keys($attributes)],
        );
    }

    public function deleteRole(string $roleId): void
    {
        $this->record(
            ChangeAction::DeleteRole,
            static fn (ClientContract $discord, string $guildId, string $reason) => $discord->roles()->delete($guildId, $roleId, $reason),
            ChangeTarget::Role,
            $roleId,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes  Extra Discord fields: parent_id, topic, position, ...
     * @param  list<PermissionOverwrite>  $permissionOverwrites
     */
    public function createChannel(string $name, ChannelType $type = ChannelType::GuildText, array $attributes = [], array $permissionOverwrites = []): Channel
    {
        return $this->record(
            ChangeAction::CreateChannel,
            static fn (ClientContract $discord, string $guildId, string $reason): Channel => $discord->channels()->create($guildId, $name, $type, $attributes, $permissionOverwrites, $reason),
            ChangeTarget::Channel,
            targetName: $name,
            details: ['type' => $type->value],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function editChannel(string $channelId, array $attributes): Channel
    {
        return $this->record(
            ChangeAction::EditChannel,
            static fn (ClientContract $discord, string $guildId, string $reason): Channel => $discord->channels()->edit($channelId, $attributes, $reason),
            ChangeTarget::Channel,
            $channelId,
            is_string($attributes['name'] ?? null) ? $attributes['name'] : null,
            ['fields' => array_keys($attributes)],
        );
    }

    public function deleteChannel(string $channelId): Channel
    {
        return $this->record(
            ChangeAction::DeleteChannel,
            static fn (ClientContract $discord, string $guildId, string $reason): Channel => $discord->channels()->delete($channelId, $reason),
            ChangeTarget::Channel,
            $channelId,
        );
    }

    /**
     * Run any write with the audit reason and the change log, for changes this
     * class has no method for (reordering, permission overwrites, ...).
     *
     * When Discord refuses, the failure is logged and Discord's exception is
     * rethrown unchanged, so the app decides what to show.
     *
     * @template TResult
     *
     * @param  Closure(ClientContract $discord, string $guildId, string $auditReason): TResult  $change
     * @param  array<string, mixed>  $details
     * @return TResult
     */
    public function record(
        string|BackedEnum $action,
        Closure $change,
        ?ChangeTarget $targetType = null,
        ?string $targetId = null,
        ?string $targetName = null,
        array $details = [],
    ): mixed {
        $guildId = $this->guildId();
        $client = $this->client();
        $reason = trim((string) $this->reason);

        if ($reason !== '') {
            $details['reason'] = $reason;
        }

        $entry = [
            'guild_id' => $guildId,
            'action' => $action instanceof BackedEnum ? (string) $action->value : $action,
            'target_type' => $targetType?->value,
            'target_id' => $targetId,
            'target_name' => $targetName,
        ];

        try {
            $result = $change($client, $guildId, $this->auditReason());
        } catch (DiscordException $exception) {
            $this->log($entry + [
                'details' => $details === [] ? null : $details,
                'succeeded' => false,
                // A network failure has no HTTP status; the client reports it as 0.
                'http_status' => $exception->httpStatus > 0 ? $exception->httpStatus : null,
                'discord_code' => $exception->discordCode,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        // A created role or channel only gets its id from Discord's answer.
        if ($entry['target_id'] === null && ($result instanceof Role || $result instanceof Channel)) {
            $entry['target_id'] = $result->id;
        }

        $this->log($entry + ['details' => $details === [] ? null : $details, 'succeeded' => true]);

        return $result;
    }

    /**
     * "Prefix: actor: reason", leaving out whatever is empty.
     */
    public function auditReason(): string
    {
        $parts = array_filter(
            [trim(config()->string('discord-server.audit_reason_prefix', '')), $this->actorName(), trim((string) $this->reason)],
            static fn (?string $part): bool => $part !== null && $part !== '',
        );

        return implode(': ', $parts);
    }

    private function actorName(): ?string
    {
        if ($this->actor === null) {
            return null;
        }

        if ($this->actor instanceof NamedDiscordActor) {
            return trim($this->actor->discordActorName());
        }

        $name = $this->actor->getAttribute('name');

        return is_string($name) && trim($name) !== ''
            ? trim($name)
            : class_basename($this->actor).' #'.$this->actorKey();
    }

    private function actorKey(): string
    {
        $key = $this->actor?->getKey();

        return is_int($key) || is_string($key) ? (string) $key : '?';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function log(array $attributes): void
    {
        if (! config('discord-server.change_log.enabled', true)) {
            return;
        }

        $change = new DiscordChange($attributes);
        $change->actor()->associate($this->actor);
        $change->save();
    }
}
