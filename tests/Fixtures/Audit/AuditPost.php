<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Maxcxam\Larsadmin\Tests\Fixtures\Status;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

class AuditPost extends Model
{
    protected $table = 'audit_posts';

    protected $guarded = [];

    protected $hidden = ['token'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'published' => 'boolean',
            'published_at' => 'datetime',
            'published_on' => 'date',
            'attachments' => 'array',
            'private' => 'encrypted',
        ];
    }
}
