<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Field;

/**
 * An extension field that asks Field::authorizedTo() for the record it is resolved for (ADR-041) and sends
 * the answers to the client, the way a field would decide to emit a URL only with "view".
 */
class AbilityAwareField extends Field
{
    public const array ABILITIES = ['view', 'update', 'delete', 'forceDelete'];

    public string $component = 'acme/ability-aware';

    /**
     * The answers of authorizedTo() at the last resolve() / resolveDefault().
     *
     * @var array<string, bool>
     */
    public array $answers = [];

    public function resolve(Model $record): static
    {
        parent::resolve($record);

        $this->answers = $this->ask();

        return $this;
    }

    public function resolveDefault(Request $request): static
    {
        parent::resolveDefault($request);

        $this->answers = $this->ask();

        return $this;
    }

    /**
     * @return array<string, bool>
     */
    public function ask(): array
    {
        return array_combine(self::ABILITIES, array_map(fn (string $ability) => $this->authorizedTo($ability), self::ABILITIES));
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'answers' => $this->answers];
    }
}
