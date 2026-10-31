<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Fields\Field;

/**
 * A queued, closure-driven action recording every handled chunk: fn (ActionFields, Collection, int $chunk).
 * Without a callback it marks the records "live".
 */
class QueuedAction extends Action
{
    public bool $queued = true;

    /** @var list<array{fields: array<string, mixed>, ids: list<int|string>, user: mixed, locale: string}> */
    public static array $calls = [];

    public int $chunk = 0;

    /**
     * @param  array<int, Field>  $fieldList
     */
    public function __construct(public string $key = 'publish', public ?Closure $callback = null, public array $fieldList = [], public string $label = 'Publish') {}

    public function uriKey(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->label;
    }

    public function fields(Request $request): array
    {
        return $this->fieldList;
    }

    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        $this->chunk++;

        static::$calls[] = [
            'fields' => $fields->toArray(),
            'ids' => $models->modelKeys(),
            'user' => request()->user()?->getAuthIdentifier(),
            'locale' => app()->getLocale(),
        ];

        if ($this->callback !== null) {
            return ($this->callback)($fields, $models, $this->chunk);
        }

        $models->each->update(['status' => 'live']);

        return null;
    }
}
