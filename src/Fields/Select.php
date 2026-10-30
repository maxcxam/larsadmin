<?php

namespace Maxcxam\Larsadmin\Fields;

use BackedEnum;
use Closure;
use Illuminate\Validation\Rule;
use UnitEnum;

class Select extends Field
{
    public string $component = 'select-field';

    /**
     * @var array<array-key, string>|Closure
     */
    protected array|Closure $options = [];

    /**
     * The resolved options, memoized per field instance.
     *
     * @var array<int, array{value: array-key, label: string}>|null
     */
    protected ?array $resolvedOptions = null;

    /**
     * Set the available options as a [value => label] map, or a closure returning one.
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;
        $this->resolvedOptions = null;

        return $this;
    }

    /**
     * Get the options as a list of value / label pairs.
     *
     * @return array<int, array{value: array-key, label: string}>
     */
    public function resolvedOptions(): array
    {
        return $this->resolvedOptions ??= collect(
            $this->options instanceof Closure ? call_user_func($this->options) : $this->options
        )
            ->map(fn ($label, $value) => ['value' => $value, 'label' => (string) $label])
            ->values()
            ->all();
    }

    protected function resolveValue(mixed $value): mixed
    {
        return $this->scalar($value);
    }

    protected function formatForDisplay(mixed $value): mixed
    {
        $value = $this->scalar($value);

        if (! is_scalar($value)) {
            return $value;
        }

        foreach ($this->resolvedOptions() as $option) {
            if ((string) $option['value'] === (string) $value) {
                return $option['label'];
            }
        }

        return $value;
    }

    protected function typeRules(): array
    {
        return [Rule::in(array_column($this->resolvedOptions(), 'value'))];
    }

    /**
     * Reduce enums to their stored scalar: the value of a backed enum, the name of a unit enum.
     */
    protected function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            default => $value,
        };
    }

    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'options' => $this->resolvedOptions(),
        ]);
    }
}
