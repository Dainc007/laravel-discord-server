<?php

declare(strict_types=1);

use Dainc007\DiscordRestClient\Contracts\ClientContract;
use Dainc007\DiscordRestClient\Exceptions\DiscordApiException;
use Dainc007\DiscordRestClient\Exceptions\MissingPermissionException;
use Dainc007\LaravelDiscordServer\DiscordServerServiceProvider;
use Dainc007\LaravelDiscordServer\Facades\DiscordServer;
use Dainc007\LaravelDiscordServer\Models\DiscordChange;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

it('publishes the config and the migration under the package tags', function (string $tag): void {
    expect(ServiceProvider::pathsToPublish(DiscordServerServiceProvider::class, $tag))->not->toBeEmpty();
})->with(['discord-server-config', 'discord-server-migrations']);

it('drops the change log table on rollback', function (): void {
    $migration = require __DIR__.'/../../database/migrations/create_discord_changes_table.php.stub';

    $migration->down();

    expect(Schema::hasTable('discord_changes'))->toBeFalse();
});

it('keeps the fake when scoped services are reset, as a queue worker does between jobs', function (): void {
    $fake = DiscordServer::fake();

    app()->forgetScopedInstances();

    expect(app(ClientContract::class))->toBe($fake)
        ->and(DiscordServer::client())->toBe($fake);
});

it('accepts a server id typed as a number in the config', function (): void {
    config(['discord-server.guild_id' => 1367822479]);

    expect(DiscordServer::guildId())->toBe('1367822479');
});

it('prunes log entries older than the kept days', function (): void {
    config(['discord-server.change_log.keep_days' => 30]);
    $old = DiscordChange::query()->create(['guild_id' => '42', 'action' => 'member.kick', 'succeeded' => true]);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    $recent = DiscordChange::query()->create(['guild_id' => '42', 'action' => 'member.kick', 'succeeded' => true]);
    $recent->forceFill(['created_at' => now()->subDays(29)])->save();

    $this->artisan('model:prune', ['--model' => [DiscordChange::class]])->assertSuccessful();

    expect(DiscordChange::query()->pluck('id')->all())->toBe([$recent->id]);
});

it('keeps every entry when the kept days are null', function (): void {
    config(['discord-server.change_log.keep_days' => null]);
    DiscordChange::query()->create(['guild_id' => '42', 'action' => 'member.kick', 'succeeded' => true])
        ->forceFill(['created_at' => now()->subYears(3)])->save();

    $this->artisan('model:prune', ['--model' => [DiscordChange::class]])->assertSuccessful();

    expect(DiscordChange::query()->count())->toBe(1);
});

it('logs a refused member change for every member method', function (Closure $call, string $action): void {
    config(['discord-server.guild_id' => '42']);
    DiscordServer::fake([new MissingPermissionException('Missing Permissions', 403, 50013)]);

    expect($call)->toThrow(MissingPermissionException::class);

    expect(DiscordChange::query()->sole())
        ->action->toBe($action)
        ->succeeded->toBeFalse()
        ->discord_code->toBe(50013);
})->with([
    'kick' => [fn () => DiscordServer::kick('900'), 'member.kick'],
    'unban' => [fn () => DiscordServer::unban('900'), 'member.unban'],
    'removeRole' => [fn () => DiscordServer::removeRole('900', '111'), 'member.remove_role'],
    'ban' => [fn () => DiscordServer::ban('900'), 'member.ban'],
]);

it('logs Discord\'s field errors message for a rejected role edit', function (): void {
    config(['discord-server.guild_id' => '42']);
    DiscordServer::fake([new DiscordApiException('Invalid Form Body', 400, 50035)]);

    expect(fn () => DiscordServer::editRole('111', ['color' => 1]))->toThrow(DiscordApiException::class);

    expect(DiscordChange::query()->sole())->http_status->toBe(400)->error->toBe('Invalid Form Body');
});
