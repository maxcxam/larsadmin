<?php

namespace Maxcxam\Larsadmin\Mcp\Introspection;

use Closure;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * The allow-list serialization helpers of MCP introspection (ADR-038): class names, files relative to
 * base_path(), rule summaries, closure flags and the synthetic request. Nothing here runs a closure or
 * returns a value it did not build from an allow-listed source.
 *
 * @internal
 */
final class Metadata
{
    /**
     * The largest JSON response a tool returns (design §6); longer lists are cut with "truncated": true.
     */
    public const int MAX_LENGTH = 50000;

    /**
     * Rules whose parameters are formats, not data: shown when every parameter looks like a format.
     *
     * @var list<string>
     */
    public const array FORMAT_RULES = ['date_format', 'mimes', 'mimetypes', 'extensions'];

    /**
     * Rules whose numeric parameters are sizes or bounds, not data: shown when every parameter is numeric. Any
     * other rule (in, not_in, exists, unique, required_if...) is reported by name only, even with numeric
     * parameters, which may be record ids (design §6.4).
     *
     * @var list<string>
     */
    public const array SIZE_RULES = [
        'min', 'max', 'size', 'between', 'digits', 'digits_between', 'decimal', 'max_digits', 'min_digits',
        'multiple_of', 'gt', 'gte', 'lt', 'lte',
    ];

    /**
     * The synthetic request introspection builds prototypes with: the panel path, no user, no session.
     */
    public static function request(): Request
    {
        $request = Request::create(Larsadmin::path());
        $request->setUserResolver(fn () => null);

        return $request;
    }

    /**
     * The class name of an object or class string; anonymous classes are reported without their file path.
     */
    public static function className(object|string $class): string
    {
        $name = is_object($class) ? $class::class : $class;

        return str_contains($name, '@anonymous') ? 'class@anonymous' : $name;
    }

    /**
     * The file declaring the class, relative to base_path() ("vendor/..." for packages outside it); null when
     * unknown or outside both, so no absolute path of the machine is returned.
     */
    public static function classFile(object|string $class): ?string
    {
        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        return $file === false ? null : self::relativePath($file);
    }

    /**
     * A path relative to base_path() with forward slashes, the "vendor/..." part of a path in a vendor
     * directory, or null.
     */
    public static function relativePath(string $path): ?string
    {
        $real = realpath($path);

        if ($real === false) {
            return null;
        }

        $real = str_replace('\\', '/', $real);
        $base = realpath(base_path());

        if ($base !== false) {
            $base = rtrim(str_replace('\\', '/', $base), '/').'/';

            $inside = PHP_OS_FAMILY === 'Windows'
                ? strncasecmp($real, $base, strlen($base)) === 0
                : str_starts_with($real, $base);

            if ($inside) {
                return substr($real, strlen($base));
            }
        }

        $vendor = strrpos($real, '/vendor/');

        return $vendor === false ? null : substr($real, $vendor + 1);
    }

    /**
     * Why a host method could not be introspected: the exception class only, never its message (it may hold
     * SQL with values or record data).
     */
    public static function reason(Throwable $e): string
    {
        return self::className($e).' without an authenticated user';
    }

    /**
     * Read a declared (possibly protected or private) property without running anything.
     */
    public static function property(object $object, string $name, ?string $declaringClass = null): mixed
    {
        try {
            $property = new ReflectionProperty($declaringClass ?? $object, $name);

            return $property->isInitialized($object) ? $property->getValue($object) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The class declaring a method of the object or class, or null when it has no such method.
     */
    public static function declaringClass(object|string $object, string $method): ?string
    {
        try {
            return (new ReflectionMethod($object, $method))->getDeclaringClass()->getName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "overridden" when the method is declared below the base class, else "inherited".
     */
    public static function overridden(object|string $object, string $method, string $base): string
    {
        $declaring = self::declaringClass($object, $method);

        return $declaring !== null && $declaring !== $base ? 'overridden' : 'inherited';
    }

    /**
     * How an ability callback is configured: "closure" when one is set, "overridden" when the check method is
     * redeclared, else "default". The callback is never called.
     */
    public static function callbackFlag(?Closure $callback, object $object, string $method, string $base): string
    {
        if ($callback !== null) {
            return 'closure';
        }

        return self::overridden($object, $method, $base) === 'overridden' ? 'overridden' : 'default';
    }

    /**
     * A summary of validation rules: rule names, parameters only when numeric or formats, rule objects as their
     * class, closures as "closure".
     *
     * @param  iterable<mixed>  $rules
     * @return list<string>
     */
    public static function rules(iterable $rules): array
    {
        $summary = [];

        foreach ($rules as $rule) {
            $summary[] = self::rule($rule);
        }

        return $summary;
    }

    /**
     * The summary of one rule.
     */
    public static function rule(mixed $rule): string
    {
        if ($rule instanceof Closure) {
            return 'closure';
        }

        if (is_object($rule)) {
            return self::className($rule);
        }

        if (! is_string($rule)) {
            return get_debug_type($rule);
        }

        [$name, $parameters] = array_pad(explode(':', trim($rule), 2), 2, null);

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $name) !== 1) {
            return 'custom';
        }

        if ($parameters === null || $parameters === '') {
            return $name;
        }

        $list = explode(',', $parameters);

        $numeric = array_filter($list, fn (string $value) => preg_match('/^-?\d+(\.\d+)?\z/', trim($value)) === 1);

        if (in_array(strtolower($name), self::SIZE_RULES, true) && count($numeric) === count($list)) {
            return $name.':'.$parameters;
        }

        if (in_array(strtolower($name), self::FORMAT_RULES, true)) {
            $formats = array_filter($list, fn (string $value) => preg_match('#^[A-Za-z0-9.+/: -]{1,32}\z#', $value) === 1);

            if (count($formats) === count($list)) {
                return $name.':'.$parameters;
            }
        }

        return $name;
    }

    /**
     * Keep the payload under MAX_LENGTH by dropping items from the end of the given lists (in order), marking
     * the payload "truncated".
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $lists  keys of list values, the first shrunk first
     * @return array<string, mixed>
     */
    public static function fit(array $payload, array $lists): array
    {
        $payload['truncated'] = false;

        foreach ($lists as $key) {
            while (self::length($payload) > self::MAX_LENGTH && is_array($payload[$key] ?? null) && $payload[$key] !== []) {
                array_pop($payload[$key]);
                $payload['truncated'] = true;
            }
        }

        return $payload;
    }

    private static function length(array $payload): int
    {
        return strlen((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
