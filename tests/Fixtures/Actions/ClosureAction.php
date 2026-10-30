<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Actions;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;

/**
 * Generic closure-driven action recording every handled chunk.
 */
class ClosureAction extends Action
{
    public function __construct(public string $key = 'mark-live', public ?Closure $callback = null, public array $fieldList = []) {}

    public function uriKey(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return 'Mark live';
    }

    public function fields(Request $request): array
    {
        return $this->fieldList;
    }

    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        ActionPost::$calls[] = ['fields' => $fields->toArray(), 'ids' => $models->modelKeys()];

        $models->each->update(['status' => 'live']);

        return $this->callback ? ($this->callback)($fields, $models) : null;
    }
}
