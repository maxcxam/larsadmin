<?php

namespace Maxcxam\Larsadmin\Actions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;
use ReflectionMethod;

/**
 * A bulk or standalone operation on an entity's records.
 *
 * Run semantics (see ActionController):
 * - Records are processed in chunks of $chunkSize, each handled in its own transaction; a
 *   failure rolls back only its chunk. Already committed chunks stay, the exception is
 *   reported and the user gets an error toast saying how many records were handled.
 * - Selecting 'all' matching records is capped by config "larsadmin.actions.max_all" (422 above it).
 * - Records failing canRun()/the entity update ability are skipped (and counted); a selection
 *   that matches nothing is answered with an error toast, one where nothing is authorized with 403.
 * - Of the responses returned by the chunks the most severe wins: danger, then redirect/download,
 *   then message (the earlier one on a tie).
 * - A standalone action has no records: canRun() receives a null model, and without it the
 *   entity's create ability decides.
 */
abstract class Action
{
    /**
     * Whether the action is dangerous (the UI shows a red confirm button).
     */
    public bool $destructive = false;

    /**
     * Whether the action runs without selected records.
     */
    public bool $standalone = false;

    public ?string $confirmText = null;

    public ?string $confirmButtonText = null;

    public ?string $cancelButtonText = null;

    /**
     * Skip the confirmation modal. Null = only when not destructive, without
     * confirm text and without fields.
     */
    public ?bool $withoutConfirmation = null;

    public bool $showOnIndex = true;

    public bool $showOnDetail = true;

    public bool $showInline = false;

    public int $chunkSize = 200;

    protected ?Closure $canSeeCallback = null;

    protected ?Closure $canRunCallback = null;

    /** @var class-string<Entity>|null */
    protected ?string $entity = null;

