<?php

declare(strict_types=1);

use Dainc007\DiscordRestClient\Contracts\ClientContract;
use Dainc007\DiscordRestClient\Exceptions\MissingPermissionException;
use Dainc007\LaravelDiscordServer\Enums\ChangeTarget;
use Dainc007\LaravelDiscordServer\Events\DiscordChangeRecorded;
use Dainc007\LaravelDiscordServer\Facades\DiscordServer;
use Dainc007\LaravelDiscordServer\Models\DiscordChange;
use Dainc007\LaravelDiscordServer\Tests\Support\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function (): void {
    config(['discord-server.guild_id' => '42']);
});

it('hands back the result and the very row it logged', function (): void {
    DiscordServer::fake([null]);
    $user = User::query()->create(['name' => 'Anna']);

    $recorded = DiscordServer::actingAs($user)->recordChange(
        'roles.reorder',
        function (ClientContract $client, string $guildId, string $reason): string {
            $client->members()->addRole($guildId, '1', '2', $reason);

            // Another change logged while this one runs must not be mixed up with it.
            DiscordServer::recordChange('other', fn (): null => null);

            return 'done';
        },
        ChangeTarget::Guild,
        details: ['positions' => 3],
    );

    expect($recorded->result)->toBe('done')
        ->and($recorded->log)->toBeInstanceOf(DiscordChange::class)
        ->and($recorded->log?->action)->toBe('roles.reorder')
        ->and($recorded->log?->details)->toBe(['positions' => 3])
        ->and($recorded->log?->actor?->is($user))->toBeTrue()
        ->and($recorded->log?->is(DiscordChange::query()->where('action', 'roles.reorder')->sole()))->toBeTrue();
});

it('hands back no row when the change log is off', function (): void {
    config(['discord-server.change_log.enabled' => false]);
    Event::fake([DiscordChangeRecorded::class]);
    DiscordServer::fake();

    $recorded = DiscordServer::recordChange('custom', fn (): string => 'ok');

    expect($recorded->result)->toBe('ok')
        ->and($recorded->log)->toBeNull();
    Event::assertNotDispatched(DiscordChangeRecorded::class);
});

it('keeps record() returning only the result', function (): void {
    DiscordServer::fake();

    expect(DiscordServer::record('custom', fn (): int => 7))->toBe(7);
});

it('announces every logged change, including one Discord refused', function (): void {
    Event::fake([DiscordChangeRecorded::class]);
    DiscordServer::fake([null, new MissingPermissionException('Missing Permissions', 403, 50013)]);

    DiscordServer::addRole('900', '111');
    expect(fn () => DiscordServer::addRole('900', '222'))->toThrow(MissingPermissionException::class);

    Event::assertDispatchedTimes(DiscordChangeRecorded::class, 2);
    Event::assertDispatched(DiscordChangeRecorded::class, fn (DiscordChangeRecorded $event): bool => $event->change->succeeded);
    Event::assertDispatched(DiscordChangeRecorded::class, fn (DiscordChangeRecorded $event): bool => ! $event->change->succeeded
        && $event->change->discord_code === 50013
        && $event->change->exists);
});

it('reports a failing listener and still hands back an applied change', function (): void {
    Exceptions::fake();
    Event::listen(fn (DiscordChangeRecorded $event) => throw new RuntimeException('mail server down'));
    $discord = DiscordServer::fake([rolePayload(['id' => '555'])]);

    $role = DiscordServer::createRole('Captains');

    expect($role->id)->toBe('555')
        ->and(DiscordChange::query()->sole()->succeeded)->toBeTrue();
    $discord->assertSentCount(1);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'mail server down');
});

it('keeps Discord\'s own error when a listener fails on a refused change', function (): void {
    Exceptions::fake();
    Event::listen(fn (DiscordChangeRecorded $event) => throw new RuntimeException('mail server down'));
    DiscordServer::fake([new MissingPermissionException('Missing Permissions', 403, 50013)]);

    expect(fn () => DiscordServer::addRole('900', '111'))->toThrow(MissingPermissionException::class);

    Exceptions::assertReported(RuntimeException::class);
});
