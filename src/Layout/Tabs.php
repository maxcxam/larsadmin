<?php

namespace Maxcxam\Larsadmin\Layout;

use InvalidArgumentException;
use LogicException;

/**
 * A tab set: every panel becomes one tab.
 */
final class Tabs
{
    /** @var list<Panel> */
    private array $panels;

    /**
     * @param  array<int, Panel>  $panels
     */
    private function __construct(public readonly ?string $name, array $panels)
    {
        if ($panels === []) {
            throw new InvalidArgumentException('Tabs need at least one panel.');
        }

        foreach ($panels as $panel) {
            if (! $panel instanceof Panel) {
                throw new LogicException('Tabs accept Panel instances only, ['.get_debug_type($panel).'] given.');
            }
        }

        $this->panels = array_values($panels);
    }

    /**
     * @param  array<int, Panel>  $panels
     */
    public static function make(?string $name, array $panels): self
    {
        return new self($name, $panels);
    }

    /**
     * @return list<Panel>
     */
    public function panels(): array
    {
        return $this->panels;
    }
}
