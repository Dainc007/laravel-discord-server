<?php

declare(strict_types=1);

use Dainc007\DiscordRestClient\Contracts\ClientContract;
use Dainc007\DiscordRestClient\Data\Channel;
use Dainc007\DiscordRestClient\Enums\ChannelType;
use Dainc007\DiscordRestClient\Enums\Permission;
use Dainc007\DiscordRestClient\Exceptions\MissingPermissionException;
use Dainc007\DiscordRestClient\Exceptions\TransportException;
use Dainc007\DiscordRestClient\Testing\RecordedRequest;
use Dainc007\LaravelDiscordServer\Enums\ChangeTarget;
use Dainc007\LaravelDiscordServer\Exceptions\DiscordServerNotConfigured;
use Dainc007\LaravelDiscordServer\Facades\DiscordServer;
use Dainc007\LaravelDiscordServer\Models\DiscordChange;
use Dainc007\LaravelDiscordServer\Tests\Support\Moderator;
use Dainc007\LaravelDiscordServer\Tests\Support\ReorderAction;
use Dainc007\LaravelDiscordServer\Tests\Support\User;

beforeEach(function (): void {
    config(['discord-server.guild_id' => '42']);
});

it('gives a role with an audit reason naming the app, the actor and the reason, and logs it', function (): void {
    $discord = DiscordServer::fake([null]);
    $user = User::query()->create(['name' => 'Łukasz Żółć']);

    DiscordServer::actingAs($user)->because('linked account')->addRole('900', '111');

    $discord->assertSent(
        'PUT /guilds/{guild}/members/{user}/roles/{role}',
        fn (RecordedRequest $r): bool => $r->path() === '/guilds/42/members/900/roles/111'
            && $r->auditReason === 'My App: Łukasz Żółć: linked account',
    );

    $change = DiscordChange::query()->sole();
    expect($change->action)->toBe('member.add_role')
        ->and($change->guild_id)->toBe('42')
        ->and($change->target_type)->toBe('member')
        ->and($change->target_id)->toBe('900')
        ->and($change->details)->toBe(['role_id' => '111', 'reason' => 'linked account'])
        ->and($change->succeeded)->toBeTrue()
        ->and($change->actor->is($user))->toBeTrue();
});

it('logs a change with no actor and no reason, using only the prefix as audit reason', function (): void {
    $discord = DiscordServer::fake([null]);

    DiscordServer::removeRole('900', '111');

    $discord->assertSent('DELETE /guilds/{guild}/members/{user}/roles/{role}', fn (RecordedRequest $r): bool => $r->auditReason === 'My App');
    expect(DiscordChange::query()->sole())
        ->actor_type->toBeNull()
        ->details->toBe(['role_id' => '111']);
});

it('uses the name a model gives itself in the audit log', function (): void {
    $discord = DiscordServer::fake([null]);

    DiscordServer::actingAs(Moderator::query()->create(['name' => 'Ola']))->kick('900');

    $discord->assertSent('DELETE /guilds/{guild}/members/{user}', fn (RecordedRequest $r): bool => $r->auditReason === 'My App: Mod Ola');
});

it('falls back to the model class and key when the actor has no name', function (): void {
    $discord = DiscordServer::fake([null]);
    $user = User::query()->create(['name' => null]);

    DiscordServer::actingAs($user)->unban('900');

    $discord->assertSent('DELETE /guilds/{guild}/bans/{user}', fn (RecordedRequest $r): bool => $r->auditReason === "My App: User #{$user->id}");
});

it('leaves out an empty prefix and a whitespace-only reason', function (): void {
    config(['discord-server.audit_reason_prefix' => '']);
    $discord = DiscordServer::fake([null]);

    DiscordServer::because('   ')->addRole('900', '111');

    // The real client sends no audit-log header for an empty reason.
    expect($discord->recorded()[0]->auditReason)->toBe('')
        ->and(DiscordChange::query()->sole()->details)->toBe(['role_id' => '111']);
});

