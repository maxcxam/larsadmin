<?php

namespace Maxcxam\Larsadmin\Layout;

use Illuminate\Support\Collection;
use LogicException;
use Maxcxam\Larsadmin\Fields\Field;

/**
 * Splits the output of Entity::fields() into the flat field list every server path
 * works with and an attribute-keyed layout tree used for presentation only.
 *
 * @internal Not part of the public API.
 */
final class FieldLayout
{
    /**
     * @param  array<int, mixed>  $items  Field|Panel|Tabs
     * @return array{0: list<Field>, 1: list<array<string, mixed>>}
     *
     * @throws LogicException on nested blocks, relation fields in a panel, duplicate attributes or unknown items
     */
    public static function flatten(array $items): array
    {
        $fields = [];
        $tree = [];
        $seen = [];
        $loose = null;

        $collect = function (Field $field) use (&$fields, &$seen): string {
            if (isset($seen[$field->attribute])) {
                throw new LogicException("The attribute [{$field->attribute}] is defined more than once in fields().");
            }

            $seen[$field->attribute] = true;
            $fields[] = $field;

            return $field->attribute;
        };

        $panel = function (Panel $panel, string $key) use ($collect): array {
            $attributes = [];

            foreach ($panel->fields() as $field) {
                if (! $field instanceof Field) {
                    throw new LogicException("The panel [{$panel->name}] may contain fields only, [".get_debug_type($field).'] given.');
                }

                if ($field->isRelation()) {
                    throw new LogicException("The relation field [{$field->attribute}] cannot be placed in the panel [{$panel->name}].");
                }

                $attributes[] = $collect($field);
            }

            return $panel->toNode($key, $attributes);
        };

        foreach ($items as $item) {
            if ($item instanceof Field) {
                if ($loose === null) {
                    $loose = count($tree);
                    $tree[] = [
                        'type' => 'panel', 'key' => 'p'.$loose, 'name' => null, 'description' => null,
                        'collapsible' => false, 'collapsed' => false, 'columns' => 1, 'fields' => [],
                    ];
                }

                $tree[$loose]['fields'][] = $collect($item);

                continue;
            }

            $loose = null;
            $index = count($tree);

            if ($item instanceof Panel) {
                $tree[] = $panel($item, 'p'.$index);
            } elseif ($item instanceof Tabs) {
                $tabs = [];

                foreach ($item->panels() as $i => $tab) {
                    $tabs[] = $panel($tab, "t{$index}-{$i}");
                }

                $tree[] = ['type' => 'tabs', 'key' => 't'.$index, 'name' => $item->name, 'tabs' => $tabs];
            } else {
                throw new LogicException('fields() may return Field, Panel and Tabs instances only, ['.get_debug_type($item).'] given.');
            }
        }

        return [$fields, $tree];
    }

    /**
     * Keep only the attributes present in the resolved fields (relation panels excluded)
     * and drop panels and tabs left empty.
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @param  Collection<int, Field>  $fields
     * @return list<array<string, mixed>>
     */
    public static function serialize(array $tree, Collection $fields): array
    {
        $present = $fields->reject(fn (Field $field) => $field->isRelation())
            ->mapWithKeys(fn (Field $field) => [$field->attribute => true])
            ->all();

        $filter = function (array $panel) use ($present): array {
            $panel['fields'] = array_values(array_filter($panel['fields'], fn (string $attribute) => isset($present[$attribute])));

            return $panel;
        };

        $result = [];

        foreach ($tree as $node) {
            if ($node['type'] === 'tabs') {
                $node['tabs'] = array_values(array_filter(
                    array_map($filter, $node['tabs']),
                    fn (array $tab) => $tab['fields'] !== [],
                ));

                if ($node['tabs'] !== []) {
                    $result[] = $node;
                }

                continue;
            }

            $node = $filter($node);

            if ($node['fields'] !== []) {
                $result[] = $node;
            }
        }

        return $result;
    }
}
