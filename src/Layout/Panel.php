<?php

namespace Maxcxam\Larsadmin\Layout;

use InvalidArgumentException;
use Maxcxam\Larsadmin\Fields\Field;

/**
 * A titled group of fields. Layout only: it is flattened away before fields are resolved,
 * so validation, saving and the index never see it.
 */
final class Panel
{
    /** @var list<mixed> */
    private array $items;

    private ?string $description = null;

    private bool $collapsible = false;

    private bool $collapsed = false;

    private int $columns = 1;

    /**
     * @param  array<int, Field>  $fields
     */
    private function __construct(public readonly string $name, array $fields)
    {
        $this->items = array_values($fields);
    }

    /**
     * @param  array<int, Field>  $fields
     */
    public static function make(string $name, array $fields): self
    {
        return new self($name, $fields);
    }

    public function description(string $text): self
    {
        $this->description = $text;

        return $this;
    }

    /**
     * Let the user collapse the panel; it needs a name, the toggle's label.
     *
     * @throws InvalidArgumentException when the panel has no name
     */
    public function collapsible(bool $collapsible = true): self
    {
        if ($collapsible) {
            $this->assertNamed();
        }

        $this->collapsible = $collapsible;

        return $this;
    }

    /**
     * Start collapsed (implies collapsible, so it needs a name too).
     *
     * @throws InvalidArgumentException when the panel has no name
     */
    public function collapsed(bool $collapsed = true): self
    {
        if ($collapsed) {
            $this->assertNamed();
            $this->collapsible = true;
        }

        $this->collapsed = $collapsed;

        return $this;
    }

    public function columns(int $columns): self
    {
        if (! in_array($columns, [1, 2], true)) {
            throw new InvalidArgumentException("A panel supports 1 or 2 columns, [{$columns}] given.");
        }

        $this->columns = $columns;

        return $this;
    }

    /**
     * The panel's items as given; FieldLayout rejects anything that is not a Field.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return $this->items;
    }

    /**
     * A collapsed panel without a name could never be opened again (there is no toggle to click).
     */
    private function assertNamed(): void
    {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('A collapsible panel needs a name: it labels the toggle.');
        }
    }

    /**
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    public function toNode(string $key, array $attributes): array
    {
        return [
            'type' => 'panel',
            'key' => $key,
            'name' => $this->name,
            'description' => $this->description,
            'collapsible' => $this->collapsible,
            'collapsed' => $this->collapsed,
            'columns' => $this->columns,
            'fields' => $attributes,
        ];
    }
}
