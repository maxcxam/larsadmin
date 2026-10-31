<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users;

use Illuminate\Notifications\Notifiable;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * A panel user that can receive notifications (the standard Notifiable trait).
 */
class NotifiableUser extends User
{
    use Notifiable;
}
