<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Files;

use Illuminate\Database\Eloquent\Model;

/**
 * The documents table without casts: multiple() columns are read and written as JSON strings.
 */
class RawDocument extends Model
{
    protected $table = 'documents';

    protected $guarded = [];
}
