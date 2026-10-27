<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Tests;

use Dainc007\LaravelDiscordServer\DiscordServerServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $migration = require __DIR__.'/../database/migrations/create_discord_changes_table.php.stub';
        $migration->up();
    }

    protected function getPackageProviders($app): array
    {
        return [DiscordServerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('discord-server.audit_reason_prefix', 'My App');
    }
}
