<?php

namespace Maxcxam\Larsadmin\Fields;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class Date extends Field
{
    public string $component = 'date-field';

    /**
     * The format sent to the HTML date input.
     */
    protected string $inputFormat = 'Y-m-d';

    /**
     * The format used on index and detail views.
     */
    protected ?string $displayFormat = null;

    /**
     * The larsadmin::ui.formats key used when no explicit display format is set.
     */
    protected string $localeFormat = 'date';

    /**
     * Set the format used on index and detail views.
     */
    public function displayFormat(string $format): static
    {
        $this->displayFormat = $format;

        return $this;
    }

    protected function resolveValue(mixed $value): mixed
    {
        return $this->format($value, $this->inputFormat);
    }

    protected function formatForDisplay(mixed $value): mixed
    {
        return $this->format($value, $this->displayFormat ?? __("larsadmin::ui.formats.{$this->localeFormat}"), true);
    }

    protected function typeRules(): array
    {
        return ['date'];
    }

    protected function fillValue(Request $request): mixed
    {
        return $request->filled($this->attribute) ? $request->input($this->attribute) : null;
    }

    /**
     * Format the value, returning it untouched when it cannot be parsed.
     */
    protected function format(mixed $value, string $format, bool $translated = false): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);

            return $translated ? $date->translatedFormat($format) : $date->format($format);
        } catch (Throwable) {
            return $value;
        }
    }
}
