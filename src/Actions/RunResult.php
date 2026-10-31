<?php

namespace Maxcxam\Larsadmin\Actions;

use Throwable;

/**
 * The outcome of ActionRunner::run(): the most severe response, the counts, the exception that stopped
 * the run (not reported yet) and whether it was cancelled between chunks. $total is null for a standalone run.
 *
 * @internal Not part of the public API.
 */
final readonly class RunResult
{
    public function __construct(
        public ?ActionResponse $response,
        public ?int $total,
        public int $ran = 0,
        public int $skipped = 0,
        public ?Throwable $error = null,
        public bool $cancelled = false,
    ) {}
}
