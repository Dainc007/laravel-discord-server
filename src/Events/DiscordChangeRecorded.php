<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer\Events;

use Dainc007\LaravelDiscordServer\Models\DiscordChange;

/**
 * A change was written to the change log: one that went through, or one
 * Discord refused ($change->succeeded tells which). Not fired when the change
 * log is switched off.
 */
final readonly class DiscordChangeRecorded
{
    public function __construct(
        public DiscordChange $change,
    ) {}
}
