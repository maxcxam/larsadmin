<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
