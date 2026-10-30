<?php

namespace Maxcxam\Larsadmin\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class Detached
{
    public function __construct(
        public readonly Model $parent,
        public readonly string $relation,
        public readonly Model $related,
        public readonly ?Authenticatable $user,
    ) {}
}
