<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftAuditPost extends AuditPost
{
    use SoftDeletes;
}
