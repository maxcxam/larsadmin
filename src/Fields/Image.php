<?php

namespace Maxcxam\Larsadmin\Fields;

use Closure;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Files\FileTypes;

/**
 * A raster image (jpg, jpeg, png, gif, webp, avif), previewed on the index, detail and form.
 * SVG is never accepted: it can carry scripts (ADR-018).
 */
class Image extends File
{
    public string $component = 'image-field';

    public const array DEFAULT_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    public function __construct(string $name, ?string $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        $this->types = self::DEFAULT_TYPES;
    }

    /**
     * Restrict the accepted raster types. Content and client name are checked by the "mimes" and
     * "extensions" rules, so only raster images can be stored.
     */
    public function types(array $types): static
    {
        foreach ($types as $type) {
            $type = strtolower(ltrim(trim((string) $type), '.'));

            if (in_array($type, ['svg', 'svgz'], true)) {
                throw new InvalidArgumentException("The image field [{$this->attribute}] cannot accept SVG; use a File field on a private disk.");
            }

            if (FileTypes::rasterType($type) === null) {
                throw new InvalidArgumentException("The image field [{$this->attribute}] accepts only ".implode(', ', self::DEFAULT_TYPES)."; use a File field for [{$type}].");
            }
        }

        return parent::types($types);
    }

    /**
     * The preview URL of an image: fn (StoredFile $file, Model $record): ?string, null for the original.
     */
    public function thumbnail(Closure $callback): static
    {
        $this->thumbnailCallback = $callback;

        return $this;
    }
}
