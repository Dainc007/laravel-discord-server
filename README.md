# laravel-discord-server

[![Tests](https://github.com/dainc007/laravel-discord-server/actions/workflows/tests.yml/badge.svg)](https://github.com/dainc007/laravel-discord-server/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/dainc007/laravel-discord-server.svg)](https://packagist.org/packages/dainc007/laravel-discord-server)
[![PHP version](https://img.shields.io/packagist/dependency-v/dainc007/laravel-discord-server/php.svg)](https://packagist.org/packages/dainc007/laravel-discord-server)

Manage a Discord server from Laravel with a bot token: give and take roles, set nicknames, time out, kick and ban members, create and edit roles and channels. Every change shows up in Discord's own audit log with who made it and why, and is written to a change log table, also when Discord refuses it. Built on [`dainc007/discord-rest-client`](https://github.com/dainc007/discord-rest-client).

## Install

```bash
composer require dainc007/laravel-discord-server
php artisan vendor:publish --tag="discord-server-migrations"
php artisan migrate
```

Then add to `.env`:

```dotenv
DISCORD_BOT_TOKEN=your-bot-token
DISCORD_GUILD_ID=your-server-id
```

Requires PHP 8.3+ and Laravel 12 or 13. To change the defaults, publish the config: `php artisan vendor:publish --tag="discord-server-config"`.

Creating the bot, inviting it with the right permissions and finding the server id are described in the [client's setup guide](https://github.com/dainc007/discord-rest-client#setting-up-discord). The step that trips most people: the bot's role must sit above every role it should give or edit.

### Check the setup

```bash
php artisan discord-server:check
php artisan discord-server:check --role=123456789012345678   # a role your app gives
```

It reads the server with your token and reports: whether the bot reaches it, which permissions this package uses are missing, and which roles sit at or above the bot's highest role (the bot cannot give or edit those). It changes nothing. The bot's user id is read from the token; pass `--bot-id=` if that fails. It exits with an error when the server cannot be read or a `--role` is out of the bot's reach, so it also works in a deploy script.

## Usage

```php
use Dainc007\LaravelDiscordServer\Facades\DiscordServer;

DiscordServer::actingAs($request->user())
    ->because('Linked Discord account')
    ->addRole($discordUserId, $playerRoleId);
```

Discord's audit log then shows "My App: Anna: Linked Discord account" (the prefix is `APP_NAME`), and a row lands in `discord_changes`.

`actingAs()`, `because()` and `forGuild()` each return a copy, so a configured instance can be kept:

```php
use Dainc007\DiscordRestClient\Enums\ChannelType;

$discord = DiscordServer::actingAs($admin)->because('Season 3 start');
$discord->createRole('Season 3');
$discord->createChannel('season-3', ChannelType::GuildText);
```

| Members | Roles | Channels |
|---|---|---|
| `addRole`, `removeRole`, `syncRoles`, `setNickname`, `timeout`, `kick`, `ban`, `unban` | `createRole`, `editRole`, `deleteRole` | `createChannel`, `editChannel`, `deleteChannel` |

Reads go straight to the client and skip the change log:

```php
$roles   = DiscordServer::client()->guilds()->roles(DiscordServer::guildId());
$members = DiscordServer::client()->members()->search(DiscordServer::guildId(), 'anna');
```

### Keeping roles in step with your app

`syncRoles()` gives a member every role in `wanted`, and takes away each role listed in `managed` but missing from `wanted`. Roles outside both lists, such as ones a moderator gave by hand, stay.

```php
DiscordServer::syncRoles($discordUserId,
    wanted: $user->isCaptain() ? [$playerRoleId, $captainRoleId] : [$playerRoleId],
    managed: [$playerRoleId, $captainRoleId],
); // ['added' => [...], 'removed' => [...]]
```

Your app decides who gets which role and which Discord account belongs to which user, and passes both in.

### Your own changes

For a write without its own method (reordering, permission overwrites), wrap it in `record()` to get the audit reason and the log entry:

```php
use Dainc007\DiscordRestClient\Contracts\ClientContract;
use Dainc007\LaravelDiscordServer\Enums\ChangeTarget;

DiscordServer::actingAs($admin)->record(
    'roles.reorder',
    fn (ClientContract $client, string $guildId, string $auditReason) => $client->roles()->reorder($guildId, $positions, $auditReason),
    ChangeTarget::Guild,
);
```

To keep working with the log row the change wrote (show it, link to it, add to it), use `recordChange()`. It returns the change's result and that exact row, even when other changes are logged at the same moment:

```php
$recorded = DiscordServer::actingAs($admin)->recordChange('roles.reorder', $change, ChangeTarget::Guild);

$recorded->result; // what the closure returned
$recorded->log;    // the DiscordChange row, or null when the change log is off
```

### Who made the change

Any Eloquent model can be the actor. Discord's audit log shows its `name` attribute. To choose another name, implement `NamedDiscordActor`:

```php
use Dainc007\LaravelDiscordServer\Contracts\NamedDiscordActor;

class Admin extends Model implements NamedDiscordActor
{
    public function discordActorName(): string
    {
        return $this->display_name;
    }
}
```

## Call Discord from a queued job

A call can take a while: up to 10 seconds per try, a few retries, and a wait when Discord's rate limit says so (up to 60 seconds by default). Inside a web request, that is a page that hangs. Make changes from a queued job:

```php
use Dainc007\DiscordRestClient\Exceptions\MissingPermissionException;
use Dainc007\LaravelDiscordServer\Facades\DiscordServer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GiveDiscordRole implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public User $user, public string $roleId) {}

    public function handle(): void
    {
        try {
            DiscordServer::actingAs($this->user)
                ->because('Linked Discord account')
                ->addRole($this->user->discord_id, $this->roleId);
        } catch (MissingPermissionException $exception) {
            $this->fail($exception); // retrying will not fix the role order
        }
    }
}

GiveDiscordRole::dispatch($user, $playerRoleId);
```

If a call must run during a request, publish the config and lower `max_retries` and `max_retry_after_seconds`, so a busy Discord fails fast.

## When Discord says no

A refused change is logged with `succeeded = false`, Discord's HTTP status, its error code and message. Then the client's exception is thrown again unchanged, so your app decides what to show: `MissingPermissionException` (403, often the role order), `UnknownResourceException` (404), `RateLimitedException`, `InvalidTokenException`, `TransportException` (network). All extend `DiscordException`. See the [client's error list](https://github.com/dainc007/discord-rest-client#errors).

A missing token or server id throws `DiscordServerNotConfigured` before any request.

## The change log

Every logged change fires `Dainc007\LaravelDiscordServer\Events\DiscordChangeRecorded`, also when Discord refused it (`$event->change->succeeded` tells which), so an app can notify someone or react. This event is the only way to reach the row of a refused change: `recordChange()` throws Discord's exception instead of returning. An error thrown by a listener is reported (`report()`) and does not stop the change or replace Discord's error:

```php
use Dainc007\LaravelDiscordServer\Events\DiscordChangeRecorded;
use Illuminate\Support\Facades\Event;

Event::listen(function (DiscordChangeRecorded $event): void {
    if (! $event->change->succeeded) {
        // tell the admins
    }
});
```

`Dainc007\LaravelDiscordServer\Models\DiscordChange`, one row per change:

| Column | |
|---|---|
| `actor_type`, `actor_id` | who (`actor()` relation), null for the console or a job |
| `guild_id`, `action` | where and what, e.g. `member.add_role` |
| `target_type`, `target_id`, `target_name` | the member, role or channel |
| `details` | JSON: role id, nickname, the reason, ... |
| `succeeded`, `http_status`, `discord_code`, `error` | the outcome |

Turn it off with `change_log.enabled = false`, or rename the table with `change_log.table` before migrating.

Entries older than `change_log.keep_days` (90 by default; `null` keeps everything) are deleted by Laravel's `model:prune`. The model lives outside `app/Models`, so name it when you schedule the command:

```php
use Dainc007\LaravelDiscordServer\Models\DiscordChange;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [DiscordChange::class]])->daily();
```

## Rate limits

Discord's rate-limit buckets are kept in the Laravel cache, so web requests and queue workers know about each other's limits. This is best effort: two workers can read "one request left" at the same moment, and one of them then gets a 429, waits and retries. Set `DISCORD_RATE_LIMIT_STORE` to pick a store; the `array` store keeps them per process. The client's [rate-limit notes](https://github.com/dainc007/discord-rest-client#rate-limits) still apply: the 50-requests-a-second count is per process.

The client is bound as a scoped service, so under Octane each request gets its own.

## Testing your app

```php
use Dainc007\DiscordRestClient\Testing\RecordedRequest;
use Dainc007\LaravelDiscordServer\Facades\DiscordServer;

$discord = DiscordServer::fake([null]); // one queued response: 204 for addRole

$this->post('/link-discord')->assertOk();

$discord->assertSent('PUT /guilds/{guild}/members/{user}/roles/{role}',
    fn (RecordedRequest $r) => $r->parameter('role') === $playerRoleId);
```

The fake makes no HTTP calls and the change log keeps working. When no server id is configured, the fake fills one in. Queue a `DiscordException` to test a refusal. The fake stays in place when Laravel resets per-request services, as a queue worker does between jobs.

## Planned

A queued version of the change methods. A Filament plugin with pages for roles, channels, members and the change log comes as a separate package.

## Package tests

```bash
composer test
composer analyse
```

## Changelog and security

See [CHANGELOG.md](CHANGELOG.md). To report a security problem, see [SECURITY.md](SECURITY.md).

## Licence

MIT. See [LICENSE.md](LICENSE.md).
