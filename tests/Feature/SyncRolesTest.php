<?php

declare(strict_types=1);

use Dainc007\DiscordRestClient\Exceptions\UnknownResourceException;
use Dainc007\DiscordRestClient\Testing\RecordedRequest;
use Dainc007\LaravelDiscordServer\Facades\DiscordServer;
use Dainc007\LaravelDiscordServer\Models\DiscordChange;

beforeEach(function (): void {
    config(['discord-server.guild_id' => '42']);
});

it('adds missing wanted roles and removes managed roles that are no longer wanted', function (): void {
    // Member has Player (1) and Captain (2), plus a hand-given role (9).
    $discord = DiscordServer::fake([memberPayload('900', ['1', '2', '9']), null, null]);

    $result = DiscordServer::syncRoles('900', wanted: ['1', '3'], managed: ['1', '2', '3']);

    expect($result)->toBe(['added' => ['3'], 'removed' => ['2']]);
    $discord->assertSent('PUT /guilds/{guild}/members/{user}/roles/{role}', fn (RecordedRequest $r): bool => $r->parameter('role') === '3');
    $discord->assertSent('DELETE /guilds/{guild}/members/{user}/roles/{role}', fn (RecordedRequest $r): bool => $r->parameter('role') === '2');
    $discord->assertNotSent('DELETE /guilds/{guild}/members/{user}/roles/{role}', fn (RecordedRequest $r): bool => $r->parameter('role') === '9');
    expect(DiscordChange::query()->pluck('action')->all())->toBe(['member.add_role', 'member.remove_role']);
});

it('changes nothing when the member already has exactly the wanted roles', function (): void {
    $discord = DiscordServer::fake([memberPayload('900', ['1'])]);

    expect(DiscordServer::syncRoles('900', ['1'], ['1', '2']))->toBe(['added' => [], 'removed' => []]);

    $discord->assertSentCount(1);
    expect(DiscordChange::query()->count())->toBe(0);
});

it('removes every managed role when nothing is wanted', function (): void {
    DiscordServer::fake([memberPayload('900', ['1', '2']), null, null]);

    expect(DiscordServer::syncRoles('900', [], ['1', '2']))->toBe(['added' => [], 'removed' => ['1', '2']]);
});

it('stops before any change when the member is not on the server', function (): void {
    $discord = DiscordServer::fake([new UnknownResourceException('Unknown Member', 404, 10007)]);

    expect(fn () => DiscordServer::syncRoles('900', ['1'], ['1']))->toThrow(UnknownResourceException::class);

    $discord->assertSentCount(1);
    expect(DiscordChange::query()->count())->toBe(0);
});
