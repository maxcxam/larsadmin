<?php

namespace Maxcxam\Larsadmin\Fields;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use Maxcxam\Larsadmin\Entity;

abstract class Field implements JsonSerializable
{
    public const string INDEX = 'index';

    public const string DETAIL = 'detail';

    public const string CREATE = 'create';

    public const string UPDATE = 'update';

    /**
     * The Vue component that renders the field.
     */
    public string $component;

    /**
     * The displayable name of the field.
     */
    public string $name;

    /**
     * The model attribute the field represents.
     */
    public string $attribute;

    /**
     * The resolved field value, as sent to forms.
     */
    public mixed $value = null;

    /**
     * The resolved field value, formatted for index and detail views.
     */
    public mixed $displayedAs = null;

    /**
     * The context the field is currently resolved for.
     */
    public ?string $context = null;

    public bool $sortable = false;

    public bool $nullable = false;

    public bool $readonly = false;

    public ?string $help = null;

    public ?string $placeholder = null;

    public bool $showOnIndex = true;

    public bool $showOnDetail = true;

    public bool $showOnCreation = true;

    public bool $showOnUpdate = true;

    public bool $wrap = false;

    public ?string $width = null;

    /**
     * Span both columns of a two-column panel.
     */
    public bool $fullWidth = false;

    protected ?string $align = null;

    /**
     * Characters shown before the ellipsis; 0 means CSS ellipsis only, null no truncation.
     */
    protected ?int $truncate = null;

    public bool $hideByDefault = false;

    /**
     * Whether the audit log records only that the field changed, never its values.
     */
    private bool $sensitive = false;

    /**
     * Closures that decide visibility per context (true = shown), replacing the static flag.
     *
     * @var array<string, Closure>
     */
    protected array $visibilityCallbacks = [];

    protected ?Closure $readonlyCallback = null;

    protected ?Closure $canSeeCallback = null;

    protected ?Request $canSeeRequest = null;

    protected bool $canSeeResult = true;

    protected array $rules = [];

    protected array $creationRules = [];

    protected array $updateRules = [];

    protected array $meta = [];

    protected ?Closure $resolveCallback = null;

    protected ?Closure $displayCallback = null;

    protected ?Closure $fillCallback = null;

    protected ?Closure $defaultCallback = null;

    public function __construct(string $name, ?string $attribute = null, ?callable $resolveCallback = null)
    {
        if ($attribute === null && preg_match('/[^\x00-\x7F]/', $name)) {
            throw new InvalidArgumentException(
                "Cannot derive a column from the non-ASCII field name [{$name}]; pass the attribute as the second argument."
            );
        }

        $this->name = $name;
        $this->attribute = $attribute ?? Str::snake(Str::lower($name));
        $this->resolveCallback = $resolveCallback ? Closure::fromCallable($resolveCallback) : null;
    }

