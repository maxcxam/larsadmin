<?php

namespace Maxcxam\Larsadmin\Metrics\Results;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class PartitionResult extends MetricResult
{
    /**
     * The key of the slice that collects everything beyond the limit.
     */
    public const string OTHER = '__other__';

    protected ?Closure $label = null;

    /**
     * The related titles requested by labelsFrom(): [query, title column, label of the empty key].
     *
     * @var array{0: Builder, 1: string, 2: string|null}|null
     */
    protected ?array $labelsFrom = null;

    /** @var array<string, string> */
    protected array $colors = [];

    protected int $limit = 8;

    /**
     * @param  array<string, int|float>  $values  group key => aggregate, largest first
     * @param  bool  $additive  whether slices beyond the limit may be summed into "Other" (not for average/min/max)
     */
    public function __construct(protected array $values, protected bool $additive = true) {}

    /**
     * Format the group key into a slice label. The key is always a string: '' for a NULL group.
     * Replaces labelsFrom() (the last of the two wins).
     *
     * @param  Closure(string): string  $callback
     */
    public function label(Closure $callback): static
    {
        $this->label = $callback;
        $this->labelsFrom = null;

        return $this;
    }

    /**
     * Label the slices with a column of a related model, the group keys being its primary keys
     * (e.g. category_id => Category::class). The titles of the shown slices are loaded with one
     * query when the result is serialized; a NULL/'' key is labelled $missing (default: "None"),
     * a key without a row "#<key>". Replaces label() (the last of the two wins).
     *
     * SECURITY: the related model is read as given (no entity scoping or policies), but only for
     * the keys already in this result, so it reveals the titles of the groups the user can count.
     *
     * @param  class-string<Model>|Builder  $model
     */
    public function labelsFrom(string|Builder $model, string $titleColumn = 'name', ?string $missing = null): static
    {
        if (is_string($model)) {
            if (! is_subclass_of($model, Model::class)) {
                throw new InvalidArgumentException("Partition labels need a Model class or an Eloquent builder; got [{$model}].");
            }

            $model = $model::query();
        }

        $this->labelsFrom = [$model, $titleColumn, $missing];
        $this->label = null;

        return $this;
    }

    /**
     * CSS colors per group key; slices without one take the palette colour by index.
     *
     * @param  array<string, string>  $colors
     */
    public function colors(array $colors): static
    {
        $this->colors = $colors;

        return $this;
    }

    /**
     * Show at most $limit slices; the rest is collected into "Other".
     */
    public function limit(int $limit = 8): static
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The partition limit must be at least 1.');
        }

        $this->limit = $limit;

        return $this;
    }

    public function toArray(): array
    {
        $shown = array_slice($this->values, 0, $this->limit, true);
        $label = $this->label ?? ($this->labelsFrom !== null ? $this->relatedLabels(array_keys($shown)) : null);

        $slices = [];

        foreach ($shown as $key => $value) {
            $key = (string) $key;

            $slices[] = [
                'key' => $key,
                'label' => $label !== null ? (string) $label($key) : ($key === '' ? __('larsadmin::ui.no_data') : $key),
                'value' => $value,
                'color' => $this->colors[$key] ?? null,
            ];
        }

        $rest = array_slice($this->values, $this->limit, null, true);

        if ($rest !== [] && $this->additive) {
            $slices[] = [
                'key' => self::OTHER,
                'label' => __('larsadmin::metrics.other'),
                'value' => array_sum($rest),
                'color' => null,
            ];
        }

        return [
            'type' => 'partition',
            'slices' => $slices,
            'total' => array_sum(array_column($slices, 'value')),
            'format' => $this->formatObject(),
        ];
    }

    /**
     * Load the related titles of the given keys (one query) and return the labelling closure.
     *
     * @param  list<int|string>  $keys
     * @return Closure(string): string
     */
    private function relatedLabels(array $keys): Closure
    {
        [$query, $titleColumn, $missing] = $this->labelsFrom;

        $related = $query->getModel();
        $integerKeys = in_array($related->getKeyType(), ['int', 'integer'], true);

        $ids = [];

        foreach ($keys as $key) {
            $key = (string) $key;

            if ($key !== '') {
                $ids[] = $integerKeys && is_numeric($key) ? (int) $key : $key;
            }
        }

        $titles = [];

        if ($ids !== []) {
            foreach ((clone $query)->whereIn($related->getQualifiedKeyName(), $ids)->pluck($titleColumn, $related->getKeyName()) as $id => $title) {
                $titles[(string) $id] = (string) $title;
            }
        }

        $missing ??= __('larsadmin::metrics.none');

        return fn (string $key): string => $key === '' ? $missing : (($titles[$key] ?? '') !== '' ? $titles[$key] : "#{$key}");
    }
}