it('logs a refused change with Discord\'s status and code, then rethrows the original exception', function (): void {
    DiscordServer::fake([new MissingPermissionException('Missing Permissions', 403, 50013)]);

    expect(fn () => DiscordServer::because('promote')->addRole('900', '111'))
        ->toThrow(MissingPermissionException::class);

    expect(DiscordChange::query()->sole())
        ->succeeded->toBeFalse()
        ->http_status->toBe(403)
        ->discord_code->toBe(50013)
        ->error->toBe('Missing Permissions')
        ->details->toBe(['role_id' => '111', 'reason' => 'promote']);
});

it('logs a network failure without a status or code', function (): void {
    DiscordServer::fake([new TransportException('Connection timed out')]);

    expect(fn () => DiscordServer::kick('900'))->toThrow(TransportException::class);

    expect(DiscordChange::query()->sole())
        ->succeeded->toBeFalse()
        ->http_status->toBeNull()
        ->discord_code->toBeNull();
});

it('does not log or call Discord when no server id is configured', function (): void {
    $discord = DiscordServer::fake([null]);
    config(['discord-server.guild_id' => '']);

    expect(fn () => DiscordServer::addRole('900', '111'))->toThrow(DiscordServerNotConfigured::class, 'DISCORD_GUILD_ID');

    $discord->assertNothingSent();
    expect(DiscordChange::query()->count())->toBe(0);
});

it('refuses to build a real client without a bot token', function (): void {
    config(['discord-server.bot_token' => '']);

    expect(fn () => DiscordServer::client())->toThrow(DiscordServerNotConfigured::class, 'DISCORD_BOT_TOKEN');
});

it('builds a real client when a token is set', function (): void {
    config(['discord-server.bot_token' => 'test-token']);

    expect(DiscordServer::client())->toBeInstanceOf(ClientContract::class);
});

it('sends changes to another server with forGuild', function (): void {
    $discord = DiscordServer::fake([null]);

    DiscordServer::forGuild('77')->addRole('900', '111');

    $discord->assertSent('PUT /guilds/{guild}/members/{user}/roles/{role}', fn (RecordedRequest $r): bool => $r->parameter('guild') === '77');
    expect(DiscordChange::query()->sole()->guild_id)->toBe('77');
});

it('keeps the configured actor and reason on a copy only', function (): void {
    $discord = DiscordServer::fake([null, null]);
    $withReason = DiscordServer::because('first');

    $withReason->addRole('900', '111');
    DiscordServer::addRole('900', '222');

    expect($discord->recorded()[0]->auditReason)->toBe('My App: first')
        ->and($discord->recorded()[1]->auditReason)->toBe('My App');
});

it('stores the id Discord gives a created role, with its name', function (): void {
    $discord = DiscordServer::fake([rolePayload(['id' => '555', 'name' => 'Zarząd'])]);

    $role = DiscordServer::createRole('Zarząd', [Permission::ViewChannel], color: 0xFF0000);

    expect($role->id)->toBe('555');
    $discord->assertSent('POST /guilds/{guild}/roles', fn (RecordedRequest $r): bool => $r->body['name'] === 'Zarząd' && $r->body['color'] === 0xFF0000);
    expect(DiscordChange::query()->sole())
        ->action->toBe('role.create')
        ->target_type->toBe('role')
        ->target_id->toBe('555')
        ->target_name->toBe('Zarząd');
});

it('logs which fields of a role were edited, and the new name', function (): void {
    DiscordServer::fake([rolePayload(['name' => 'Captains'])]);

    DiscordServer::editRole('111', ['name' => 'Captains', 'hoist' => true]);

    expect(DiscordChange::query()->sole())
        ->target_id->toBe('111')
        ->target_name->toBe('Captains')
        ->details->toBe(['fields' => ['name', 'hoist']]);
});

it('deletes a role', function (): void {
    $discord = DiscordServer::fake([null]);

    DiscordServer::deleteRole('111');

    $discord->assertSent('DELETE /guilds/{guild}/roles/{role}');
    expect(DiscordChange::query()->sole()->action)->toBe('role.delete');
});

