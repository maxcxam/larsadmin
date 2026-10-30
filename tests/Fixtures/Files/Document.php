<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Files;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['attachments' => 'array'];
    }
}
