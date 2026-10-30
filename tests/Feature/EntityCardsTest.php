<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\MetricPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsTrend;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsValue;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\TestMetrics;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

const CARDS_INDEX = '/admin/entities/metric-posts';

beforeEach(function () {
    TestMetrics::reset();
    TestMetrics::$source = Post::class;
    MetricPost::$cardList = [];
    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => 'UTC', 'larsadmin.locale' => 'en']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    Larsadmin::auth(fn () => true);
    Larsadmin::entities([MetricPost::class]);
    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));

    DB::table('posts')->insert([
        'title' => 'Post', 'status' => 'live', 'views' => 1, 'published' => false,
        'subtitle' => '', 'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00',
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    TestMetrics::reset();
    MetricPost::$cardList = [];
});

it('exposes an empty cards prop by default', function () {
    $this->get(CARDS_INDEX)->assertInertia(fn (Assert $page) => $page->component('Entities/Index')->where('cards', []));
});

it('exposes visible entity cards with their endpoint and the endpoint answers', function () {
    MetricPost::$cardList = [PostsValue::make()->width('1/2')->help('Hint'), PostsTrend::make()->canSee(fn () => false)];

    $this->get(CARDS_INDEX)->assertInertia(fn (Assert $page) => $page
        ->has('cards', 1)
        ->where('cards.0.component', 'value-metric')
        ->where('cards.0.uriKey', 'posts-value')
        ->where('cards.0.width', '1/2')
        ->where('cards.0.helpText', 'Hint')
        ->where('cards.0.defaultRange', 30)
        ->where('cards.0.endpoint', '/admin/api/entities/metric-posts/metrics/posts-value')
        ->has('cards.0.ranges.0', fn (Assert $range) => $range->has('value')->has('label')));

    $this->getJson('/admin/api/entities/metric-posts/metrics/posts-value?range=30')
        ->assertOk()
        ->assertJsonPath('range', 30)
        ->assertJsonPath('result.type', 'value')
        ->assertJsonPath('result.value', 1);

    $this->getJson('/admin/api/entities/metric-posts/metrics/posts-trend')->assertNotFound();
});

it('does not add queries to the index for cards', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(CARDS_INDEX)->assertOk();

        return count(DB::getQueryLog());
    };

    $without = $count();
    MetricPost::$cardList = [PostsValue::make(), PostsTrend::make()];

    expect($count())->toBe($without)->and(TestMetrics::$calls)->toBe(0);
});
