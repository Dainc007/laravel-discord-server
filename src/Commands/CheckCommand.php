<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Commands;

use Dainc007\DiscordRestClient\Data\Role;
use Dainc007\DiscordRestClient\Enums\Permission;
use Dainc007\DiscordRestClient\Exceptions\DiscordException;
use Dainc007\DiscordRestClient\Exceptions\InvalidTokenException;
use Dainc007\DiscordRestClient\Exceptions\MissingPermissionException;
use Dainc007\DiscordRestClient\Exceptions\UnknownResourceException;
use Dainc007\LaravelDiscordServer\DiscordServer;
use Dainc007\LaravelDiscordServer\Exceptions\DiscordServerNotConfigured;
use Illuminate\Console\Command;

/**
 * Reads the server with the configured token and reports what the bot can
 * do there: missing permissions, and roles it cannot change because they sit
 * at or above its own highest role. Changes nothing.
 */
final class CheckCommand extends Command
{
    /** The permissions the package's methods use. */
    private const array USED_PERMISSIONS = [
        Permission::ManageRoles,
        Permission::ManageChannels,
        Permission::ManageNicknames,
        Permission::ModerateMembers,
        Permission::KickMembers,
        Permission::BanMembers,
    ];

    protected $signature = 'discord-server:check
        {--bot-id= : The bot\'s user id, when it cannot be read from the token}
        {--role=* : A role id the bot must be able to give and edit (repeatable)}';

    protected $description = 'Check that the bot reaches the Discord server, and which roles and permissions it has there';

    public function handle(DiscordServer $discord): int
    {
        try {
            $guildId = $discord->guildId();
            $client = $discord->client();
            $guild = $client->guilds()->get($guildId, withCounts: false);
            $roles = $client->guilds()->roles($guildId);
        } catch (DiscordServerNotConfigured $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (DiscordException $exception) {
            $this->error('Discord refused: '.$exception->getMessage());
            $this->line($this->hintFor($exception));

            return self::FAILURE;
        }

        $this->info("Connected to \"{$guild->name}\" ({$guild->id}); it has ".count($roles).' roles.');

        $botId = $this->botId();

        if ($botId === null) {
            $this->warn('Could not read the bot\'s user id from the token. Pass --bot-id to check its permissions and role order.');

            return self::SUCCESS;
        }

        try {
            $botRoleIds = $client->members()->get($guildId, $botId)->roleIds;
        } catch (UnknownResourceException) {
            $this->error("The bot ({$botId}) is not a member of this server. Invite it first.");

            return self::FAILURE;
        }

        $everyone = array_values(array_filter($roles, static fn (Role $role): bool => $role->id === $guildId));
        $botRoles = array_values(array_filter($roles, static fn (Role $role): bool => in_array($role->id, $botRoleIds, true)));
        $highestPosition = max([0, ...array_map(static fn (Role $role): int => $role->position, $botRoles)]);

        $this->reportPermissions([...$everyone, ...$botRoles]);

        $blocked = array_values(array_filter(
            $roles,
            static fn (Role $role): bool => $role->id !== $guildId && ($role->managed || $role->position >= $highestPosition),
        ));

        if ($blocked !== []) {
            $this->newLine();
            $this->line("Roles the bot cannot give or edit (its highest role is at position {$highestPosition}):");
            $this->table(['Role', 'Id', 'Position', 'Why'], array_map(
                static fn (Role $role): array => [
                    $role->name,
                    $role->id,
                    $role->position,
                    $role->managed ? 'managed by an integration' : 'at or above the bot\'s highest role',
                ],
                $blocked,
            ));
        }

        return $this->checkRequiredRoles($roles, $blocked) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<Role>  $roles  The bot's roles plus @everyone
     */
    private function reportPermissions(array $roles): void
    {
        $granted = [];

        foreach ($roles as $role) {
            foreach (Permission::fromBitfield($role->permissions) as $permission) {
                $granted[$permission->name] = $permission;
            }
        }

        if (isset($granted[Permission::Administrator->name])) {
            $this->warn('The bot has Administrator. It only needs the permissions it uses: '.$this->names(self::USED_PERMISSIONS).'.');

            return;
        }

        $missing = array_values(array_filter(
            self::USED_PERMISSIONS,
            static fn (Permission $permission): bool => ! isset($granted[$permission->name]),
        ));

        if ($missing === []) {
            $this->info('The bot has every permission this package uses.');

            return;
        }

        $this->warn('The bot lacks: '.$this->names($missing).'. Calls that need them will fail with a 403.');
    }

    /**
     * @param  list<Role>  $roles
     * @param  list<Role>  $blocked
     */
    private function checkRequiredRoles(array $roles, array $blocked): bool
    {
        $known = array_map(static fn (Role $role): string => $role->id, $roles);
        $blockedIds = array_map(static fn (Role $role): string => $role->id, $blocked);
        $passed = true;

        /** @var list<string> $required */
        $required = array_values(array_filter((array) $this->option('role'), is_string(...)));

        foreach ($required as $roleId) {
            if (! in_array($roleId, $known, true)) {
                $this->error("Role {$roleId} does not exist on this server.");
                $passed = false;
            } elseif (in_array($roleId, $blockedIds, true)) {
                $this->error("Role {$roleId} sits at or above the bot's highest role. Drag the bot's role above it in Server Settings → Roles.");
                $passed = false;
            } else {
                $this->info("Role {$roleId}: the bot can give and edit it.");
            }
        }

        return $passed;
    }

    /**
     * The option, or the id Discord encodes in the token's first part.
     */
    private function botId(): ?string
    {
        $option = $this->option('bot-id');

        if (is_string($option) && ctype_digit($option)) {
            return $option;
        }

        $token = config('discord-server.bot_token');
        $token = is_string($token) ? trim($token) : '';

        // The client accepts a token with a "Bot " prefix, so the check does too.
        if (str_starts_with($token, 'Bot ')) {
            $token = substr($token, 4);
        }

        $firstPart = explode('.', $token)[0];
        $decoded = base64_decode(strtr($firstPart, '-_', '+/'), true);

        return is_string($decoded) && ctype_digit($decoded) ? $decoded : null;
    }

    private function hintFor(DiscordException $exception): string
    {
        return match (true) {
            $exception instanceof InvalidTokenException => 'Check DISCORD_BOT_TOKEN: copy it again from the developer portal (Bot → Reset Token).',
            $exception instanceof UnknownResourceException => 'Check DISCORD_GUILD_ID: in Discord, right-click the server icon → Copy Server ID.',
            $exception instanceof MissingPermissionException => 'The bot cannot see this server. Invite it with the OAuth2 URL Generator.',
            default => 'Try again in a moment; Discord may be busy.',
        };
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function names(array $permissions): string
    {
        return implode(', ', array_map(
            static fn (Permission $permission): string => (string) preg_replace('/(?<!^)([A-Z])/', ' $1', $permission->name),
            $permissions,
        ));
    }
}
