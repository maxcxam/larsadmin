<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

use Illuminate\Database\Eloquent\Model;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use RuntimeException;

/**
 * viewAny follows Probe::$viewAny; records titled "Private ..." may not be viewed and
 * "Boom ..." makes the view check crash with a message that must never reach the client.
 */
class RecordPolicy
{
    public function viewAny(User $user): bool
    {
        return Probe::$viewAny;
    }

    public function view(User $user, Model $record): bool
    {
        if (str_starts_with((string) $record->title, 'Boom')) {
            throw new RuntimeException('policy secret: SELECT * FROM salaries');
        }

        return ! str_starts_with((string) $record->title, 'Private');
    }
}
