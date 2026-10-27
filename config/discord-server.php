<?php

declare(strict_types=1);

return [

    /*
     * The bot token from the Discord developer portal (Bot → Reset Token).
     */
    'bot_token' => env('DISCORD_BOT_TOKEN', ''),

    /*
     * The server every change goes to, unless a call names another one with forGuild().
     */
    'guild_id' => env('DISCORD_GUILD_ID', ''),

    /*
     * Listing every member needs the "Server Members Intent" switched on in the
     * developer portal. Leave this off until it is.
     */
    'members_intent' => (bool) env('DISCORD_MEMBERS_INTENT', false),

    'http' => [
        'timeout' => 10,
        'connect_timeout' => 5,
    ],

    /*
     * How often one call is retried after a 429, a 5xx on a read or a network
     * error on a read, and the longest rate-limit wait accepted before giving up.
     */
    'max_retries' => 3,
    'max_retry_after_seconds' => 60.0,

    /*
     * Rate-limit state lives in this cache store, so queue workers and web
     * requests share it. Null uses the default store; a store that only lives in
     * memory ("array") keeps it per process.
     */
    'rate_limits' => [
        'store' => env('DISCORD_RATE_LIMIT_STORE'),
        'prefix' => 'discord-server:rate-limit:',
    ],

    /*
     * Shown at the start of the reason in Discord's audit log, before the actor
     * and the reason: "My App: Anna: linked account".
     */
    'audit_reason_prefix' => env('APP_NAME', 'Laravel'),

    'change_log' => [
        'enabled' => true,
        'table' => 'discord_changes',

        /*
         * `php artisan model:prune` deletes entries older than this many days.
         * Null keeps them forever.
         */
        'keep_days' => 90,
    ],

];
