<?php

namespace Maxcxam\Larsadmin\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\BelongsToMany;

/**
 * Accepts only keys the user could have picked from the options endpoint:
 * the related entity's relatable scope plus its viewAny ability.
 * With $attach the value must also not be attached to the record yet.
 */
class RelatableExists implements ValidationRule
{
    public function __construct(
        private readonly BelongsTo|BelongsToMany $field,
        private readonly Request $request,
        private readonly ?Model $record = null,
        private readonly bool $attach = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = $this->field->normalizeKey($value);

        if ($key === null) {
            $fail('validation.exists')->translate();

            return;
        }

        if ($this->attach) {
            $this->validateAttach($key, $fail);

            return;
        }

        // An unchanged value stays valid, so a record whose related row left the scope can still be saved.
        if ($this->record?->exists && (string) $this->record->getAttribute($this->field->fillAttribute()) === (string) $key) {
            return;
        }

        if ($this->field->findRelatable($this->request, $key, $this->record) === null) {
            $fail('validation.exists')->translate();
        }
    }

    private function validateAttach(int|string $key, Closure $fail): void
    {
        $related = $this->field->findRelatable($this->request, $key, $this->record);

        if ($related === null) {
            $fail('validation.exists')->translate();

            return;
        }

        $attached = $this->field->relation($this->record)->whereKey($related->getKey())->exists();

        if ($attached) {
            $fail('larsadmin::messages.already_attached')->translate();
        }
    }
}
