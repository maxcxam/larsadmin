<?php

namespace Maxcxam\Larsadmin\Dashboards;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Metrics\Card;
use Maxcxam\Larsadmin\Metrics\Cards;

abstract class Dashboard
{
    /**
     * Whether the dashboard is listed in the sidebar.
     */
    public bool $displayInNavigation = true;

    protected ?Closure $canSee = null;

    public static function make(mixed ...$args): static
    {
        return new static(...$args);
    }

    /**
     * The name shown in the sidebar and as page title (translated via __()); derived from the class name by default.
     */
    public function name(): string
    {
        return __(Str::title(Str::snake(class_basename(static::class), ' ')));
    }

    /**
     * The key used in the URL; the kebab-cased class name by default.
     */
    public function uriKey(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * The cards of the dashboard, in order. Every card still checks its own canSee().
     *
     * @return array<int, Card>
     */
    abstract public function cards(Request $request): array;

    /**
     * Only show the dashboard (and serve its metrics) when the callback returns true.
     *
     * @param  Closure(Request): bool  $callback
     */
    public function canSee(Closure $callback): static
    {
        $this->canSee = $callback;

        return $this;
    }

    public function authorizedToSee(Request $request): bool
    {
        return $this->canSee === null || (bool) ($this->canSee)($request);
    }

    /**
     * The cards the request may see.
     *
     * @return list<Card>
     */
    public function visibleCards(Request $request): array
    {
        return Cards::visible($request, $this->cards($request));
    }

    /**
     * The cards for the page prop, with their JSON endpoints.
     *
     * @return list<array<string, mixed>>
     */
    public function serializeCards(Request $request): array
    {
        return Cards::serialize($request, $this->cards($request), 'larsadmin.api.dashboard-metric', $this->uriKey());
    }
}
