<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit\Actions;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Fields\Field;

/**
 * A closure-driven action for the audit tests: fn (ActionFields $fields, Collection $models, int $chunk).
 */
class AuditAction extends Action
{
    public int $chunks = 0;

    /**
     * @param  array<int, Field>  $fieldList
     */
    public function __construct(public string $key, public Closure $callback, public array $fieldList = [], public string $label = 'Опублікувати') {}

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
        $this->chunks++;

        return ($this->callback)($fields, $models, $this->chunks);
    }
}
