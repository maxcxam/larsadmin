<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Field;

/**
 * An extension field built on the stable Field hooks whose value is a range {min, max}: fill() writes two
 * attributes ("<attribute>_min", "<attribute>_max"), so the 1.5 default (reading fillAttribute()) would lose
 * it, and actionValue() hands the action the whole range instead. $override replaces the action value (tests of
 * values that cannot be queued); $calls records whether the field knew it was in an action.
 */
class ActionValueField extends Field
{
    public string $component = 'acme/range';

    /** @var list<array{hook: string, inAction: bool}> */
    public static array $calls = [];

    /** @var (Closure(): mixed)|null replaces the computed action value */
    public static ?Closure $override = null;

    public static function reset(): void
    {
        static::$calls = [];
        static::$override = null;
    }

    public function fill(Request $request, Model $record): ?Closure
    {
        static::$calls[] = ['hook' => 'fill', 'inAction' => $this->inAction()];

        $range = $request->input($this->attribute);

        if (is_array($range)) {
            $record->{$this->attribute.'_min'} = isset($range['min']) ? (int) $range['min'] : null;
            $record->{$this->attribute.'_max'} = isset($range['max']) ? (int) $range['max'] : null;
        }

        return null;
    }

    public function actionValue(Request $request): mixed
    {
        static::$calls[] = ['hook' => 'actionValue', 'inAction' => $this->inAction()];

        if (static::$override !== null) {
            return (static::$override)();
        }

        $range = $request->input($this->attribute);

        return is_array($range) ? [
            'min' => isset($range['min']) ? (int) $range['min'] : null,
            'max' => isset($range['max']) ? (int) $range['max'] : null,
        ] : null;
    }

    public function rulesFor(string $context, ?Model $record = null): array
    {
        return [
            $this->attribute => ['nullable', 'array:min,max'],
            $this->attribute.'.min' => ['nullable', 'integer'],
            $this->attribute.'.max' => ['nullable', 'integer', 'gte:'.$this->attribute.'.min'],
        ];
    }

    public function validationAttributeNames(): array
    {
        return [
            $this->attribute => $this->name,
            $this->attribute.'.min' => $this->name.' (min)',
            $this->attribute.'.max' => $this->name.' (max)',
        ];
    }
}
