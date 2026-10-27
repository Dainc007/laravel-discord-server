<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One change made, or tried, on a Discord server through this package.
 *
 * @property int $id
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string $guild_id
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $target_name
 * @property array<string, mixed>|null $details
 * @property bool $succeeded
 * @property int|null $http_status
 * @property int|null $discord_code
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DiscordChange extends Model
{
    use MassPrunable;

    protected $guarded = ['id'];

    /**
     * Entries older than change_log.keep_days; none when it is null.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('discord-server.change_log.keep_days');

        if (! is_int($days) || $days < 1) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<', now()->subDays($days));
    }

    public function getTable(): string
    {
        $table = config('discord-server.change_log.table', 'discord_changes');

        return is_string($table) && $table !== '' ? $table : 'discord_changes';
    }

    /**
     * Who made the change: any model, or null for a scheduled job or the console.
     *
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'succeeded' => 'boolean',
            'http_status' => 'integer',
            'discord_code' => 'integer',
        ];
    }
}
