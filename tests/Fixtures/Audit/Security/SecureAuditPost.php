<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit\Security;

use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditPost;

/**
 * An audited post whose masking rules can change after entries were written: attributes can be
 * added to $hidden and encrypted casts at run time (read-time re-masking, ADR-024).
 */
class SecureAuditPost extends AuditPost
{
    /** @var list<string> */
    public static array $extraHidden = [];

    /** @var array<string, string> */
    public static array $extraCasts = [];

    public function getHidden(): array
    {
        return array_values(array_unique([...parent::getHidden(), ...static::$extraHidden]));
    }

    public function getCasts(): array
    {
        return [...parent::getCasts(), ...static::$extraCasts];
    }

    public static function reset(): void
    {
        static::$extraHidden = [];
        static::$extraCasts = [];
    }
}
