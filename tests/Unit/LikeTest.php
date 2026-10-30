<?php

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
