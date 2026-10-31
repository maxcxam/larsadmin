<?php

namespace Maxcxam\Larsadmin\Events;

use Maxcxam\Larsadmin\Actions\Queued\ActionRun;

/**
 * Dispatched when a queued action run reached a final status (completed, failed or cancelled), after
 * the run was saved. The run's payload is already cleared.
 */
class ActionRunFinished
{
    public function __construct(public readonly ActionRun $run) {}
}
