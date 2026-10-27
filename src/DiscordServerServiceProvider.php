<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer;

use Dainc007\DiscordRestClient\Client;
use Dainc007\DiscordRestClient\Contracts\ClientContract;
use Dainc007\LaravelDiscordServer\Commands\CheckCommand;
use Dainc007\LaravelDiscordServer\Exceptions\DiscordServerNotConfigured;
use Dainc007\LaravelDiscordServer\RateLimit\CacheBucketStore;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class DiscordServerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-discord-server')
            ->hasConfigFile('discord-server')
            ->hasMigration('create_discord_changes_table')
            ->hasCommand(CheckCommand::class);
    }

    public function packageRegistered(): void
    {
        // Scoped, not singleton: under Octane each request gets a fresh client,
        // while the rate-limit state it needs lives in the cache.
        $this->app->scoped(ClientContract::class, static function (Application $app): ClientContract {
            $token = trim(config()->string('discord-server.bot_token', ''));

            if ($token === '') {
                throw DiscordServerNotConfigured::missingToken();
            }

            $store = config('discord-server.rate_limits.store');

            return Client::make(
                $token,
                membersIntent: config()->boolean('discord-server.members_intent', false),
                httpClient: new GuzzleClient([
                    'timeout' => config()->integer('discord-server.http.timeout', 10),
                    'connect_timeout' => config()->integer('discord-server.http.connect_timeout', 5),
                ]),
                bucketStore: new CacheBucketStore(
                    $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null),
                    config()->string('discord-server.rate_limits.prefix', 'discord-server:rate-limit:'),
                ),
                maxRetries: config()->integer('discord-server.max_retries', 3),
                maxRetryAfterSeconds: config()->float('discord-server.max_retry_after_seconds', 60.0),
            );
        });

        $this->app->scoped(DiscordServer::class, static fn (Application $app): DiscordServer => new DiscordServer($app));
    }
}
