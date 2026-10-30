<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Metrics\Card;
use Maxcxam\Larsadmin\Metrics\Cards;
use Maxcxam\Larsadmin\Metrics\EntitySource;
use Maxcxam\Larsadmin\Metrics\Range;
use Maxcxam\Larsadmin\Metrics\Results\PartitionResult;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\AllRangesValue;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\CardsDashboard;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\CustomMetric;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\LiveMetricPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\MetricPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PlainCard;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsByStatus;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsProgress;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsTrend;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsValue;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\TestMetrics;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Security\DenyAllPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    TestMetrics::reset();
    TestMetrics::$source = Post::class;
    MetricPost::$cardList = [];

    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => 'UTC']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    Larsadmin::auth(fn () => true);
    Larsadmin::entities([MetricPost::class, LiveMetricPost::class]);

    $this->dashboard = CardsDashboard::make();
    Larsadmin::dashboards([$this->dashboard]);

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->admin);

    // 3 in the last 30 days, 2 in the 30 days before, 1 older, 1 in the future.
    foreach ([
        ['2026-10-01 10:00:00', 'live', 10],
        ['2026-10-10 10:00:00', 'draft', 20],
        ['2026-10-20 09:00:00', 'live', 30],
        ['2026-09-01 10:00:00', 'live', 5],
        ['2026-09-15 10:00:00', 'draft', 15],
        ['2026-01-05 10:00:00', 'archived', 100],
        ['2026-10-21 10:00:00', 'live', 1000],
    ] as [$at, $status, $views]) {
        DB::table('posts')->insert([
            'title' => 'Post', 'status' => $status, 'views' => $views, 'published' => false,
            'subtitle' => '', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    TestMetrics::reset();
    MetricPost::$cardList = [];
});

function metricUrl(string $key, string $query = ''): string
{
    return '/admin/api/dashboards/cards-dashboard/metrics/'.$key.($query === '' ? '' : '?'.$query);
}

function useCards(Card ...$cards): void
{
    test()->dashboard->cardList = $cards;
}

function metricJson(string $key, string $query = ''): array
{
    $response = test()->getJson(metricUrl($key, $query))->assertOk();

    return $response->json();
}

// --- Value -----------------------------------------------------------------

it('returns the value payload with the previous period and change', function () {
    useCards(PostsValue::make());

    $json = metricJson('posts-value', 'range=30');

    expect($json)->toEqual([
        'range' => 30,
        'result' => [
            'type' => 'value', 'value' => 3, 'previous' => 2, 'change' => 50.0, 'zeroResult' => false,
            'format' => [], 'prefix' => null, 'suffix' => null,
        ],
    ]);
});

it('encodes empty format options as a JSON object', function () {
    useCards(PostsValue::make());

    $this->get(metricUrl('posts-value', 'range=30'), ['Accept' => 'application/json'])
        ->assertOk()
        ->assertSee('"format":{}', false);
});

it('serializes prefix, suffix, currency and Intl options', function () {
    useCards(CustomMetric::make());

    expect(metricJson('custom')['result'])->toBe([
        'type' => 'value', 'value' => 42, 'previous' => null, 'change' => null, 'zeroResult' => true,
        'format' => ['style' => 'currency', 'currency' => 'UAH', 'maximumFractionDigits' => 1],
        'prefix' => '~', 'suffix' => ' pcs',
    ]);
});

it('computes sum, average, min and max values', function (string $function, int|float|null $expected) {
    useCards(PostsValue::make());
    TestMetrics::$function = $function;
    TestMetrics::$column = 'views';

    expect(metricJson('posts-value', 'range=30')['result']['value'])->toEqual($expected);
})->with([
    'sum' => ['sum', 60],
    'average' => ['average', 20],
    'min' => ['min', 10],
    'max' => ['max', 30],
]);

it('returns null and no change for an empty average and a zero previous period', function () {
    useCards(PostsValue::make());
    TestMetrics::$function = 'average';
    TestMetrics::$column = 'views';
    DB::table('posts')->delete();

    expect(metricJson('posts-value', 'range=30')['result'])
        ->toMatchArray(['value' => null, 'previous' => null, 'change' => null]);

    // 1 now vs 0 before: no division by zero.
    TestMetrics::$function = null;
    DB::table('posts')->insert(['title' => 'x', 'status' => 'live', 'created_at' => '2026-10-19 10:00:00']);

    expect(metricJson('posts-value', 'range=30')['result'])
        ->toMatchArray(['value' => 1, 'previous' => 0, 'change' => null]);
});

it('supports an explicit date column', function () {
    useCards(AllRangesValue::make());
    DB::table('posts')->update(['published_at' => '2026-10-19 10:00:00']);
    DB::table('posts')->where('id', '<=', 2)->update(['published_at' => '2026-09-25 10:00:00']);
    TestMetrics::$dateColumn = 'published_at';

    expect(metricJson('posts-value', 'range=7')['result']['value'])->toBe(5);
});

it('honours every range with calendar aligned boundaries', function () {
    $now = CarbonImmutable::parse('2026-11-18 15:30:00', 'UTC'); // a Wednesday, 48 days into Q4

    [$current, $previous] = Range::bounds(7, $now);
    expect($current[0]->toDateTimeString())->toBe('2026-11-12 00:00:00')
        ->and($current[1]->toDateTimeString())->toBe('2026-11-18 15:30:00')
        ->and($previous[0]->toDateTimeString())->toBe('2026-11-05 00:00:00')
        ->and($previous[1]->toDateTimeString())->toBe('2026-11-11 15:30:00');

    [$current, $previous] = Range::bounds('TODAY', $now);
    expect($current[0]->toDateTimeString())->toBe('2026-11-18 00:00:00')
        ->and($previous[0]->toDateTimeString())->toBe('2026-11-17 00:00:00')
        ->and($previous[1]->toDateTimeString())->toBe('2026-11-17 15:30:00');

    [$current, $previous] = Range::bounds('MTD', $now);
    expect($current[0]->toDateTimeString())->toBe('2026-11-01 00:00:00')
        ->and($previous[0]->toDateTimeString())->toBe('2026-10-01 00:00:00')
        ->and($previous[1]->toDateTimeString())->toBe('2026-10-18 15:30:00');

    [$current, $previous] = Range::bounds('QTD', $now);
    expect($current[0]->toDateTimeString())->toBe('2026-10-01 00:00:00')
        ->and($previous[0]->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($previous[1]->toDateTimeString())->toBe('2026-08-18 15:30:00');

    [$current, $previous] = Range::bounds('YTD', $now);
    expect($current[0]->toDateTimeString())->toBe('2026-01-01 00:00:00')
        ->and($previous[0]->toDateTimeString())->toBe('2025-01-01 00:00:00')
        ->and($previous[1]->toDateTimeString())->toStartWith('2025-11-18');

    expect(Range::bounds('ALL', $now))->toBe([null, null])
        ->and(Range::bounds(null, $now))->toBe([null, null]);
});

it('never lets the previous period reach into the current one', function () {
    // 31st of March vs a 28 day February.
    [$current, $previous] = Range::bounds('MTD', CarbonImmutable::parse('2026-03-31 23:00:00', 'UTC'));

    expect($previous[0]->toDateTimeString())->toBe('2026-02-01 00:00:00')
        ->and($previous[1]->lessThan($current[0]))->toBeTrue();
});

it('applies the TODAY, MTD, YTD and ALL ranges to the query', function () {
    useCards(AllRangesValue::make());

    expect(metricJson('posts-value', 'range=TODAY')['result'])->toMatchArray(['value' => 1, 'previous' => 0])
        ->and(metricJson('posts-value', 'range=MTD')['result'])->toMatchArray(['value' => 3, 'previous' => 2])
        ->and(metricJson('posts-value', 'range=YTD')['result']['value'])->toBe(6)
        ->and(metricJson('posts-value', 'range=ALL')['result'])->toMatchArray(['value' => 7, 'previous' => null, 'change' => null]);
});

it('uses a calendar day range at the timezone boundary', function () {
    config(['larsadmin.timezone' => 'Asia/Tokyo']); // +09:00: it is 21:00 on the 20th, midnight is 15:00 UTC the day before
    useCards(PostsValue::make());

    // TODAY = 20 Oct 00:00 JST = 19 Oct 15:00 UTC: includes the 09:00 UTC post, excludes 10 Oct.
    expect(metricJson('posts-value', 'range=TODAY')['result']['value'])->toBe(1);
});

it('falls back to the default range for unknown or malformed values', function (string $query) {
    useCards(PostsValue::make());

    $json = metricJson('posts-value', $query);

    expect($json['range'])->toBe(30)
        ->and($json['result']['value'])->toBe(3);
})->with([
    'missing' => [''],
    'unknown number' => ['range=31'],
    'text' => ['range=bogus'],
    'lowercase key' => ['range=today'],
    'array' => ['range[]=7'],
    'negative' => ['range=-5'],
    'sql' => ['range=1%27%3B%20DROP%20TABLE%20posts%3B--'],
    'empty' => ['range='],
]);

it('echoes string ranges and exposes them in the card JSON', function () {
    useCards(CustomMetric::make());

    expect(metricJson('custom', 'range=ALL')['range'])->toBe('ALL')
        ->and(metricJson('custom', 'range=5')['range'])->toBe(5)
        ->and(metricJson('custom')['range'])->toBe(5);

    expect(CustomMetric::make()->jsonSerialize())->toMatchArray([
        'component' => 'value-metric', 'uriKey' => 'custom', 'name' => 'Custom', 'width' => '1/3', 'helpText' => null,
        'endpoint' => null, 'defaultRange' => 5,
        'ranges' => [['value' => 5, 'label' => 'Five days'], ['value' => 'ALL', 'label' => 'Everything']],
    ]);
});

it('labels the default ranges with the metrics translations', function () {
    app()->setLocale('uk');
    $ranges = PostsValue::make()->jsonSerialize()['ranges'];

    expect($ranges[0])->toBe(['value' => 30, 'label' => '30 днів'])
        ->and(collect($ranges)->pluck('value')->all())->toBe([30, 60, 365, 'TODAY', 'MTD', 'QTD', 'YTD'])
        ->and(PostsTrend::make()->jsonSerialize()['defaultRange'])->toBe(7)
        ->and(PostsByStatus::make()->jsonSerialize()['ranges'])->toBe([])
        ->and(PostsByStatus::make()->jsonSerialize()['defaultRange'])->toBeNull();
});

// --- Trend -----------------------------------------------------------------

it('returns the trend payload', function () {
    app()->setLocale('uk');
    useCards(PostsTrend::make());

    $json = metricJson('posts-trend', 'range=7');

    expect($json['range'])->toBe(7)
        ->and($json['result']['type'])->toBe('trend')
        ->and($json['result']['unit'])->toBe('day')
        ->and($json['result']['points'])->toHaveCount(7)
        ->and(array_slice($json['result']['points'], -3))->toBe([
            ['label' => '18 жов', 'start' => '2026-10-18T00:00:00+00:00', 'value' => 0],
            ['label' => '19 жов', 'start' => '2026-10-19T00:00:00+00:00', 'value' => 0],
            ['label' => '20 жов', 'start' => '2026-10-20T00:00:00+00:00', 'value' => 1],
        ])
        ->and($json['result'])->toMatchArray(['value' => null, 'valueKind' => null, 'prefix' => null, 'suffix' => null]);
});

it('can headline the sum or the latest value of a trend', function () {
    $metric = PostsTrend::make();
    $request = $metric->metricRequest(Request::create('/'), 30);

    $sum = (clone $metric)->calculate($request)->showSumValue()->toArray();
    $latest = (clone $metric)->calculate($request)->showLatestValue()->toArray();

    expect($sum)->toMatchArray(['value' => 3, 'valueKind' => 'sum'])
        ->and($latest)->toMatchArray(['value' => 1, 'valueKind' => 'latest'])
        ->and($sum['points'])->toHaveCount(30);
});

// --- Partition -------------------------------------------------------------

it('returns the partition payload ordered by size', function () {
    useCards(PostsByStatus::make());

    expect(metricJson('posts-by-status'))->toBe([
        'range' => null,
        'result' => [
            'type' => 'partition',
            'slices' => [
                ['key' => 'live', 'label' => 'live', 'value' => 4, 'color' => null],
                ['key' => 'draft', 'label' => 'draft', 'value' => 2, 'color' => null],
                ['key' => 'archived', 'label' => 'archived', 'value' => 1, 'color' => null],
            ],
            'total' => 7,
            'format' => [],
        ],
    ]);
});

it('limits partition slices, collects the rest into Other and applies labels and colors', function () {
    $metric = PostsByStatus::make();
    $result = $metric->calculate($metric->metricRequest(Request::create('/')))
        ->limit(2)
        ->label(fn (string $key) => strtoupper($key))
        ->colors(['live' => '#00ff00']);

    app()->setLocale('uk');

    expect($result->toArray()['slices'])->toBe([
        ['key' => 'live', 'label' => 'LIVE', 'value' => 4, 'color' => '#00ff00'],
        ['key' => 'draft', 'label' => 'DRAFT', 'value' => 2, 'color' => null],
        ['key' => '__other__', 'label' => 'Інше', 'value' => 1, 'color' => null],
    ])->and($result->toArray()['total'])->toBe(7);
});

it('does not sum non additive partitions into Other', function () {
    TestMetrics::$function = 'average';
    TestMetrics::$column = 'views';
    $metric = PostsByStatus::make();

    $slices = $metric->calculate($metric->metricRequest(Request::create('/')))->limit(1)->toArray()['slices'];

    expect($slices)->toHaveCount(1)->and($slices[0]['key'])->toBe('live');
});

it('rejects a partition limit below one', function () {
    $metric = PostsByStatus::make();

    $metric->calculate($metric->metricRequest(Request::create('/')))->limit(0);
})->throws(InvalidArgumentException::class);

// --- Partition labels from a related model ---------------------------------

/**
 * Authors by post: 3 x Olena (2), 2 x Admin (1), 1 x a deleted author (999), 1 x none.
 */
function partitionByAuthor(): PartitionResult
{
    $olena = User::create(['name' => 'Olena', 'email' => 'olena@example.com', 'password' => 'secret']);

    foreach (DB::table('posts')->orderBy('id')->pluck('id')->values() as $index => $id) {
        DB::table('posts')->where('id', $id)->update(['author_id' => [$olena->id, $olena->id, $olena->id, 1, 1, 999, null][$index]]);
    }

    TestMetrics::$source = Post::class;
    TestMetrics::$groupBy = 'author_id';
    $metric = PostsByStatus::make();

    return $metric->calculate($metric->metricRequest(Request::create('/')));
}

it('labels partition slices from a related model with one query', function () {
    $result = partitionByAuthor()->labelsFrom(User::class);

    DB::enableQueryLog();
    $slices = $result->toArray()['slices'];

    expect(array_column($slices, 'label', 'key'))->toBe(['2' => 'Olena', '1' => 'Admin', '' => 'None', '999' => '#999'])
        ->and(array_column($slices, 'value'))->toBe([3, 2, 1, 1])
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and(DB::getQueryLog()[0]['bindings'])->toBe([2, 1, 999]);
});

it('only loads the titles of the shown slices and still folds the rest into Other', function () {
    $result = partitionByAuthor()->labelsFrom(User::class)->limit(2)->colors(['2' => '#00ff00']);

    app()->setLocale('uk');
    DB::enableQueryLog();

    expect($result->toArray()['slices'])->toBe([
        ['key' => '2', 'label' => 'Olena', 'value' => 3, 'color' => '#00ff00'],
        ['key' => '1', 'label' => 'Admin', 'value' => 2, 'color' => null],
        ['key' => '__other__', 'label' => 'Інше', 'value' => 2, 'color' => null],
    ])->and(DB::getQueryLog()[0]['bindings'])->toBe([2, 1]);
});

it('labels the empty key with the translated default or a custom label', function () {
    $result = partitionByAuthor();

    app()->setLocale('uk');
    expect(array_column($result->labelsFrom(User::class)->toArray()['slices'], 'label', 'key')[''])->toBe('Без значення')
        ->and(array_column($result->labelsFrom(User::class, 'name', 'Анонім')->toArray()['slices'], 'label', 'key')[''])->toBe('Анонім');
});

it('labels slices from a builder and a custom title column', function () {
    $result = partitionByAuthor()->labelsFrom(User::query()->where('name', 'Olena'), 'email');

    expect(array_column($result->toArray()['slices'], 'label', 'key'))
        ->toBe(['2' => 'olena@example.com', '1' => '#1', '' => 'None', '999' => '#999']);
});

it('lets the last of label() and labelsFrom() win', function () {
    $result = partitionByAuthor();

    expect(array_column($result->labelsFrom(User::class)->label(fn (string $key) => "k{$key}")->toArray()['slices'], 'label'))
        ->toBe(['k2', 'k1', 'k', 'k999'])
        ->and(array_column($result->label(fn (string $key) => "k{$key}")->labelsFrom(User::class)->toArray()['slices'], 'label'))
        ->toBe(['Olena', 'Admin', 'None', '#999']);
});

it('rejects labels from a class that is not a model', function () {
    (new PartitionResult(['1' => 1]))->labelsFrom(stdClass::class);
})->throws(InvalidArgumentException::class);

// --- Progress --------------------------------------------------------------

it('returns the progress payload', function () {
    useCards(PostsProgress::make());

    expect(metricJson('posts-progress', 'range=30'))->toEqual([
        'range' => 30,
        'result' => [
            'type' => 'progress', 'value' => 3, 'target' => 10, 'percentage' => 30.0, 'avoid' => false,
            'format' => [], 'prefix' => null, 'suffix' => null,
        ],
    ]);
});

it('sums towards a target and can invert the colours', function () {
    TestMetrics::$column = 'views';
    $metric = PostsProgress::make();
    $result = $metric->calculate($metric->metricRequest(Request::create('/'), 30))->avoid()->prefix('$')->suffix('k');

    expect(json_decode(json_encode($result), true))->toEqual([
        'type' => 'progress', 'value' => 60, 'target' => 100, 'percentage' => 60.0, 'avoid' => true,
        'format' => [], 'prefix' => '$', 'suffix' => 'k',
    ]);
});

// --- Authorization and scoping ----------------------------------------------

it('scopes an Entity source by its indexQuery', function () {
    TestMetrics::$source = LiveMetricPost::class;
    useCards(PostsValue::make());

    // Without scoping: 3. Only live posts: 2 (1 Oct and 20 Oct).
    expect(metricJson('posts-value', 'range=30')['result']['value'])->toBe(2);

    TestMetrics::$source = MetricPost::class;
    expect(metricJson('posts-value', 'range=30')['result']['value'])->toBe(3);
});

it('does NOT restrict a plain model class or builder', function () {
    useCards(PostsValue::make());
    Gate::policy(Post::class, DenyAllPolicy::class);

    TestMetrics::$source = Post::class;
    expect(metricJson('posts-value', 'range=30')['result']['value'])->toBe(3);

    TestMetrics::$source = Post::query()->where('status', 'draft');
    expect(metricJson('posts-value', 'range=30')['result']['value'])->toBe(1);

    TestMetrics::$source = DB::table('posts')->where('status', 'live');
    TestMetrics::$dateColumn = 'created_at';
    expect(metricJson('posts-value', 'range=30')['result']['value'])->toBe(2);
});

it('answers 403 from the dashboard endpoint when an Entity source is not viewable', function () {
    Gate::policy(Post::class, DenyAllPolicy::class);
    TestMetrics::$source = MetricPost::class;
    useCards(PostsValue::make());

    $this->getJson(metricUrl('posts-value'))->assertForbidden();
});

it('answers 403 when the calculation throws an AuthorizationException', function () {
    TestMetrics::$denied = true;
    useCards(PostsValue::make());

    $this->getJson(metricUrl('posts-value'))->assertForbidden()->assertJsonStructure(['message']);
});

it('rejects a query builder source without a date column', function () {
    TestMetrics::$source = DB::table('posts');
    useCards(PostsValue::make());

    expect(fn () => $this->withoutExceptionHandling()->getJson(metricUrl('posts-value')))
        ->toThrow(InvalidArgumentException::class);
});

it('narrows an Entity source with scoped()', function () {
    TestMetrics::$source = MetricPost::class;
    TestMetrics::$constraint = fn (Builder $query) => $query->where('status', 'draft');
    useCards(PostsValue::make());

    // 3 posts in the last 30 days, 1 of them a draft (10 Oct); the previous period has 1 draft (15 Sep).
    expect(metricJson('posts-value', 'range=30')['result'])->toMatchArray(['value' => 1, 'previous' => 1]);
});

it('keeps the indexQuery scoping of a scoped() source', function () {
    TestMetrics::$source = LiveMetricPost::class;
    TestMetrics::$constraint = fn (Builder $query) => $query->where('views', '>=', 20);
    useCards(PostsValue::make());

    // views >= 20 in the last 30 days: the draft (20) and the live post (30); only the live one is visible.
    expect(metricJson('posts-value', 'range=30')['result']['value'])->toBe(1);
});

it('does not let an orWhere in the constraint escape the indexQuery scoping', function () {
    TestMetrics::$source = LiveMetricPost::class;
    TestMetrics::$constraint = fn (Builder $query) => $query->where('views', '>=', 30)->orWhere('status', 'draft');
    useCards(PostsValue::make());

    // Unnested, "status = live and views >= 30 or status = draft" would also count the hidden draft.
    expect(metricJson('posts-value', 'range=30')['result']['value'])->toBe(1);
});

it('accepts an EntitySource in every metric type', function () {
    TestMetrics::$source = EntitySource::make(LiveMetricPost::class, fn (Builder $query) => $query->where('views', '<', 1000));
    useCards(PostsValue::make(), PostsTrend::make(), PostsByStatus::make(), PostsProgress::make());

    expect(metricJson('posts-value', 'range=30')['result']['value'])->toBe(2)
        ->and(array_sum(array_column(metricJson('posts-trend', 'range=30')['result']['points'], 'value')))->toBe(2)
        ->and(metricJson('posts-by-status')['result']['slices'])->toBe([['key' => 'live', 'label' => 'live', 'value' => 3, 'color' => null]])
        ->and(metricJson('posts-progress', 'range=30')['result']['value'])->toBe(2);
});

it('answers 403 when the Entity of a scoped() source is not viewable', function () {
    Gate::policy(Post::class, DenyAllPolicy::class);
    TestMetrics::$source = MetricPost::class;
    TestMetrics::$constraint = fn (Builder $query) => $query->where('status', 'live');
    useCards(PostsValue::make());

    $this->getJson(metricUrl('posts-value'))->assertForbidden();
});

it('rejects a scoped() source that is not an Entity', function (string $class) {
    TestMetrics::$source = $class;
    TestMetrics::$constraint = fn (Builder $query) => $query;
    useCards(PostsValue::make());

    expect(fn () => $this->withoutExceptionHandling()->getJson(metricUrl('posts-value')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => EntitySource::make($class, fn () => null))
        ->toThrow(InvalidArgumentException::class);
})->with([Post::class, stdClass::class]);

it('rejects a source that is neither an Entity nor a model', function () {
    TestMetrics::$source = stdClass::class;
    useCards(PostsValue::make());

    expect(fn () => $this->withoutExceptionHandling()->getJson(metricUrl('posts-value')))
        ->toThrow(InvalidArgumentException::class);
});

it('hides invisible dashboards, cards and non metric cards with 404', function () {
    useCards(PostsValue::make()->canSee(fn () => false), PostsTrend::make(), PlainCard::make());

    $this->getJson(metricUrl('posts-value'))->assertNotFound();
    $this->getJson(metricUrl('plain-card'))->assertNotFound();
    $this->getJson(metricUrl('missing'))->assertNotFound();
    $this->getJson(metricUrl('posts-trend'))->assertOk();

    $this->dashboard->canSee(fn () => false);
    $this->getJson(metricUrl('posts-trend'))->assertNotFound();
    $this->getJson('/admin/api/dashboards/nope/metrics/posts-trend')->assertNotFound();
});

it('passes the request to canSee callbacks', function () {
    useCards(PostsValue::make()->canSee(fn (Request $request) => $request->user()->email === 'other@example.com'));

    $this->getJson(metricUrl('posts-value'))->assertNotFound();

    $this->actingAs(User::create(['name' => 'O', 'email' => 'other@example.com', 'password' => 'x']));
    $this->getJson(metricUrl('posts-value'))->assertOk();
});

it('requires an authenticated, authorized panel user', function () {
    useCards(PostsValue::make());

    $this->app['auth']->forgetGuards();
    $this->getJson(metricUrl('posts-value'))->assertUnauthorized();

    $this->actingAs($this->admin);
    Larsadmin::auth(fn () => false);
    $this->getJson(metricUrl('posts-value'))->assertForbidden();
});

// --- Entity endpoint -------------------------------------------------------

function entityMetricUrl(string $entity, string $key, string $query = ''): string
{
    return "/admin/api/entities/{$entity}/metrics/{$key}".($query === '' ? '' : '?'.$query);
}

it('serves entity cards from the entity endpoint', function () {
    MetricPost::$cardList = [PostsValue::make()];

    $this->getJson(entityMetricUrl('metric-posts', 'posts-value', 'range=30'))
        ->assertOk()
        ->assertJsonPath('result.value', 3);
});

it('scopes entity cards by the entity of the endpoint indexQuery when the source is the entity', function () {
    TestMetrics::$source = LiveMetricPost::class;
    LiveMetricPost::$cardList = [PostsValue::make()];

    $this->getJson(entityMetricUrl('live-metric-posts', 'posts-value', 'range=30'))->assertJsonPath('result.value', 2);
    LiveMetricPost::$cardList = [];
});

it('forbids the entity endpoint without viewAny and hides invisible entity cards', function () {
    MetricPost::$cardList = [PostsValue::make(), PostsTrend::make()->canSee(fn () => false)];

    $this->getJson(entityMetricUrl('metric-posts', 'posts-trend'))->assertNotFound();
    $this->getJson(entityMetricUrl('metric-posts', 'nope'))->assertNotFound();
    $this->getJson(entityMetricUrl('unknown-things', 'posts-value'))->assertNotFound();

    Gate::policy(Post::class, DenyAllPolicy::class);
    $this->getJson(entityMetricUrl('metric-posts', 'posts-value'))->assertForbidden();
});

it('serializes cards for pages with relative endpoints and only the visible ones', function () {
    $cards = [PostsValue::make()->width('1/2')->help('Hint'), PostsTrend::make()->canSee(fn () => false), PostsByStatus::make()];

    $dashboard = Cards::serialize(Request::create('/', 'GET'), $cards, 'larsadmin.api.entity-metric', 'metric-posts');

    expect($dashboard)->toHaveCount(2)
        ->and($dashboard[0])->toMatchArray([
            'component' => 'value-metric', 'uriKey' => 'posts-value', 'name' => 'Posts Value', 'width' => '1/2', 'helpText' => 'Hint',
            'endpoint' => '/admin/api/entities/metric-posts/metrics/posts-value', 'defaultRange' => 30,
        ])
        ->and($dashboard[1]['endpoint'])->toBe('/admin/api/entities/metric-posts/metrics/posts-by-status');
});

it('validates card widths', function () {
    PostsValue::make()->width('3/4');
})->throws(InvalidArgumentException::class);

// --- Caching ---------------------------------------------------------------

it('does not cache by default', function () {
    useCards(PostsValue::make());

    metricJson('posts-value');
    metricJson('posts-value');

    expect(TestMetrics::$calls)->toBe(2);
});

it('caches the result when cacheFor is set and keeps the payload identical', function () {
    Cache::flush();
    TestMetrics::$ttl = 600;
    useCards(PostsValue::make());

    $first = metricJson('posts-value', 'range=30');
    DB::table('posts')->delete();
    $second = metricJson('posts-value', 'range=30');

    expect($second)->toBe($first)
        ->and(TestMetrics::$calls)->toBe(1)
        ->and($first['result']['value'])->toBe(3);
});

it('keys the cache by range, user, timezone and locale', function () {
    Cache::flush();
    TestMetrics::$ttl = 600;
    useCards(PostsValue::make());

    metricJson('posts-value', 'range=30');
    expect(TestMetrics::$calls)->toBe(1);

    metricJson('posts-value', 'range=60');
    expect(TestMetrics::$calls)->toBe(2);

    // another user
    $this->actingAs(User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'x']));
    metricJson('posts-value', 'range=30');
    expect(TestMetrics::$calls)->toBe(3);

    // another timezone
    config(['larsadmin.timezone' => 'Europe/Kyiv']);
    metricJson('posts-value', 'range=30');
    expect(TestMetrics::$calls)->toBe(4);

    // another locale
    config(['larsadmin.locale' => 'en']);
    metricJson('posts-value', 'range=30');
    expect(TestMetrics::$calls)->toBe(5);

    // same everything: a hit
    metricJson('posts-value', 'range=30');
    expect(TestMetrics::$calls)->toBe(5);
});

it('does not share the cache between owners or serve it for a different range key', function () {
    Cache::flush();
    TestMetrics::$ttl = 600;
    useCards(PostsValue::make());
    MetricPost::$cardList = [PostsValue::make()];

    metricJson('posts-value', 'range=30');
    $this->getJson(entityMetricUrl('metric-posts', 'posts-value', 'range=30'))->assertOk();

    expect(TestMetrics::$calls)->toBe(2);

    // an invalid range is the default range: the same cache entry
    metricJson('posts-value', 'range=bogus');
    expect(TestMetrics::$calls)->toBe(2);
});

it('caches through the configured store and ignores non positive ttl', function () {
    config(['cache.stores.metrics_test' => ['driver' => 'array'], 'larsadmin.metrics.cache_store' => 'metrics_test']);
    TestMetrics::$ttl = 600;
    useCards(PostsValue::make());

    metricJson('posts-value');
    metricJson('posts-value');

    expect(TestMetrics::$calls)->toBe(1)
        ->and(Cache::store('metrics_test')->get('larsadmin:metric:'.md5(implode('|', [
            'dashboard:cards-dashboard', 'posts-value', '30', 'UTC', app()->getLocale(), (string) $this->admin->getKey(),
        ])))['value'])->toBe(3);

    TestMetrics::$ttl = 0;
    metricJson('posts-value');
    metricJson('posts-value');
    expect(TestMetrics::$calls)->toBe(3);
});

it('does not cache a denied calculation', function () {
    Cache::flush();
    TestMetrics::$ttl = 600;
    TestMetrics::$denied = true;
    useCards(PostsValue::make());

    $this->getJson(metricUrl('posts-value'))->assertForbidden();

    TestMetrics::$denied = false;
    $this->getJson(metricUrl('posts-value'))->assertOk();
    expect(TestMetrics::$calls)->toBe(2);
});