    /** @var Collection<int, Field>|null */
    private ?Collection $fieldPrototypes = null;

    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }

    /**
     * Handle the action for one chunk of authorized records (inside a transaction).
     */
    abstract public function handle(ActionFields $fields, Collection $models): ?ActionResponse;

    /**
     * The label shown in the UI (translated via __()). Derived from the class name (English)
     * when not overridden: override it for other locales, e.g. uk.
     */
    public function name(): string
    {
        return __(Str::title(Str::snake(class_basename(static::class), ' ')));
    }

    /**
     * The URL segment identifying the action within its entity.
     */
    public function uriKey(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * The fields collected in the confirmation modal (create context).
     *
     * @return array<int, Field>
     */
    public function fields(Request $request): array
    {
        return [];
    }

    /**
     * @param  class-string<Entity>  $entity
     */
    public function bindTo(string $entity): static
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * The unresolved fields, built once and bound to the entity.
     *
     * @return Collection<int, Field>
     */
    public function fieldPrototypes(Request $request): Collection
    {
        return $this->fieldPrototypes ??= collect($this->fields($request))
            ->each(fn (Field $field) => $this->entity ? $field->bindTo($request, $this->entity) : null)
            ->values();
    }

    /**
     * The fields cloned and resolved with their defaults, for serialization.
     *
     * @return Collection<int, Field>
     */
    public function resolveFields(Request $request): Collection
    {
        return $this->fieldPrototypes($request)
            ->filter(fn (Field $field) => $field->isShownOn(Field::CREATE, $request))
            ->map(fn (Field $field) => clone $field)
            ->each(function (Field $field) use ($request) {
                $field->context = Field::CREATE;
                $field->readonly = $field->isReadonlyFor($request, $this->scratchModel());
                $field->resolveDefault($request);
            })
            ->values();
    }

    /**
     * A fresh model handed to readonly closures (actions have no record).
     */
    protected function scratchModel(): Model
    {
        return new class extends Model {};
    }

    /**
     * The fields whose values are collected and validated.
     *
     * @return Collection<int, Field>
     */
    public function editableFields(Request $request): Collection
    {
        return $this->fieldPrototypes($request)
            ->filter(fn (Field $field) => $field->isShownOn(Field::CREATE, $request)
                && ! $field->isReadonlyFor($request, $this->scratchModel())
                && ! $field->isRelation())
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(Request $request): array
    {
        return $this->editableFields($request)
            ->flatMap(fn (Field $field) => $field->rulesFor(Field::CREATE))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(Request $request): array
    {
        return $this->editableFields($request)
            ->mapWithKeys(fn (Field $field) => [$field->attribute => $field->name])
            ->all();
    }

    /**
     * Validate the request against the action's fields and return their values.
     */
    public function validatedFields(Request $request): ActionFields
    {
        $request->validate($this->rules($request), [], $this->validationAttributes($request));

        $scratch = new class extends Model
        {
            protected $guarded = [];
        };

        $values = [];

        foreach ($this->editableFields($request) as $field) {
            $field->fill($request, $scratch);

            $values[$field->attribute] = $scratch->getAttribute($field->fillAttribute());
        }

        return new ActionFields($values);
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
     * @param  Closure(Request, ?Model): bool  $callback  the model is null for a standalone action
     */
    public function canRun(Closure $callback): static
    {
        $this->canRunCallback = $callback;

        return $this;
    }

    /**
     * Whether the user may run the action on the record. Defaults to the
     * entity's update ability; an unbound action fails closed.
     */
    public function authorizedToRun(Request $request, Model $model): bool
    {
        if ($this->canRunCallback !== null) {
            return (bool) ($this->canRunCallback)($request, $model);
        }

        return $this->entity !== null && $this->entity::make($model)->authorizedToUpdate($request);
    }

    /**
     * Whether availability is just the entity's update ability (no canRun callback, authorizedToRun not
     * overridden), so a caller that already knows that ability can skip the policy call.
     */
    public function usesDefaultRunCheck(): bool
    {
        return $this->canRunCallback === null
            && (new ReflectionMethod($this, 'authorizedToRun'))->getDeclaringClass()->getName() === self::class;
    }

    /**
     * Whether the user may run a standalone action (it has no records to check). The canRun
     * callback is called with a null model when set, otherwise the entity's create ability
     * decides. An unbound action fails closed.
     */
    public function authorizedToRunStandalone(Request $request): bool
    {
        if ($this->canRunCallback !== null) {
            return (bool) ($this->canRunCallback)($request, null);
        }

        return $this->entity !== null && $this->entity::authorizedToCreate($request);
    }

    public function destructive(bool $destructive = true): static
    {
        $this->destructive = $destructive;

        return $this;
    }

    public function standalone(bool $standalone = true): static
    {
        $this->standalone = $standalone;

        return $this;
    }

    public function confirmText(string $text): static
    {
        $this->confirmText = $text;

        return $this;
    }

    public function confirmButtonText(string $text): static
    {
        $this->confirmButtonText = $text;

        return $this;
    }

    public function cancelButtonText(string $text): static
    {
        $this->cancelButtonText = $text;

        return $this;
    }

    public function withoutConfirmation(bool $without = true): static
    {
        $this->withoutConfirmation = $without;

        return $this;
    }

    /**
     * Show the action in the index toolbar menu.
     */
    public function showOnIndex(bool $show = true): static
    {
        $this->showOnIndex = $show;

        return $this;
    }

    /**
     * Show the action in the detail page menu.
     */
    public function showOnDetail(bool $show = true): static
    {
        $this->showOnDetail = $show;

        return $this;
    }

    /**
     * Show the action only in the per-row menu of the index.
     */
    public function onlyInline(): static
    {
        $this->showOnIndex = false;
        $this->showOnDetail = false;
        $this->showInline = true;

        return $this;
    }

    public function onlyOnIndex(): static
    {
        $this->showOnIndex = true;
        $this->showOnDetail = false;

        return $this;
    }

    public function onlyOnDetail(): static
    {
        $this->showOnIndex = false;
        $this->showOnDetail = true;

        return $this;
    }

    public function showInline(bool $inline = true): static
    {
        $this->showInline = $inline;

        return $this;
    }

    /**
     * The action prop shown on index and detail pages.
     *
     * @return array<string, mixed>
     */
    public function serialize(Request $request): array
    {
        $fields = $this->resolveFields($request);

        return [
            'uriKey' => $this->uriKey(),
            'name' => $this->name(),
            'destructive' => $this->destructive,
            'standalone' => $this->standalone,
            'confirmText' => $this->confirmText !== null
                ? __($this->confirmText)
                : __($this->destructive ? 'larsadmin::actions.confirm_destructive' : 'larsadmin::actions.confirm'),
            'confirmButtonText' => __($this->confirmButtonText ?? ($this->destructive ? 'larsadmin::actions.confirm_destructive_button' : 'larsadmin::actions.confirm_button')),
            'cancelButtonText' => __($this->cancelButtonText ?? 'larsadmin::actions.cancel_button'),
            'withoutConfirmation' => $this->withoutConfirmation
                ?? (! $this->destructive && $this->confirmText === null && $fields->isEmpty()),
            'showOnIndex' => $this->showOnIndex,
            'showOnDetail' => $this->showOnDetail,
            'showInline' => $this->showInline,
            'fields' => $fields,
        ];
    }
}
