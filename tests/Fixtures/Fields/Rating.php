<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Field;

/**
 * An extension field built on the stable Field hooks: 0..max stars stored as an integer. It records the
 * hooks it runs (contract tests) and can return a deferred closure from fill() (ADR-002).
 */
class Rating extends Field
{
    public string $component = 'acme/rating';

    public int $max = 5;

    /** @var list<string> */
    public static array $calls = [];

    protected ?Closure $afterSave = null;

    public function max(int $max): static
    {
        $this->max = $max;

        return $this;
    }

    /**
     * Run the callback with the record after it was saved.
     */
    public function afterSave(Closure $callback): static
    {
        $this->afterSave = $callback;

        return $this;
    }

    public function fill(Request $request, Model $record): ?Closure
    {
        static::$calls[] = 'fill';

        parent::fill($request, $record);

        return $this->afterSave;
    }

    protected function resolveValue(mixed $value): mixed
    {
        static::$calls[] = 'resolveValue';

        return $value === null ? null : (int) $value;
    }

    protected function formatForDisplay(mixed $value): mixed
    {
        static::$calls[] = 'formatForDisplay';

        return $value === null ? null : str_repeat('*', (int) $value);
    }

    protected function fillValue(Request $request): mixed
    {
        static::$calls[] = 'fillValue';

        $value = $request->input($this->attribute);

        return $value === null ? null : (int) $value;
    }

    protected function typeRules(): array
    {
        return ['integer', 'min:0', 'max:'.$this->max];
    }

    protected function defaultAlign(): string
    {
        return 'center';
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'max' => $this->max];
    }
}
