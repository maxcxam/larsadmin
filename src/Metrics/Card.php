<?php

namespace Maxcxam\Larsadmin\Metrics;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A dashboard or entity index card. Stable for subclasses since 1.5: $component, $width, name(), uriKey(),
 * width(), help(), canSee(), withMeta() and jsonSerialize().
 */
abstract class Card implements JsonSerializable
{
    /**
     * The widths a card may take on the grid.
     *
     * @var list<string>
     */
    public const array WIDTHS = ['1/3', '1/2', '2/3', 'full'];

    /**
     * The key of the Vue component that renders the card (see the frontend card registry).
     */
    public string $component = '';

    /**
     * The width of the card: '1/3', '1/2', '2/3' or 'full'.
     */
    public string $width = '1/3';

    public ?string $helpText = null;

    protected ?Closure $canSee = null;

    protected ?string $endpoint = null;

    /**
     * Extra props merged into the serialized card after the base keys (withMeta()).
     *
     * @var array<string, mixed>
     */
    protected array $meta = [];

    public static function make(mixed ...$args): static
    {
        return new static(...$args);
    }

    /**
     * The title of the card (translated via __()); derived from the class name by default.
     */
    public function name(): string
    {
        return __(Str::title(Str::snake(class_basename(static::class), ' ')));
    }

    /**
     * The key that identifies the card inside its owner (dashboard or entity); the kebab-cased class name by default.
     */
    public function uriKey(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    public function width(string $width): static
    {
        if (! in_array($width, self::WIDTHS, true)) {
            throw new InvalidArgumentException('Card width must be one of: '.implode(', ', self::WIDTHS).'.');
        }

        $this->width = $width;

        return $this;
    }

    public function help(string $text): static
    {
        $this->helpText = $text;

        return $this;
    }

    /**
     * Only show the card (and serve its data) when the callback returns true.
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
     * The serialization keys meta may not replace: the client and the endpoint depend on them.
     *
     * @var list<string>
     */
    protected const array BASE_KEYS = ['component', 'uriKey', 'name', 'width', 'helpText', 'endpoint'];

    /**
     * Merge extra props into the serialized card, after the base keys.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws InvalidArgumentException for a base key (component, uriKey, name, width, helpText, endpoint)
     */
    public function withMeta(array $meta): static
    {
        $reserved = array_intersect(array_map('strval', array_keys($meta)), self::BASE_KEYS);

        if ($reserved !== []) {
            throw new InvalidArgumentException('Card meta cannot replace the base keys ['.implode(', ', $reserved).']; use the matching methods.');
        }

        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * Set the URL the card loads its data from; done by the owner while serializing.
     */
    public function withEndpoint(string $url): static
    {
        $this->endpoint = $url;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_merge([
            'component' => $this->component,
            'uriKey' => $this->uriKey(),
            'name' => $this->name(),
            'width' => $this->width,
            'helpText' => $this->helpText,
            'endpoint' => $this->endpoint,
        ], $this->meta);
    }
}
