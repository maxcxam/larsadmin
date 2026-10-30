<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Scout;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Searchable;

/**
 * A Searchable model (table created by the Scout test). The index holds the title and the body;
 * the engine is the in-memory collection engine unless a test sets one.
 */
class ScoutPost extends Model
{
    use Searchable;

    public static ?Engine $engine = null;

    protected $table = 'scout_posts';

    protected $guarded = [];

    public function searchableUsing()
    {
        return static::$engine ?? new CollectionEngine;
    }

    public function toSearchableArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}
