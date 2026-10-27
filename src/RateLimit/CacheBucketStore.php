<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\RateLimit;

use Dainc007\DiscordRestClient\Contracts\BucketStore;
use Dainc007\DiscordRestClient\RateLimit\BucketState;
use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps Discord's rate-limit buckets in a Laravel cache store, so every
 * process using that store waits for the same buckets.
 */
final readonly class CacheBucketStore implements BucketStore
{
    /** Discord rarely changes which bucket a route belongs to; a day is plenty. */
    private const int ROUTE_BUCKET_TTL_SECONDS = 86_400;

    public function __construct(
        private Repository $cache,
        private string $prefix = 'discord-server:rate-limit:',
    ) {}

    public function bucketForRoute(string $routeKey): ?string
    {
        $bucket = $this->cache->get($this->prefix.'route:'.$routeKey);

        return is_string($bucket) ? $bucket : null;
    }

    public function rememberBucketForRoute(string $routeKey, string $bucketHash): void
    {
        $this->cache->put($this->prefix.'route:'.$routeKey, $bucketHash, self::ROUTE_BUCKET_TTL_SECONDS);
    }

    public function state(string $bucketKey): ?BucketState
    {
        $state = $this->cache->get($this->prefix.'bucket:'.$bucketKey);

        if (! is_array($state)) {
            return null;
        }

        $limit = $state['limit'] ?? null;
        $remaining = $state['remaining'] ?? null;
        $resetAt = $state['reset_at'] ?? null;

        if (! is_int($limit) || ! is_int($remaining) || ! is_int($resetAt) && ! is_float($resetAt)) {
            return null;
        }

        return new BucketState($limit, $remaining, (float) $resetAt);
    }

    public function putState(string $bucketKey, BucketState $state): void
    {
        $this->cache->put(
            $this->prefix.'bucket:'.$bucketKey,
            ['limit' => $state->limit, 'remaining' => $state->remaining, 'reset_at' => $state->resetAt],
            $this->secondsUntil($state->resetAt),
        );
    }

    public function globalResetAt(): ?float
    {
        $resetAt = $this->cache->get($this->prefix.'global');

        return is_numeric($resetAt) ? (float) $resetAt : null;
    }

    public function putGlobalResetAt(float $resetAt): void
    {
        $this->cache->put($this->prefix.'global', $resetAt, $this->secondsUntil($resetAt));
    }

    /**
     * Keep an entry a little past its reset time; after that it says nothing.
     */
    private function secondsUntil(float $timestamp): int
    {
        return max(1, (int) ceil($timestamp - microtime(true)) + 1);
    }
}