it('creates, edits and deletes a channel', function (): void {
    $discord = DiscordServer::fake([
        ['id' => '20', 'type' => 2, 'name' => 'Szatnia'],
        ['id' => '20', 'type' => 2, 'name' => 'Lobby'],
        ['id' => '20', 'type' => 2, 'name' => 'Lobby'],
    ]);

    $channel = DiscordServer::createChannel('Szatnia', ChannelType::GuildVoice);
    DiscordServer::editChannel($channel->id, ['name' => 'Lobby']);
    $deleted = DiscordServer::deleteChannel($channel->id);

    expect($deleted)->toBeInstanceOf(Channel::class);
    $discord->assertSentCount(3);
    expect(DiscordChange::query()->pluck('action')->all())->toBe(['channel.create', 'channel.edit', 'channel.delete'])
        ->and(DiscordChange::query()->pluck('target_id')->all())->toBe(['20', '20', '20'])
        ->and(DiscordChange::query()->first()?->details)->toBe(['type' => 2]);
});

it('sets and resets a nickname', function (): void {
    $discord = DiscordServer::fake([memberPayload(overrides: ['nick' => 'Kapitan']), memberPayload()]);

    expect(DiscordServer::setNickname('900', 'Kapitan')->nick)->toBe('Kapitan');
    DiscordServer::setNickname('900', null);

    expect($discord->recorded()[1]->body)->toBe(['nick' => null])
        ->and(DiscordChange::query()->pluck('details')->all())->toBe([['nickname' => 'Kapitan'], ['nickname' => null]]);
});

it('times a member out and lifts the timeout', function (): void {
    DiscordServer::fake([memberPayload(), memberPayload()]);
    $until = new DateTimeImmutable('2026-11-01T12:00:00+00:00');

    DiscordServer::timeout('900', $until);
    DiscordServer::timeout('900', null);

    expect(DiscordChange::query()->pluck('details')->all())
        ->toBe([['until' => '2026-11-01T12:00:00+00:00'], ['until' => null]]);
});

it('bans a member, deleting recent messages', function (): void {
    $discord = DiscordServer::fake([null]);

    DiscordServer::ban('900', deleteMessageSeconds: 3600);

    $discord->assertSent('PUT /guilds/{guild}/bans/{user}', fn (RecordedRequest $r): bool => $r->body === ['delete_message_seconds' => 3600]);
});

it('records an app\'s own change and returns its result', function (): void {
    $discord = DiscordServer::fake([null]);

    $result = DiscordServer::record(
        ReorderAction::Reorder,
        function (ClientContract $client, string $guildId, string $reason): string {
            $client->members()->addRole($guildId, '1', '2', $reason);

            return 'done';
        },
        ChangeTarget::Guild,
    );

    expect($result)->toBe('done')
        ->and(DiscordChange::query()->sole()->action)->toBe('roles.reorder');
    $discord->assertSentCount(1);
});

it('does not log a failure that is not from Discord', function (): void {
    DiscordServer::fake();

    expect(fn () => DiscordServer::record('custom', fn (): never => throw new InvalidArgumentException('bad input')))
        ->toThrow(InvalidArgumentException::class);

    expect(DiscordChange::query()->count())->toBe(0);
});

it('skips the log when it is switched off', function (): void {
    config(['discord-server.change_log.enabled' => false]);
    $discord = DiscordServer::fake([null]);

    DiscordServer::addRole('900', '111');

    $discord->assertSentCount(1);
    expect(DiscordChange::query()->count())->toBe(0);
});

it('uses the configured table for the log', function (): void {
    config(['discord-server.change_log.table' => 'discord_changes']);

    expect((new DiscordChange)->getTable())->toBe('discord_changes');

    config(['discord-server.change_log.table' => '']);

    expect((new DiscordChange)->getTable())->toBe('discord_changes');
});

it('fills in a server id for the fake when none is configured', function (): void {
    config(['discord-server.guild_id' => '']);
    DiscordServer::fake([null]);

    DiscordServer::addRole('900', '111');

    expect(DiscordChange::query()->sole()->guild_id)->toBe('100000000000000000');
});
