<?php

declare(strict_types=1);

use Dainc007\LaravelDiscordServer\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function memberPayload(string $id = '900', array $roleIds = [], array $overrides = []): array
{
    return $overrides + [
        'user' => ['id' => $id, 'username' => 'anna', 'global_name' => 'Anna'],
        'nick' => null,
        'roles' => $roleIds,
        'joined_at' => '2026-01-01T00:00:00+00:00',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rolePayload(array $overrides = []): array
{
    return $overrides + [
        'id' => '111',
        'name' => 'Players',
        'color' => 0x3498DB,
        'hoist' => false,
        'position' => 3,
        'permissions' => '1024',
        'managed' => false,
        'mentionable' => false,
    ];
}
