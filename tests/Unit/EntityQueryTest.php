<?php

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Post as PostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\SpyPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

function titles(EntityQuery $query): array
{
    return $query->builder()->pluck('title')->all();
}

beforeEach(function () {
    $this->request = Request::create('/');
});

it('searches literally for wildcard characters', function () {
    Post::create(['title' => '50%_off', 'status' => 'a']);
    Post::create(['title' => '50 percent off', 'status' => 'a']);

    expect(titles(EntityQuery::for(PostEntity::class, $this->request)->search('50%_off')))->toBe(['50%_off'])
        ->and(titles(EntityQuery::for(PostEntity::class, $this->request)->search('%')))->toBe(['50%_off']);
});

it('matches the key by equality and ignores non numeric terms for integer keys', function () {
    $post = Post::create(['title' => 'Alpha', 'status' => 'a']);
    Post::create(['title' => 'Beta', 'status' => 'a']);

    expect(titles(EntityQuery::for(PostEntity::class, $this->request)->search((string) $post->id)))->toBe(['Alpha'])
        ->and(titles(EntityQuery::for(PostEntity::class, $this->request)->search('zzz')))->toBe([]);
});

it('trims and caps the search term at 100 characters', function () {
    $title = str_repeat('a', 100);
    Post::create(['title' => $title, 'status' => 'a']);

    $query = EntityQuery::for(PostEntity::class, $this->request)->search('  '.$title.str_repeat('b', 50).'  ');

    expect($query->builder()->getQuery()->getRawBindings()['where'])->toContain('%'.$title.'%')
        ->and(titles($query))->toBe([$title]);
});

it('always appends the key as a descending tiebreaker', function () {
    $sorted = EntityQuery::for(PostEntity::class, $this->request)->sort('views', 'asc')->builder()->toSql();
    $default = EntityQuery::for(PostEntity::class, $this->request)->sort(null, 'asc')->builder()->toSql();
    $ignored = EntityQuery::for(PostEntity::class, $this->request)->sort('body', 'asc')->builder()->toSql();

    expect($sorted)->toEndWith('order by "posts"."views" asc, "posts"."id" desc')
        ->and($default)->toEndWith('order by "posts"."id" desc')
        ->and($ignored)->toEndWith('order by "posts"."id" desc');
});

it('rejects unknown scopes', function () {
    EntityQuery::for(PostEntity::class, $this->request)->scope('nope');
})->throws(InvalidArgumentException::class);

it('builds index columns without resolving and memoizes prototypes', function () {
    SpyPost::$calls = 0;

    $columns = SpyPost::indexColumns($this->request);

    expect($columns)->toBe([
        ['attribute' => 'id', 'name' => 'ID', 'component' => 'id-field', 'sortable' => true, 'width' => null, 'align' => 'left', 'wrap' => false, 'truncate' => null, 'hideByDefault' => false],
        ['attribute' => 'headline', 'name' => 'Headline', 'component' => 'text-field', 'sortable' => false, 'width' => null, 'align' => 'left', 'wrap' => false, 'truncate' => null, 'hideByDefault' => false],
    ])->and(SpyPost::$calls)->toBe(1);

    $entity = SpyPost::make(new Post(['title' => 'x']));
    $entity->resolveFields($this->request, Field::DETAIL);
    $entity->resolveFields($this->request, Field::UPDATE);
    $entity->rules($this->request, Field::UPDATE);

    expect(SpyPost::$calls)->toBe(1);
});

it('does not share resolved state between contexts', function () {
    $entity = PostEntity::make(new Post(['title' => 'Hi', 'status' => 'draft']));

    $detail = $entity->resolveFields($this->request, Field::DETAIL);
    $create = $entity->resolveFields($this->request, Field::CREATE);

    expect($detail->firstWhere('attribute', 'title')->value)->toBe('Hi')
        ->and($create->firstWhere('attribute', 'title')->value)->toBeNull()
        ->and($detail->firstWhere('attribute', 'title')->context)->toBe(Field::DETAIL)
        ->and($entity->fieldPrototypes($this->request)->firstWhere('attribute', 'title')->value)->toBeNull();
});
