<?php

namespace Maxcxam\Larsadmin\Tools;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A page in the sidebar rendered by an extension's Vue component (design §4.5). Register with
 * Larsadmin::tools([...]). Data the component needs beyond props() comes from Larsadmin::routes().
 *
 * @api stable since 1.5
 */
abstract class Tool
{
    /**
     * The format of the component key: tools are rendered by extension components only (ADR-032).
     *
     * @internal
     */
    public const string COMPONENT_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+\z/';

    /**
     * The icons the panel can render in the sidebar (Icon.NAMES of the client); anything else uses the default.
     * Stable since 1.5: icons are only added in minors.
     *
     * @var list<string>
     */
    public const array ICONS = [
        'alert', 'arrow-down', 'arrow-up', 'bell', 'check', 'chevron-down', 'chevron-left', 'chevron-right',
        'dash', 'database', 'device-desktop', 'download', 'eye', 'file', 'globe', 'history', 'home', 'kebab',
        'lock', 'menu', 'moon', 'pencil', 'plus', 'search', 'sign-out', 'sun', 'trash', 'upload', 'x',
    ];

    /**
     * The key of the extension's Vue component that renders the page, e.g. 'acme/backups'.
     */
    public string $component = '';

    /** @internal Set it with canSee(). */
    protected ?Closure $canSee = null;

    public static function make(mixed ...$args): static
    {
        return new static(...$args);
    }

    /**
     * The title of the tool in the menu and on its page (translated via __()); derived from the class name.
     */
    public function name(): string
    {
        return __(Str::title(Str::snake(class_basename(static::class), ' ')));
    }

    /**
     * The key in the tool's URL; the kebab-cased class name by default.
     */
    public function uriKey(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * The sidebar icon: one of ICONS; null uses the default icon.
     */
    public function icon(): ?string
    {
        return null;
    }

    /**
     * The icon sent to the client: icon() when it is one of ICONS, else null (the default icon).
     *
     * @internal
     */
    public function navigationIcon(): ?string
    {
        $icon = $this->icon();

        return in_array($icon, self::ICONS, true) ? $icon : null;
    }

    /**
     * Only show the tool (menu item and page) when the callback returns true.
     *
     * @param  Closure(Request): bool  $callback
     */
    public function canSee(Closure $callback): static
    {
        $this->canSee = $callback;

        return $this;
    }

    /**
     * Whether the request may see the tool; true without canSee() (the panel gate already applied).
     */
    public function authorizedToSee(Request $request): bool
    {
        return $this->canSee === null || (bool) ($this->canSee)($request);
    }

    /**
     * The page props of the component (JSON encodable); none by default.
     *
     * @return array<string, mixed>
     */
    public function props(Request $request): array
    {
        return [];
    }
}
