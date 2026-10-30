<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Search\GlobalSearch;
use Maxcxam\Larsadmin\Search\ScoutCandidates;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Scout\RankedEngine;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Scout\ScoutPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Scout\ScoutPostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    if (! class_exists('Laravel\Scout\Builder')) {
        $this->markTestSkipped('laravel/scout is not installed.');
    }

    $this->app->register('Laravel\Scout\ScoutServiceProvider');

    Schema::create('scout_posts', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->text('body')->nullable();
        $table->unsignedInteger('team_id')->default(1);
        $table->timestamps();
    });

    ScoutPost::$engine = null;
    ScoutPostEntity::$scout = null;
    ScoutPostEntity::$indexUsing = null;
    ScoutPostEntity::$scoutUsing = null;
    ScoutPostEntity::$scoutCalls = [];
    ScoutPostEntity::$globalSearchLimit = null;

    Larsadmin::entities([ScoutPostEntity::class]);
    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));

    foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $i => $title) {
        ScoutPost::create(['title' => $title, 'body' => "hidden word {$title}", 'team_id' => $i % 2 + 1]);
    }
});

function scoutSearch(string $term): array
{
    return app(GlobalSearch::class)->run(Request::create('/admin/api/search'), $term);
}

function scoutTitles(string $term): array
{
    return array_column(scoutSearch($term)['groups'][0]['results'] ?? [], 'title');
}

function rankedEngine(array $keys): RankedEngine
{
    return ScoutPost::$engine = new RankedEngine($keys);
}

it('detects a Searchable model and searches through Scout (AC-3)', function () {
    expect(ScoutPostEntity::usesScout())->toBeTrue();

    // "word" is only in the indexed body, not in $search: only the engine can find it.
    $group = scoutSearch('word')['groups'][0];

    expect(array_column($group['results'], 'title'))->toBe(['Delta', 'Gamma', 'Beta', 'Alpha'])
        ->and($group['indexUrl'])->toBe('/admin/entities/scout-posts?search=word')
        ->and($group['more'])->toBeFalse();
});

it('searches the $search columns when usesScout() is false (AC-3)', function () {
    ScoutPostEntity::$scout = false;

    expect(scoutSearch('word')['groups'])->toBe([])
        ->and(scoutTitles('alpha'))->toBe(['Alpha']);
});

it('keeps the engine ranking', function () {
    rankedEngine([3, 1, 4, 2]);

    expect(scoutTitles('anything'))->toBe(['Gamma', 'Alpha', 'Delta', 'Beta']);
});

it('drops keys outside indexQuery, stale and malformed keys (AC-8)', function () {
    rankedEngine([99, 'x', '3', 2, 3, 1, '12345678901234567890']);
    ScoutPostEntity::$indexUsing = fn ($query) => $query->where('title', '!=', 'Beta');

    expect(scoutTitles('anything'))->toBe(['Gamma', 'Alpha']);
});

it('runs no SQL when the engine finds nothing', function () {
    rankedEngine([]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(scoutSearch('nothing')['groups'])->toBe([])
        ->and(DB::getQueryLog())->toBe([]);

    rankedEngine(['x', 'y']);

    expect(scoutSearch('nothing')['groups'])->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('passes the request and the Scout builder through scoutQuery() to pre-filter', function () {
    ScoutPostEntity::$scoutUsing = fn ($search) => $search->where('team_id', 2);

    expect(scoutTitles('word'))->toBe(['Delta', 'Beta'])
        ->and(ScoutPostEntity::$scoutCalls)->toHaveCount(1)
        ->and(ScoutPostEntity::$scoutCalls[0][0])->toBeInstanceOf(Request::class)
        ->and(ScoutPostEntity::$scoutCalls[0][1])->toBeInstanceOf('Laravel\Scout\Builder')
        ->and(ScoutPostEntity::$scoutCalls[0][1]->query)->toBe('word');
});

it('asks the engine for scout_candidates keys, at least limit + 1', function () {
    $engine = rankedEngine([1, 2, 3, 4]);

    scoutSearch('word');
    config(['larsadmin.search.scout_candidates' => 2]);
    scoutSearch('word');
    ScoutPostEntity::$globalSearchLimit = 3;
    $group = scoutSearch('word')['groups'][0];

    expect($engine->builders()->pluck('limit')->all())->toBe([50, 6, 4])
        ->and($group['more'])->toBeTrue()
        ->and(array_column($group['results'], 'title'))->toBe(['Alpha', 'Beta', 'Gamma']);
});

it('computes more after the index scope', function () {
    rankedEngine([1, 2, 3, 4]);
    ScoutPostEntity::$globalSearchLimit = 2;
    ScoutPostEntity::$indexUsing = fn ($query) => $query->whereIn('title', ['Alpha', 'Beta']);

    expect(scoutSearch('word')['groups'][0]['more'])->toBeFalse();
});

it('lists the entity in failed when the engine fails, without falling back to SQL', function () {
    Exceptions::fake();
    ScoutPost::$engine = new RankedEngine([], new RuntimeException('Meilisearch is down.'));

    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = scoutSearch('alpha');

    expect($response['groups'])->toBe([])
        ->and($response['failed'])->toBe([['entity' => 'scout-posts', 'label' => 'Scout posts']])
        ->and(collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'scout_posts'))->all())->toBe([]);

    Exceptions::assertReported(RuntimeException::class);
});

it('returns the candidates in rank order from the service directly', function () {
    rankedEngine([2, 1]);

    $rows = (new ScoutCandidates)->get(ScoutPostEntity::class, Request::create('/'), 'x', 1);

    expect($rows->pluck('title')->all())->toBe(['Beta']);
});
