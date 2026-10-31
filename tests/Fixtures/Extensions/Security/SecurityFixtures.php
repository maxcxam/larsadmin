<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Extensions\Security;

/**
 * The paths of the T1 (1.5) security fixture extension: dist/ holds an entry script plus files that must never
 * be served (HTML, PHP, env, dotfiles, a hidden directory, a source map...); outside/ is a traversal target
 * beside dist/; lang/ holds translations crafted to break out of the page.
 */
final class SecurityFixtures
{
    public const string ROOT = __DIR__;

    public const string DIST = __DIR__.'/dist';

    public const string LANG = __DIR__.'/lang';
}
