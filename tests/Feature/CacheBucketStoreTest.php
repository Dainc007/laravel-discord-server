<?php

declare(strict_types=1);

use Dainc007\DiscordRestClient\Client;
use Dainc007\DiscordRestClient\Contracts\ClientContract;
use Dainc007\DiscordRestClient\RateLimit\BucketState;
use Dainc007\LaravelDiscordServer\RateLimit\CacheBucketStore;
use Illuminate\Support\Facades\Cache;

it('starts empty', function (): void {
    $store = new CacheBucketStore(Cache::store('array'));

    expect($store->bucketForRoute('GET /guilds/{guild}/roles'))->toBeNull()
        ->and($store->state('abc:guild=1'))->toBeNull()
        ->and($store->globalResetAt())->toBeNull();
});

it('shares buckets between two stores on the same cache', function (): void {
    $cache = Cache::store('array');
    $first = new CacheBucketStore($cache);
    $second = new CacheBucketStore($cache);
    $resetAt = microtime(true) + 30;

    $first->rememberBucketForRoute('GET /guilds/{guild}/roles', 'abc');
    $first->putState('abc:guild=1', new BucketState(5, 0, $resetAt));
    $first->putGlobalResetAt($resetAt);

    expect($second->bucketForRoute('GET /guilds/{guild}/roles'))->toBe('abc')
        ->and($second->state('abc:guild=1'))->toEqual(new BucketState(5, 0, $resetAt))
        ->and($second->globalResetAt())->toBe($resetAt);
});

it('keeps stores with different prefixes apart', function (): void {
    $cache = Cache::store('array');

    (new CacheBucketStore($cache, 'app-a:'))->rememberBucketForRoute('GET /x', 'abc');

    expect((new CacheBucketStore($cache, 'app-b:'))->bucketForRoute('GET /x'))->toBeNull();
});

it('ignores a cache entry that is not a bucket state', function (): void {
    $cache = Cache::store('array');
    $cache->put('discord-server:rate-limit:bucket:abc:guild=1', 'garbage', 60);

    expect((new CacheBucketStore($cache))->state('abc:guild=1'))->toBeNull();
});

it('still stores a state whose reset time has already passed', function (): void {
    $store = new CacheBucketStore(Cache::store('array'));

    $store->putState('abc:guild=1', new BucketState(5, 0, microtime(true) - 10));

    expect($store->state('abc:guild=1')?->remaining)->toBe(0);
});

it('builds the real client with the cache store named in config', function (): void {
    config(['discord-server.bot_token' => 'test-token', 'discord-server.rate_limits.store' => 'array']);

    $client = app(ClientContract::class);

    expect($client)->toBeInstanceOf(Client::class);
});
