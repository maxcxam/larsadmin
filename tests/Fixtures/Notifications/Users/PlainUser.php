<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users;

use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * A panel user without the Notifiable trait: no bell, run notifications are skipped.
 */
class PlainUser extends User {}
