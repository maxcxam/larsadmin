<?php

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Maxcxam\Larsadmin\Support\Like;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

it('escapes wildcards so they match literally', function () {
    Post::create(['title' => '50%_off', 'status' => 'a']);
    Post::create(['title' => '50 percent off', 'status' => 'a']);
    Post::create(['title' => 'wow!', 'status' => 'a']);

    $match = function (string $term) {
        $query = Post::query();
        Like::where($query, 'title', $term);

        return $query->pluck('title')->all();
    };

    expect($match('50%_off'))->toBe(['50%_off'])
        ->and($match('%'))->toBe(['50%_off'])
        ->and($match('_'))->toBe(['50%_off'])
        ->and($match('!'))->toBe(['wow!'])
        ->and($match('OFF'))->toHaveCount(2);
});

it('generates an escape clause and honours the boolean', function () {
    $query = Post::query()->where('id', 1);
    Like::where($query, 'posts.title', 'x', 'and');

    expect($query->toSql())->toContain('"posts"."title" like ? escape \'!\'')->toContain(' and ');
});

it('orders rows starting with the term first, with wildcards escaped', function () {
    Post::create(['title' => 'x 50%', 'status' => 'a']);
    Post::create(['title' => '50% off', 'status' => 'a']);
    Post::create(['title' => '50 percent off', 'status' => 'a']);
    Post::create(['title' => 'Off season', 'status' => 'a']);

    $order = function (string $term) {
        $query = Post::query();
        Like::orderByPrefix($query, 'title', $term);

        return $query->orderBy('id')->pluck('title')->all();
    };

    expect($order('50%'))->toBe(['50% off', 'x 50%', '50 percent off', 'Off season'])
        ->and($order('off'))->toBe(['Off season', 'x 50%', '50% off', '50 percent off']);
});

it('generates a case expression with the prefix binding, ilike on pgsql', function () {
    $query = Post::query();
    Like::orderByPrefix($query, 'posts.title', 'a_b');

    expect($query->toSql())->toContain('order by case when "posts"."title" like ? escape \'!\' then 0 else 1 end')
        ->and($query->getBindings())->toBe(['a!_b%']);

    $connection = new SQLiteConnection(DB::connection()->getPdo(), '', '', ['driver' => 'pgsql']);

    $pgsql = Post::query()->setQuery(new QueryBuilder($connection));
    Like::orderByPrefix($pgsql, 'title', 'x');

    expect($pgsql->toSql())->toContain('"title" ilike ? escape \'!\'');
});
