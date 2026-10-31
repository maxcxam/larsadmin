<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Extensions;

/**
 * The paths of the fixture extensions: "acme-demo" (built files with a Vite manifest, a stylesheet,
 * chunks, an SVG, a source map, a dotfile, a PHP file, and secret.php beside dist/ as a traversal target)
 * and "plain" (an entry script only).
 */
final class FixtureExtensions
{
    public const string DEMO = __DIR__.'/acme-demo';

    public const string PLAIN = __DIR__.'/plain';

    /**
     * A verbatim copy of the 1.4 root view: without extensions the page must render exactly like it.
     */
    public const string VIEWS_1_4 = __DIR__.'/views-1.4';
}
