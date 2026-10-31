<?php

namespace Maxcxam\Larsadmin\Mcp\Introspection;

use Closure;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\PanelField;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Rules\RelatableExists;
use Throwable;

/**
 * The allow-listed metadata of an unresolved field (ADR-038): never its value, default, placeholder, help,
 * meta or option values, and no closure (visibility, canSee, readonly, options, default, display...) is run.
 *
 * @internal
 */
final class FieldMetadata
{
    /**
     * The core classes whose implicit rules are pure (no options, no host code): implicitRules() is only
     * computed when typeRules(), implicitRules() and acceptsNullInput() are all declared by these.
     *
     * @var list<class-string<Field>>
     */
    private const array PURE_RULE_CLASSES = [
        Field::class, Text::class, Textarea::class, Number::class, Boolean::class, Date::class,
        Password::class, File::class,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function describe(Field $field): array
    {
        $component = Metadata::property($field, 'component', Field::class);
        $component = is_string($component) ? $component : null;

        return [
            'attribute' => $field->attribute,
            'name' => $field->name,
            'type' => Metadata::className($field),
            'component' => $component,
            'contexts' => self::contexts($field),
            'readonly' => Metadata::property($field, 'readonlyCallback', Field::class) instanceof Closure
                ? 'closure'
                : $field->readonly,
            'canSee' => Metadata::property($field, 'canSeeCallback', Field::class) instanceof Closure,
            'sortable' => $field->sortable,
            'nullable' => $field->nullable,
            'sensitive' => self::sensitive($field),
            // Whether the field uploads files: File and Image always do (without setting $multipart).
            'multipart' => $field instanceof File || $field->multipart,
            ...self::validation($field),
            'relation' => self::relation($field),
            'options' => self::options($field),
            'extension' => $component !== null && str_contains($component, '/') ? $component : null,
        ];
    }

    /**
     * Whether each context shows the field: true/false from the flags, "closure" for a visibility callback.
     *
     * @return array<string, bool|string>
     */
    public static function contexts(Field $field): array
    {
        $callbacks = Metadata::property($field, 'visibilityCallbacks', Field::class);
        $callbacks = is_array($callbacks) ? $callbacks : [];

        $flags = [
            Field::INDEX => $field->showOnIndex,
            Field::DETAIL => $field->showOnDetail,
            Field::CREATE => $field->showOnCreation,
            Field::UPDATE => $field->showOnUpdate,
        ];

        $contexts = [];

        foreach ($flags as $context => $flag) {
            $contexts[$context] = isset($callbacks[$context]) ? 'closure' : $flag;

            // Relation panels show on the detail page only.
            if ($field instanceof PanelField && $context !== Field::DETAIL) {
                $contexts[$context] = false;
            }
        }

        return $contexts;
    }

    private static function sensitive(Field $field): bool
    {
        try {
            return $field->isSensitive();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The validation of the field, keys "rules", "rulesComputed" and "declaredRules":
     * - declaredRules: the summarized rules() plus creationRules() / updateRules() per form context;
     * - rules: the complete rules the panel validates with (implicit type rules first, then the declared ones), only
     *   when they can be computed without host code; null otherwise (rulesComputed false): an extension field's or a
     *   Select's type rules, or an overridden rulesFor(), are not run (Select builds "in" from its options).
     * Relation panels are never validated: all three are null / false.
     *
     * @return array{rules: array{create: list<string>, update: list<string>}|null, rulesComputed: bool, declaredRules: array{create: list<string>, update: list<string>}|null}
     */
    private static function validation(Field $field): array
    {
        if ($field instanceof PanelField) {
            return ['rules' => null, 'rulesComputed' => false, 'declaredRules' => null];
        }

        $declared = [];

        foreach ([Field::CREATE => 'creationRules', Field::UPDATE => 'updateRules'] as $context => $property) {
            $rules = [];

            foreach (['rules', $property] as $name) {
                $list = Metadata::property($field, $name, Field::class);
                $rules = [...$rules, ...(is_array($list) ? array_values($list) : [])];
            }

            $declared[$context] = $rules;
        }

        $implicit = self::implicitRules($field);
        $summary = fn (array $rules) => Metadata::rules($rules);

        if ($implicit === null) {
            return [
                'rules' => null,
                'rulesComputed' => false,
                'declaredRules' => array_map($summary, $declared),
            ];
        }

        $complete = [];

        foreach ($declared as $context => $rules) {
            $complete[$context] = $summary([...$implicit, ...$rules]);

            // BelongsTo::rulesFor() adds these around the declared rules.
            if ($field instanceof BelongsTo) {
                $complete[$context] = [$field->nullable ? 'nullable' : 'required', ...$complete[$context], RelatableExists::class];
            }
        }

        return [
            'rules' => $complete,
            'rulesComputed' => true,
            'declaredRules' => array_map($summary, $declared),
        ];
    }

    /**
     * The implicit type rules of a core field type, or null when they cannot be computed without host code (a
     * typeRules() / implicitRules() / acceptsNullInput() / rulesFor() declared outside the core classes, or
     * Select's options-based "in" rule) or computing them failed.
     *
     * @return list<mixed>|null
     */
    private static function implicitRules(Field $field): ?array
    {
        foreach (['typeRules', 'implicitRules', 'acceptsNullInput'] as $method) {
            if (! in_array(Metadata::declaringClass($field, $method), self::PURE_RULE_CLASSES, true)) {
                return null;
            }
        }

        // rulesFor() adds rules of its own (File: sizes and counts); BelongsTo's additions are known.
        if (! in_array(Metadata::declaringClass($field, 'rulesFor'), [Field::class, BelongsTo::class], true)) {
            return null;
        }

        try {
            $rules = (fn () => $this->implicitRules())->call($field);
        } catch (Throwable) {
            return null;
        }

        return is_array($rules) ? array_values($rules) : null;
    }

    /**
     * The relation target as names: the relation type, the relationship method and the related entity.
     *
     * @return array{type: string, relationship: string, entity: string|null}|null
     */
    private static function relation(Field $field): ?array
    {
        if (! $field instanceof BelongsTo && ! $field instanceof PanelField) {
            return null;
        }

        return [
            'type' => lcfirst(class_basename($field)),
            'relationship' => $field->attribute,
            'entity' => self::relatedEntity($field),
        ];
    }

    /**
     * The related entity class: the one passed to the field, else the registered entity of the relation's
     * model (the relation is built on an empty model, no query runs).
     */
    private static function relatedEntity(BelongsTo|PanelField $field): ?string
    {
        $explicit = Metadata::property($field, 'relatedEntity', $field instanceof BelongsTo ? BelongsTo::class : PanelField::class);

        if (is_string($explicit)) {
            return $explicit;
        }

        try {
            return Larsadmin::entityForModel($field->relation()->getRelated()::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * How a Select's options are given: their count for an array, "closure" without running it.
     *
     * @return array{source: string, count: int|null}|null
     */
    private static function options(Field $field): ?array
    {
        if (! $field instanceof Select) {
            return null;
        }

        $options = Metadata::property($field, 'options', Select::class);

        return match (true) {
            is_array($options) => ['source' => 'array', 'count' => count($options)],
            $options instanceof Closure => ['source' => 'closure', 'count' => null],
            default => ['source' => 'unknown', 'count' => null],
        };
    }
}
