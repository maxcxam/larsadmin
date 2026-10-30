<?php

namespace Maxcxam\Larsadmin\Metrics\Results;

use JsonSerializable;
use stdClass;

abstract class MetricResult implements JsonSerializable
{
    /**
     * The Intl.NumberFormat options sent to the browser.
     *
     * @var array<string, mixed>
     */
    protected array $format = [];

    /**
     * Format the number as a currency (ISO 4217 code, e.g. "UAH").
     */
    public function currency(string $iso): static
    {
        $this->format = [...$this->format, 'style' => 'currency', 'currency' => strtoupper($iso)];

        return $this;
    }

    /**
     * Extra Intl.NumberFormat options, e.g. ['maximumFractionDigits' => 1] or ['style' => 'percent'].
     *
     * @param  array<string, mixed>  $options
     */
    public function format(array $options): static
    {
        $this->format = [...$this->format, ...$options];

        return $this;
    }

    /**
     * The format options as a JSON object (an empty one must not encode as a list).
     */
    protected function formatObject(): stdClass|array
    {
        return $this->format === [] ? new stdClass : $this->format;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Cast a database aggregate (strings on some drivers) to int|float; null stays null.
     */
    public static function number(mixed $value): int|float|null
    {
        return is_numeric($value) ? $value + 0 : null;
    }
}