    /**
     * Create a new field.
     */
    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }

    /**
     * Resolve the field value from the given record.
     */
    public function resolve(Model $record): static
    {
        $raw = data_get($record, $this->attribute);

        $this->value = $this->resolveCallback
            ? call_user_func($this->resolveCallback, $raw, $record, $this->attribute)
            : $this->resolveValue($raw);

        // Display values are derived from the raw attribute value, not the
        // form-formatted one. A resolveUsing() callback replaces the raw value.
        $source = $this->resolveCallback ? $this->value : $raw;

        $this->displayedAs = $this->displayCallback
            ? call_user_func($this->displayCallback, $source, $record, $this->attribute)
            : $this->formatForDisplay($source);

        return $this;
    }

    /**
     * Resolve the default value for a record that is being created.
     */
    public function resolveDefault(Request $request): static
    {
        $this->value = $this->defaultCallback ? call_user_func($this->defaultCallback, $request) : null;

        return $this;
    }

    /**
     * Called once when the owning entity builds its fields, so relation fields
     * know their parent entity and the request.
     *
     * @param  class-string<Entity>  $entity
     */
    public function bindTo(Request $request, string $entity): void {}

    /**
     * The model attribute written by fill(). Defaults to the request key.
     */
    public function fillAttribute(): string
    {
        return $this->attribute;
    }

    /**
     * Fill the record with the value from the request.
     *
     * Returns an optional callback that runs after the record was saved.
     */
    public function fill(Request $request, Model $record): ?Closure
    {
        if ($this->fillCallback) {
            $result = call_user_func($this->fillCallback, $request, $record, $this->attribute);

            return $result instanceof Closure ? $result : null;
        }

        if ($request->exists($this->attribute)) {
            $value = $this->fillValue($request);

            $record->{$this->fillAttribute()} = $this->nullable && $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * Apply the field's ordering to an index query.
     */
    public function applySort(Builder $query, string $direction): void
    {
        $query->orderBy($query->getModel()->qualifyColumn($this->attribute), $direction);
    }

    /**
     * The relations to eager load on the index and detail queries.
     *
     * @return array<int, string>
     */
    public function eagerLoads(): array
    {
        return [];
    }

    /**
     * Determine if the field is a relation panel field (never filled).
     */
    public function isRelation(): bool
    {
        return false;
    }

    /**
     * Transform the raw attribute value of the record.
     */
    protected function resolveValue(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Format the resolved value for index and detail views.
     */
    protected function formatForDisplay(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Get the value that should be stored on the record.
     */
    protected function fillValue(Request $request): mixed
    {
        return $request->input($this->attribute);
    }

    /**
     * Get a string value from the request. The ConvertEmptyStringsToNull
     * middleware turns empty input into null, which a non-nullable column
     * cannot store, so it is stored as an empty string instead.
     */
    protected function fillString(Request $request): ?string
    {
        $value = $request->input($this->attribute);

        return $value ?? ($this->nullable ? null : '');
    }

    /**
     * Rules implied by the field type, enforced before any user rules.
     *
     * @return array<int, mixed>
     */
    protected function typeRules(): array
    {
        return [];
    }

    /**
     * Determine if null input is valid for the field even when not nullable.
     */
    protected function acceptsNullInput(): bool
    {
        return false;
    }

    /**
     * @return array<int, mixed>
     */
    protected function implicitRules(): array
    {
        $rules = $this->fillCallback ? [] : $this->typeRules();

        if ($rules === []) {
            return [];
        }

        return $this->nullable || $this->acceptsNullInput() ? ['nullable', ...$rules] : $rules;
    }

    public function resolveUsing(callable $callback): static
    {
        $this->resolveCallback = Closure::fromCallable($callback);

        return $this;
    }

    public function displayUsing(callable $callback): static
    {
        $this->displayCallback = Closure::fromCallable($callback);

        return $this;
    }

    public function fillUsing(callable $callback): static
    {
        $this->fillCallback = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Set the default value used when creating a record.
     */
    public function default(mixed $value): static
    {
        $this->defaultCallback = $value instanceof Closure ? $value : fn () => $value;

        return $this;
    }

    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Make the field readonly, either always or per record:
     * fn (Request $request, Model $record): bool.
     */
    public function readonly(bool|Closure $readonly = true): static
    {
        $this->readonlyCallback = $readonly instanceof Closure ? $readonly : null;
        $this->readonly = $readonly instanceof Closure ? false : $readonly;

        return $this;
    }

    /**
     * Determine if the field is readonly for the given record (a fresh model when creating).
     */
    public function isReadonlyFor(Request $request, Model $record): bool
    {
        return $this->readonlyCallback
            ? (bool) call_user_func($this->readonlyCallback, $request, $record)
            : $this->readonly;
    }

    /**
     * Show the field only when the callback returns true: fn (Request $request): bool.
     * A field that cannot be seen is absent from every context (columns, detail, forms, fill, validation, sort).
     */
    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;
        $this->canSeeRequest = null;

        return $this;
    }

    public function isAuthorizedToSee(?Request $request = null): bool
    {
        if ($this->canSeeCallback === null) {
            return true;
        }

        $request ??= request();

        if ($this->canSeeRequest !== $request) {
            $this->canSeeResult = (bool) call_user_func($this->canSeeCallback, $request);
            $this->canSeeRequest = $request;
        }

        return $this->canSeeResult;
    }

    /**
     * The column width: an int is pixels; strings like "20%", "12rem" are used as given.
     */
    public function width(string|int $width): static
    {
        $normalized = is_int($width) ? $width.'px' : trim($width);

        if (preg_match('/^\d+(\.\d+)?(px|%|rem|em|ch)$/', $normalized) !== 1) {
            throw new InvalidArgumentException(
                "Invalid width [{$width}] for the field [{$this->attribute}]; use an integer (pixels) or a value like '120px', '20%', '12rem'."
            );
        }

        $this->width = $normalized;

        return $this;
    }

    public function fullWidth(bool $full = true): static
    {
        $this->fullWidth = $full;

        return $this;
    }

    public function align(string $align): static
    {
        if (! in_array($align, ['left', 'center', 'right'], true)) {
            throw new InvalidArgumentException("Invalid alignment [{$align}] for the field [{$this->attribute}]; use left, center or right.");
        }

        $this->align = $align;

        return $this;
    }

    /**
     * The alignment used when align() was not called.
     */
    protected function defaultAlign(): string
    {
        return 'left';
    }

    public function getAlign(): string
    {
        return $this->align ?? $this->defaultAlign();
    }

    /**
     * Allow the cell content to wrap (excludes truncate()).
     */
    public function wrap(bool $wrap = true): static
    {
        $this->wrap = $wrap;

        if ($wrap) {
            $this->truncate = null;
        }

        return $this;
    }

    /**
     * Cut long content with an ellipsis, after $chars characters or by the CSS width when null (excludes wrap()).
     */
    public function truncate(?int $chars = null): static
    {
        if ($chars !== null && $chars < 1) {
            throw new InvalidArgumentException("The truncate length for the field [{$this->attribute}] must be at least 1.");
        }

        $this->truncate = $chars ?? 0;
        $this->wrap = false;

        return $this;
    }

    public function getTruncate(): ?int
    {
        return $this->truncate;
    }

    /**
     * The column is available but hidden until the user turns it on.
     */
    public function hideByDefault(bool $hide = true): static
    {
        $this->hideByDefault = $hide;

        return $this;
    }

    /**
     * The index column definition of the field.
     *
     * @return array<string, mixed>
     */
    public function columnDefinition(): array
    {
        return [
            'attribute' => $this->attribute,
            'name' => $this->name,
            'component' => $this->component,
            'sortable' => $this->sortable,
            'width' => $this->width,
            'align' => $this->getAlign(),
            'wrap' => $this->wrap,
            'truncate' => $this->truncate,
            'hideByDefault' => $this->hideByDefault,
        ];
    }

    /**
     * Never write the field's values to the audit log: only the fact that it changed is recorded.
     * Password fields are always sensitive.
     */
    public function sensitive(bool $sensitive = true): static
    {
        $this->sensitive = $sensitive;

        return $this;
    }

    public function isSensitive(): bool
    {
        return $this->sensitive;
    }

    public function help(string $help): static
    {
        $this->help = $help;

        return $this;
    }

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * Merge extra data into the serialized field.
     */
    public function withMeta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * Set the validation rules applied on both creation and update.
     */
    public function rules(mixed ...$rules): static
    {
        $this->rules = $this->normalizeRules($rules);

        return $this;
    }

    public function creationRules(mixed ...$rules): static
    {
        $this->creationRules = $this->normalizeRules($rules);

        return $this;
    }

    public function updateRules(mixed ...$rules): static
    {
        $this->updateRules = $this->normalizeRules($rules);

        return $this;
    }

    /**
     * Get the validation rules for the given context, keyed by attribute.
     *
     * The "{{id}}" placeholder in string rules is replaced with the record key,
     * e.g. "unique:users,email,{{id}}". Implicit rules for the field type
     * (e.g. "numeric", "in") are prepended unless a fillUsing() callback is set.
     */
    public function rulesFor(string $context, ?Model $record = null): array
    {
        $rules = array_merge(
            $this->implicitRules(),
            $this->rules,
            $context === self::CREATE ? $this->creationRules : $this->updateRules,
        );

        $key = $record?->getKey() ?? 'NULL';

        return [
            $this->attribute => array_map(
                fn ($rule) => is_string($rule) ? str_replace('{{id}}', (string) $key, $rule) : $rule,
                $rules,
            ),
        ];
    }

    /**
     * The validation attribute names of the keys validated by rulesFor(), mapped to the field name.
     *
     * @return array<string, string>
     */
    public function validationAttributeNames(): array
    {
        return [$this->attribute => $this->name];
    }

    /**
     * Flatten the given rules, exploding "required|max:255" pipe strings.
     *
     * Rule objects and closures are kept intact. A string containing a
     * "regex:" or "not_regex:" rule is NOT exploded, because the pattern may
     * contain a pipe; pass such rules as separate arguments or an array.
     *
     * @return array<int, mixed>
     */
    protected function normalizeRules(array $rules): array
    {
        $rules = count($rules) === 1 && is_array($rules[0]) ? $rules[0] : $rules;

        return collect($rules)
            ->flatMap(function ($rule) {
                if (! is_string($rule) || str_contains($rule, 'regex:')) {
                    return [$rule];
                }

                return array_filter(explode('|', $rule), fn (string $part) => $part !== '');
            })
            ->values()
            ->all();
    }

    /**
     * Determine if the field is required in its current context.
     */
    public function isRequired(): bool
    {
        $rules = $this->rulesFor($this->context === self::UPDATE ? self::UPDATE : self::CREATE)[$this->attribute];

        return in_array('required', $rules, true);
    }

    /**
     * Determine if the field is shown in the given context. Closures receive the request and the
     * record (null on the index, where visibility is per request, not per row).
     */
    public function isShownOn(string $context, ?Request $request = null, ?Model $record = null): bool
    {
        if ($this->canSeeCallback !== null && ! $this->isAuthorizedToSee($request)) {
            return false;
        }

        if (isset($this->visibilityCallbacks[$context])) {
            return (bool) call_user_func(
                $this->visibilityCallbacks[$context],
                $request ?? request(),
                $context === self::INDEX ? null : $record,
            );
        }

        return $this->flagFor($context);
    }

    /**
     * Determine if the field could be shown in the context for some record (used to eager load).
     */
    public function mayShowOn(string $context, ?Request $request = null): bool
    {
        return $this->isAuthorizedToSee($request)
            && (isset($this->visibilityCallbacks[$context]) || $this->flagFor($context));
    }

    protected function flagFor(string $context): bool
    {
        return match ($context) {
            self::INDEX => $this->showOnIndex,
            self::DETAIL => $this->showOnDetail,
            self::CREATE => $this->showOnCreation,
            self::UPDATE => $this->showOnUpdate,
        };
    }

    /**
     * Set the visibility of a context from a bool or a closure; $hide inverts the condition.
     */
    protected function setVisibility(string $context, bool|Closure $condition, bool $hide): static
    {
        if ($condition instanceof Closure) {
            $this->visibilityCallbacks[$context] = $hide
                ? fn (Request $request, ?Model $record) => ! $condition($request, $record)
                : $condition;

            return $this;
        }

        unset($this->visibilityCallbacks[$context]);

        $shown = $hide ? ! $condition : $condition;

        match ($context) {
            self::INDEX => $this->showOnIndex = $shown,
            self::DETAIL => $this->showOnDetail = $shown,
            self::CREATE => $this->showOnCreation = $shown,
            self::UPDATE => $this->showOnUpdate = $shown,
        };

        return $this;
    }

    public function hideFromIndex(bool|Closure $condition = true): static
    {
        return $this->setVisibility(self::INDEX, $condition, true);
    }

    public function hideFromDetail(bool|Closure $condition = true): static
    {
        return $this->setVisibility(self::DETAIL, $condition, true);
    }

    public function hideWhenCreating(bool|Closure $condition = true): static
    {
        return $this->setVisibility(self::CREATE, $condition, true);
    }

    public function hideWhenUpdating(bool|Closure $condition = true): static
    {
        return $this->setVisibility(self::UPDATE, $condition, true);
    }

    public function showOnIndex(bool|Closure $condition = true): static
    {
        return $this->setVisibility(self::INDEX, $condition, false);
    }

    public function showOnDetail(bool|Closure $condition = true): static
    {
        return $this->setVisibility(self::DETAIL, $condition, false);
    }

    public function showWhenCreating(bool|Closure $condition = true): static
    {
        return $this->setVisibility(self::CREATE, $condition, false);
    }

    public function showWhenUpdating(bool|Closure $condition = true): static
    {
        return $this->setVisibility(self::UPDATE, $condition, false);
    }

    public function onlyOnIndex(): static
    {
        return $this->only(self::INDEX);
    }

    public function onlyOnDetail(): static
    {
        return $this->only(self::DETAIL);
    }

    public function onlyOnForms(): static
    {
        return $this->only(self::CREATE, self::UPDATE);
    }

    public function exceptOnForms(): static
    {
        return $this->only(self::INDEX, self::DETAIL);
    }

    protected function only(string ...$contexts): static
    {
        $this->visibilityCallbacks = [];
        $this->showOnIndex = in_array(self::INDEX, $contexts, true);
        $this->showOnDetail = in_array(self::DETAIL, $contexts, true);
        $this->showOnCreation = in_array(self::CREATE, $contexts, true);
        $this->showOnUpdate = in_array(self::UPDATE, $contexts, true);

        return $this;
    }

    public function jsonSerialize(): array
    {
        return array_merge([
            'component' => $this->component,
            'name' => $this->name,
            'attribute' => $this->attribute,
            'value' => $this->value,
            'displayedAs' => $this->displayedAs,
            'sortable' => $this->sortable,
            'nullable' => $this->nullable,
            'readonly' => $this->readonly,
            'required' => $this->isRequired(),
            'help' => $this->help,
            'placeholder' => $this->placeholder,
            'align' => $this->getAlign(),
            'wrap' => $this->wrap,
            'truncate' => $this->truncate,
            'fullWidth' => $this->fullWidth,
        ], $this->meta);
    }
}
