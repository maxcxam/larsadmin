<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Secrets marked the other ways: a $visible allow-list, a hashed cast and a host encryption cast.
 * No boolean cast on "published" (an uncast column).
 */
class SecretCastPost extends Model
{
    protected $table = 'audit_posts';

    protected $guarded = [];

    protected $visible = ['id', 'title', 'status', 'body', 'published', 'note', 'private'];

    protected function casts(): array
    {
        return [
            'note' => 'hashed',
            'private' => EncryptedString::class,
        ];
    }
}
