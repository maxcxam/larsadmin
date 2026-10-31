<?php

namespace Maxcxam\Larsadmin\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A query-string driven constraint on an entity's index (and panel) listing.
 *
 * State lives in `?filters[<key>]=<value>`. The base class behaves as a select
 * filter: the raw value must be one of the keys returned by options().
 */
abstract class Filter
{
    public const int MAX_VALUE_LENGTH = 255;

    /**
     * The frontend component: 'select-filter', 'boolean-filter' or 'date-range-filter'.
     */
    public string $component = 'select-filter';

    protected ?Closure $canSeeCallback = null;

    /** @var array<string, string>|null */
    private ?array $resolvedOptions = null;

    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }

    /**
     * The label shown in the UI (translated via __()). Derived from the class name (English)
     * when not overridden: set it explicitly for other locales, e.g. uk.
     */
    public function name(): string
    {
        return __(Str::title(Str::snake(class_basename(static::class), ' ')));
    }

    /**
     * The key used in the query string: filters[<key>].
     */
    public function key(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * Constrain the query to the (already normalized, non-null) value.
     */
    abstract public function apply(Request $request, Builder $query, mixed $value): Builder;

    /**
     * The selectable options as [value => label].
     *
     * @return array<string|int, string>
     */
    public function options(Request $request): array
    {
        return [];
    }

    /**
     * The value applied when the query string does not mention this filter.
     */
    public function default(): mixed
    {
        return null;
    }

    /**
     * Turn a raw query value into a valid filter value; invalid input yields null
     * (filter ignored). Never throws.
     */
    public function normalize(Request $request, mixed $raw): mixed
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $raw = (string) $raw;

        if ($raw === '' || strlen($raw) > self::MAX_VALUE_LENGTH) {
            return null;
        }

        return array_key_exists($raw, $this->optionLabels($request)) ? $raw : null;
    }

    /**
     * Options keyed by string value, translated once per instance.
     *
     * @return array<string, string>
     */
    public function optionLabels(Request $request): array
    {
        if ($this->resolvedOptions === null) {
            $this->resolvedOptions = [];

            foreach ($this->options($request) as $value => $label) {
                $this->resolvedOptions[(string) $value] = __($label);
            }
        }

        return $this->resolvedOptions;
    }

    /**
     * Whether the frontend receives an options list for this filter.
     */
    protected function hasOptions(): bool
    {
        return true;
    }

    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    public function authorizedToSee(Request $request): bool
    {
        return $this->canSeeCallback === null || (bool) ($this->canSeeCallback)($request);
    }

    /**
     * The index prop for this filter.
     *
     * Stable override hook: an extension filter adds its own props by merging
     * them into parent::serialize(); it must keep the base keys.
     *
     * @return array<string, mixed>
     */
    public function serialize(Request $request, mixed $value): array
    {
        $data = [
            'key' => $this->key(),
            'label' => $this->name(),
            'component' => $this->component,
        ];

        if ($this->hasOptions()) {
            $data['options'] = collect($this->optionLabels($request))
                ->map(fn (string $label, string|int $optionValue) => ['value' => (string) $optionValue, 'label' => $label])
                ->values()
                ->all();
        }

        return $data + [
            'value' => $value,
            'default' => $this->normalize($request, $this->default()),
        ];
    }
}
