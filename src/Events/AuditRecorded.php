<?php

namespace Maxcxam\Larsadmin\Events;

use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Audit\AuditEntry;

/**
 * Dispatched after the commit of a change that wrote audit entries (only when it has listeners),
 * e.g. to forward them to a SIEM. The entries carry the attributes as written; the id is null for
 * entries inserted in a batch (correlate them by batch_id).
 */
class AuditRecorded
{
    /**
     * @param  Collection<int, AuditEntry>  $entries
     */
    public function __construct(public readonly Collection $entries) {}
}
