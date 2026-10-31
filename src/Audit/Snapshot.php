<?php

namespace Maxcxam\Larsadmin\Audit;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Files\StoredFile;

/**
 * The normalized values of a record's field attributes at one moment, held in memory only.
 * Masked values are kept as a fingerprint, never as the value (ADR-024).
 *
 * @internal Not part of the public API.
 */
final class Snapshot
{
    /**
     * @param  array<string, array{masked: bool, value: mixed, fingerprint: string|null, empty: bool}>  $entries
     */
    private function __construct(private readonly array $entries) {}

    /**
     * The snapshot of a record that does not exist (before a create, after a delete).
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The field attributes of the entity's record. No query runs: BelongsTo fields read the foreign key.
     */
    public static function capture(Entity $instance, Request $request): self
    {
        return self::of($instance->auditedFields($request), $instance->record);
    }

    /**
     * The given fields' attributes of the record.
     *
     * @param  Collection<array-key, Field>  $fields
     */
    public static function of(Collection $fields, Model $record): self
    {
        $attributes = $record->getAttributes();
        $original = $record->getRawOriginal();
        $entries = [];

        foreach ($fields as $field) {
            $source = $field instanceof File ? $field->attribute : $field->fillAttribute();

            // Fields without a column (extension fields, ADR-035) describe their state themselves.
            if ($field->hasAuditValue()) {
                $masked = AuditValues::isMasked($field, $record, $source);
                $value = $field->auditValue($record);

                $entries[$field->attribute] = [
                    'masked' => $masked,
                    'value' => $masked ? null : $value,
                    'fingerprint' => $masked ? AuditValues::fingerprint($value) : null,
                    'empty' => $value === null || $value === [],
                ];

                continue;
            }

            // Computed fields without a column are not recorded.
            if (! array_key_exists($source, $attributes) && ! array_key_exists($source, $original)) {
                continue;
            }

            $masked = AuditValues::isMasked($field, $record, $source);
            $value = match (true) {
                $field instanceof File => array_map(fn (StoredFile $file) => ['name' => $file->name, 'path' => $file->path], $field->filesOf($record)),
                $masked => self::maskedValue($record, $source),
                $field instanceof BelongsTo => self::foreignKey($field, AuditValues::attribute($record, $source)),
                default => AuditValues::attribute($record, $source),
            };

            $entries[$field->attribute] = [
                'masked' => $masked,
                'value' => $masked ? null : $value,
                'fingerprint' => $masked ? AuditValues::fingerprint($value) : null,
                'empty' => $value === null || $value === [],
            ];
        }

        return new self($entries);
    }

    /**
     * The value of a masked attribute, only to fingerprint it. A value that cannot be decrypted
     * (rotated key) is fingerprinted raw instead of breaking the save.
     */
    private static function maskedValue(Model $record, string $source): mixed
    {
        try {
            return AuditValues::attribute($record, $source);
        } catch (DecryptException) {
            return $record->getAttributes()[$source] ?? null;
        }
    }

    /**
     * A foreign key as int for integer-keyed related models (forms post strings), else as string.
     */
    private static function foreignKey(BelongsTo $field, mixed $value): mixed
    {
        if (! is_int($value) && ! is_string($value)) {
            return $value;
        }

        $related = $field->relation()->getRelated();
        $integer = $related->getIncrementing() && in_array($related->getKeyType(), ['int', 'integer'], true);

        if ($integer && is_string($value) && ctype_digit($value) && strlen($value) <= 18) {
            return (int) $value;
        }

        return $integer ? $value : (string) $value;
    }

    /**
     * The changes from this snapshot to the later one, keyed by field attribute:
     * {old, new} or {masked: true}. Empty values of a missing side are not changes. With $common only
     * attributes present in both snapshots are compared (a re-read record whose query loaded other
     * columns, e.g. withCount or a select, than the snapshot before).
     *
     * @return array<string, array<string, mixed>>
     */
    public function diff(self $after, bool $common = false): array
    {
        $changes = [];

        foreach (array_keys($after->entries + $this->entries) as $attribute) {
            $old = $this->entries[$attribute] ?? null;
            $new = $after->entries[$attribute] ?? null;

            if ($old === null || $new === null) {
                $present = $old ?? $new;

                if ($common || $present['empty']) {
                    continue;
                }

                $changes[$attribute] = $present['masked'] ? AuditValues::MASKED : ['old' => $old['value'] ?? null, 'new' => $new['value'] ?? null];

                continue;
            }

            if ($old['masked'] || $new['masked']) {
                if ($old['fingerprint'] !== $new['fingerprint'] || $old['masked'] !== $new['masked']) {
                    $changes[$attribute] = AuditValues::MASKED;
                }

                continue;
            }

            if (! self::equivalent($old['value'], $new['value'])) {
                $changes[$attribute] = ['old' => $old['value'], 'new' => $new['value']];
            }
        }

        return $changes;
    }

    /**
     * Strict equality, except as Eloquent's dirty check: a number and a numeric string are equal when
     * their string forms are ("5" posted for 5), and a bool equals 0/1/"0"/"1" (an uncast boolean
     * column). Two strings are always compared strictly ("007" vs "7" is a change).
     */
    private static function equivalent(mixed $old, mixed $new): bool
    {
        if ($old === $new) {
            return true;
        }

        if (is_bool($old) || is_bool($new)) {
            [$bool, $other] = is_bool($old) ? [$old, $new] : [$new, $old];

            return in_array($other, [0, 1, '0', '1'], true) && (bool) $other === $bool;
        }

        if (is_string($old) && is_string($new)) {
            return false;
        }

        return is_numeric($old) && is_numeric($new) && strcmp((string) $old, (string) $new) === 0;
    }
}
