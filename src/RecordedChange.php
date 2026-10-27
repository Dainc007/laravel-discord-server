<?php

declare(strict_types=1);

namespace Dainc007\LaravelDiscordServer;

use Dainc007\LaravelDiscordServer\Models\DiscordChange;

/**
 * What recordChange() did: the change's own result, and the change log row
 * written for it (null when the change log is switched off).
 *
 * @template TResult
 */
final readonly class RecordedChange
{
    /**
     * @param  TResult  $result
     */
    public function __construct(
        public mixed $result,
        public ?DiscordChange $log,
    ) {}
}
