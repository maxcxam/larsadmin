<?php

namespace Maxcxam\Larsadmin\Console;

use InvalidArgumentException;
use Maxcxam\Larsadmin\Scaffolding\Scaffolder;

/**
 * The path and name rules of the 1.5 extension scaffolds, kept as static helpers for the console commands. Since
 * 1.6 they live in Scaffolding\Scaffolder (ADR-039), which every generator uses.
 *
 * @internal
 */
final class ExtensionScaffold
{
    public const string STUBS = Scaffolder::STUBS;

    /**
     * A PHP namespace: identifiers separated by single backslashes.
     */
    public const string NAMESPACE_PATTERN = Scaffolder::NAMESPACE_PATTERN;

    /**
     * @throws InvalidArgumentException
     */
    public static function relativeDirectory(string $path, string $option): string
    {
        return Scaffolder::relativeDirectory($path, $option);
    }

    public static function insideBase(string $path): bool
    {
        return Scaffolder::insideBase($path);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function className(string $name): string
    {
        return Scaffolder::className($name);
    }

    public static function keySegment(string $value): string
    {
        return Scaffolder::keySegment($value);
    }

    public static function hostKitPath(string $relativeDirectory): string
    {
        return Scaffolder::hostKitPath($relativeDirectory);
    }
}
