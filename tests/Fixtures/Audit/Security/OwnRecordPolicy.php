<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit\Security;

use Illuminate\Database\Eloquent\Model;

/**
 * Users may list every record but view only their own (the record is the user, or its author_id is).
 */
class OwnRecordPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, Model $record): bool
    {
        return (string) $record->getKey() === (string) $user->getKey()
            || (string) $record->getAttribute('author_id') === (string) $user->getKey();
    }
}
