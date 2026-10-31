<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Security;

use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;

/**
 * The same "users" rows seen through another model (and another guard): the same id, another morph type.
 * A notification, run, download token or channel of NotifiableUser #1 must never be InboxAdmin #1's.
 */
class InboxAdmin extends NotifiableUser {}
