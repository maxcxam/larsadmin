<?php

namespace Maxcxam\Larsadmin\Audit;

use ArrayObject;
use BackedEnum;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Maxcxam\Larsadmin\Fields\Field;
use Stringable;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use UnitEnum;

/**
 * Normalization, masking and size limits of audit values (ADR-024).
 *
 * @internal Not part of the public API.
 */
final class AuditValues
{
    /**
     * The largest JSON size of an entry's changes; larger values are omitted.
     */
    public const int MAX_CHANGES_BYTES = 65536;

    /**
     * The stored form of a masked change.
     */
    public const array MASKED = ['masked' => true];

    private const int MAX_DEPTH = 16;

    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * The normalized (JSON-safe) value of a model attribute, after its cast.
     */
    public static function attribute(Model $model, string $attribute): mixed
    {
        $value = $model->getAttribute($attribute);

        if ($value instanceof DateTimeInterface && self::isDateCast($model, $attribute)) {
            return Carbon::instance($value)->format('Y-m-d');
        }

        return self::normalize($value);
    }

    /**
     * Reduce a PHP value to JSON scalars and arrays: enums to their value/name, dates to ISO-8601,
     * arrayables to arrays, Stringable to strings, other objects to "[object Class]".
     */
    public static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            // PHP 8.5 warns when NAN is cast to string, so name it explicitly.
            is_float($value) => is_finite($value) ? $value : (is_nan($value) ? 'NAN' : (string) $value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => Carbon::instance($value)->toIso8601String(),
            // The client name, never the server's temporary path (an UploadedFile is Stringable).
            $value instanceof UploadedFile => $value->getClientOriginalName(),
            $value instanceof Arrayable => self::normalizeArray($value->toArray(), $depth),
            $value instanceof ArrayObject => self::normalizeArray($value->getArrayCopy(), $depth),
            $value instanceof JsonSerializable => self::normalize($value->jsonSerialize(), $depth + 1),
            is_array($value) => self::normalizeArray($value, $depth),
            $value instanceof Stringable => (string) $value,
            is_object($value) => '[object '.$value::class.']',
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function normalizeArray(array $values, int $depth): array
    {
        return array_map(fn (mixed $value) => self::normalize($value, $depth + 1), $values);
    }

    /**
     * Whether the values of the field's attribute on the model must never be stored: Password and
     * sensitive() fields, attributes the model does not serialize ($hidden, or missing from a non-empty
     * $visible) and secret casts (encrypted, hashed).
     */
    public static function isMasked(Field $field, Model $model, string $attribute): bool
    {
        $visible = $model->getVisible();

        return $field->isSensitive()
            || in_array($attribute, $model->getHidden(), true)
            || ($visible !== [] && ! in_array($attribute, $visible, true))
            || self::isSecretCast($model, $attribute);
    }

    /**
     * Encrypted and hashed casts: "encrypted", "encrypted:*", "hashed", AsEncrypted* and any cast class
     * whose name mentions encryption (host or package casts; fail closed).
     */
    public static function isSecretCast(Model $model, string $attribute): bool
    {
        $cast = $model->getCasts()[$attribute] ?? null;

        if (! is_string($cast)) {
            return false;
        }

        $class = explode(':', $cast, 2)[0];

        return in_array($class, ['encrypted', 'hashed'], true)
            || in_array($class, [AsEncryptedArrayObject::class, AsEncryptedCollection::class], true)
            || str_contains(strtolower(class_basename($class)), 'encrypt');
    }

    private static function isDateCast(Model $model, string $attribute): bool
    {
        $cast = $model->getCasts()[$attribute] ?? null;

        return is_string($cast) && in_array(explode(':', $cast, 2)[0], ['date', 'immutable_date'], true);
    }

    /**
     * An in-memory fingerprint of a masked value, used only to detect a change (never stored).
     */
    public static function fingerprint(mixed $value): string
    {
        return hash('xxh128', serialize(self::normalize($value)));
    }

    /**
     * The longest stored string (config "larsadmin.audit.max_value_length", at least 100).
     */
    public static function maxLength(): int
    {
        return max(100, (int) config('larsadmin.audit.max_value_length', 1000));
    }

    /**
     * Cut a value to the maximum length; arrays are JSON-encoded first when they are too long.
     *
     * @return array{0: mixed, 1: bool} the value and whether it was truncated
     */
    public static function truncate(mixed $value): array
    {
        $max = self::maxLength();

        if (is_array($value)) {
            $json = self::json($value);

            return mb_strlen($json) > $max ? [mb_substr($json, 0, $max), true] : [$value, false];
        }

        if (is_string($value) && mb_strlen($value) > $max) {
            return [mb_substr($value, 0, $max), true];
        }

        return [$value, false];
    }

    /**
     * Apply the value length limit to every change, then the overall size limit: the largest values
     * are replaced by null (marked "omitted") until the JSON fits MAX_CHANGES_BYTES.
     *
     * @param  array<string, array<string, mixed>>  $changes
     * @return array{0: array<string, array<string, mixed>>, 1: bool} the changes and whether values were omitted
     */
    public static function limit(array $changes): array
    {
        foreach ($changes as $attribute => $change) {
            $truncated = [];

            foreach (['old', 'new'] as $side) {
                if (! array_key_exists($side, $change)) {
                    continue;
                }

                [$changes[$attribute][$side], $cut] = self::truncate($change[$side]);

                if ($cut) {
                    $truncated[] = $side;
                }
            }

            if ($truncated !== []) {
                $changes[$attribute]['truncated'] = $truncated;
            }
        }

        $omitted = false;

        while (strlen(self::json($changes)) > self::MAX_CHANGES_BYTES) {
            $largest = null;
            $size = 0;

            foreach ($changes as $attribute => $change) {
                foreach (['old', 'new'] as $side) {
                    if (($change[$side] ?? null) !== null && ($length = strlen(self::json($change[$side]))) > $size) {
                        [$largest, $size] = [[$attribute, $side], $length];
                    }
                }
            }

            if ($largest === null) {
                break;
            }

            $changes[$largest[0]][$largest[1]] = null;
            $changes[$largest[0]]['omitted'] = true;
            $omitted = true;
        }

        return [$changes, $omitted];
    }

    /**
     * The JSON stored for a value.
     */
    public static function json(mixed $value): string
    {
        return (string) json_encode($value, self::JSON_FLAGS);
    }
}
