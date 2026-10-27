<?php

declare(strict_types=1);

use Dainc007\DiscordRestClient\Enums\Permission;
use Dainc007\DiscordRestClient\Exceptions\InvalidTokenException;
use Dainc007\DiscordRestClient\Exceptions\UnknownResourceException;
use Dainc007\DiscordRestClient\Testing\RecordedRequest;
use Dainc007\LaravelDiscordServer\Facades\DiscordServer;
use Dainc007\LaravelDiscordServer\Models\DiscordChange;

const BOT_ID = '555000000000000000';

beforeEach(function (): void {
    // Discord tokens start with the bot's user id in base64.
    config([
        'discord-server.guild_id' => '42',
        'discord-server.bot_token' => base64_encode(BOT_ID).'.abc.def',
    ]);
});

/**
 * @return list<array<string, mixed>>
 */
function serverRoles(string $botPermissions): array
{
    return [
        rolePayload(['id' => '42', 'name' => '@everyone', 'position' => 0, 'permissions' => '0']),
        rolePayload(['id' => '1', 'name' => 'Player', 'position' => 1, 'permissions' => '0']),
        rolePayload(['id' => '2', 'name' => 'Bot', 'position' => 2, 'permissions' => $botPermissions]),
        rolePayload(['id' => '3', 'name' => 'Admin', 'position' => 3, 'permissions' => '0']),
        rolePayload(['id' => '4', 'name' => 'Twitch', 'position' => 0, 'managed' => true, 'permissions' => '0']),
    ];
}

function guildPayload(): array
{
    return ['id' => '42', 'name' => 'Test Server', 'owner_id' => '1'];
}

$allUsed = Permission::combine(
    Permission::ManageRoles, Permission::ManageChannels, Permission::ManageNicknames,
    Permission::ModerateMembers, Permission::KickMembers, Permission::BanMembers,
);

it('reports a healthy setup and lists the roles the bot cannot change', function () use ($allUsed): void {
    $discord = DiscordServer::fake([guildPayload(), serverRoles($allUsed), memberPayload(BOT_ID, ['2'])]);

    $this->artisan('discord-server:check', ['--role' => ['1']])
        ->expectsOutputToContain('Connected to "Test Server" (42); it has 5 roles.')
        ->expectsOutputToContain('The bot has every permission this package uses.')
        ->expectsOutputToContain('Roles the bot cannot give or edit')
        ->expectsOutputToContain('Role 1: the bot can give and edit it.')
        ->assertSuccessful();

    $discord->assertSent('GET /guilds/{guild}/members/{user}', fn (RecordedRequest $r): bool => $r->parameter('user') === BOT_ID);
    expect(DiscordChange::query()->count())->toBe(0);
});

it('fails for a required role that sits above the bot, and for one that does not exist', function (string $roleId, string $message) use ($allUsed): void {
    DiscordServer::fake([guildPayload(), serverRoles($allUsed), memberPayload(BOT_ID, ['2'])]);

    $this->artisan('discord-server:check', ['--role' => [$roleId]])
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'above the bot' => ['3', 'Role 3 sits at or above the bot\'s highest role.'],
    'the bot\'s own role' => ['2', 'Role 2 sits at or above the bot\'s highest role.'],
    'managed by an integration' => ['4', 'Role 4 sits at or above the bot\'s highest role.'],
    'unknown' => ['99', 'Role 99 does not exist on this server.'],
]);

it('names the permissions the bot lacks', function (): void {
    DiscordServer::fake([guildPayload(), serverRoles(Permission::combine(Permission::ManageRoles)), memberPayload(BOT_ID, ['2'])]);

    $this->artisan('discord-server:check')
        ->expectsOutputToContain('The bot lacks: Manage Channels, Manage Nicknames, Moderate Members, Kick Members, Ban Members.')
        ->assertSuccessful();
});

it('counts permissions given to @everyone', function () use ($allUsed): void {
    $roles = serverRoles('0');
    $roles[0]['permissions'] = $allUsed;
    DiscordServer::fake([guildPayload(), $roles, memberPayload(BOT_ID, ['2'])]);

    $this->artisan('discord-server:check')
        ->expectsOutputToContain('The bot has every permission this package uses.')
        ->assertSuccessful();
});

it('warns when the bot has Administrator', function (): void {
    DiscordServer::fake([guildPayload(), serverRoles(Permission::combine(Permission::Administrator)), memberPayload(BOT_ID, ['2'])]);

    $this->artisan('discord-server:check')
        ->expectsOutputToContain('The bot has Administrator.')
        ->assertSuccessful();
});

it('treats a bot with no roles as able to change nothing', function () use ($allUsed): void {
    DiscordServer::fake([guildPayload(), serverRoles($allUsed), memberPayload(BOT_ID, [])]);

    $this->artisan('discord-server:check', ['--role' => ['1']])
        ->expectsOutputToContain('its highest role is at position 0')
        ->assertFailed();
});

it('fails when the bot is not on the server', function () use ($allUsed): void {
    DiscordServer::fake([guildPayload(), serverRoles($allUsed), new UnknownResourceException('Unknown Member', 404, 10007)]);

    $this->artisan('discord-server:check')
        ->expectsOutputToContain('is not a member of this server')
        ->assertFailed();
});

it('explains a rejected token', function (): void {
    DiscordServer::fake([new InvalidTokenException('401: Unauthorized', 401, 0)]);

    $this->artisan('discord-server:check')
        ->expectsOutputToContain('Check DISCORD_BOT_TOKEN')
        ->assertFailed();
});

it('explains an unknown server id', function (): void {
    DiscordServer::fake([new UnknownResourceException('Unknown Guild', 404, 10004)]);

    $this->artisan('discord-server:check')
        ->expectsOutputToContain('Check DISCORD_GUILD_ID')
        ->assertFailed();
});

it('fails before calling Discord when no server id is set', function (): void {
    $discord = DiscordServer::fake();
    config(['discord-server.guild_id' => '']);

    $this->artisan('discord-server:check')
        ->expectsOutputToContain('DISCORD_GUILD_ID')
        ->assertFailed();

    $discord->assertNothingSent();
});

it('stops after the server check when the bot id cannot be read from the token', function () use ($allUsed): void {
    config(['discord-server.bot_token' => 'not-a-real-token']);
    $discord = DiscordServer::fake([guildPayload(), serverRoles($allUsed)]);

    $this->artisan('discord-server:check')
        ->expectsOutputToContain('Pass --bot-id')
        ->assertSuccessful();

    $discord->assertSentCount(2);
});

it('reads the bot id from a token written with the "Bot " prefix', function () use ($allUsed): void {
    config(['discord-server.bot_token' => 'Bot '.base64_encode(BOT_ID).'.abc.def']);
    $discord = DiscordServer::fake([guildPayload(), serverRoles($allUsed), memberPayload(BOT_ID, ['2'])]);

    $this->artisan('discord-server:check')->assertSuccessful();

    $discord->assertSent('GET /guilds/{guild}/members/{user}', fn (RecordedRequest $r): bool => $r->parameter('user') === BOT_ID);
});

it('takes the bot id from the option', function () use ($allUsed): void {
    config(['discord-server.bot_token' => 'not-a-real-token']);
    $discord = DiscordServer::fake([guildPayload(), serverRoles($allUsed), memberPayload('777', ['2'])]);

    $this->artisan('discord-server:check', ['--bot-id' => '777'])->assertSuccessful();

    $discord->assertSent('GET /guilds/{guild}/members/{user}', fn (RecordedRequest $r): bool => $r->parameter('user') === '777');
});
