<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Metrics\TrendBuckets;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsTrend;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\TestMetrics;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

beforeEach(function () {
    TestMetrics::reset();
    TestMetrics::$source = Post::class;
});

afterEach(fn () => TestMetrics::reset());

function bucketLabels(array $buckets): array
{
    return array_map(fn ($b) => $b[0]->toIso8601String(), $buckets);
}

function insertPost(string $createdAt, int $views = 0, string $status = 'draft'): void
{
    DB::table('posts')->insert([
        'title' => 'Post', 'status' => $status, 'views' => $views, 'published' => false,
        'subtitle' => '', 'created_at' => $createdAt, 'updated_at' => $createdAt,
    ]);
}

/**
 * Run the day trend and return [iso start => value].
 */
function trendValues(int $range, string $now, string $timezone, ?string $function = null, ?string $column = null): array
{
    TestMetrics::$function = $function;
    TestMetrics::$column = $column;

    $metric = new PostsTrend;
    $request = $metric->metricRequest(Request::create('/'), $range, '', CarbonImmutable::parse($now, 'UTC'));

    expect($request->timezone)->toBe($timezone);

    return collect($metric->resolve($request)->toArray()['points'])->pluck('value', 'start')->all();
}

it('builds consecutive day buckets ending with the current one', function () {
    $now = CarbonImmutable::parse('2026-10-20 15:30:00', 'UTC');

    $buckets = TrendBuckets::make('day', 3, $now);

    expect(bucketLabels($buckets))->toBe(['2026-10-18T00:00:00+00:00', '2026-10-19T00:00:00+00:00', '2026-10-20T00:00:00+00:00'])
        ->and($buckets[2][1]->toIso8601String())->toBe('2026-10-21T00:00:00+00:00')
        ->and($buckets[0][1]->equalTo($buckets[1][0]))->toBeTrue();
});

it('starts weeks on Monday', function () {
    // Wednesday 2026-10-21
    $buckets = TrendBuckets::make('week', 2, CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));

    expect(bucketLabels($buckets))->toBe(['2026-10-12T00:00:00+00:00', '2026-10-19T00:00:00+00:00'])
        ->and($buckets[1][0]->isMonday())->toBeTrue();
});

it('builds month buckets across a year boundary and different month lengths', function () {
    $buckets = TrendBuckets::make('month', 4, CarbonImmutable::parse('2026-01-31 23:00:00', 'UTC'));

    expect(bucketLabels($buckets))->toBe([
        '2025-10-01T00:00:00+00:00', '2025-11-01T00:00:00+00:00', '2025-12-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00',
    ])->and($buckets[3][1]->toIso8601String())->toBe('2026-02-01T00:00:00+00:00');

    // March 31st must not overflow when stepping back through shorter months.
    $buckets = TrendBuckets::make('month', 3, CarbonImmutable::parse('2026-03-31 10:00:00', 'UTC'));
    expect(bucketLabels($buckets))->toBe(['2026-01-01T00:00:00+00:00', '2026-02-01T00:00:00+00:00', '2026-03-01T00:00:00+00:00']);
});

it('clamps the number of buckets to 1..366 and rejects unknown units', function () {
    $now = CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC');

    expect(TrendBuckets::make('day', 5000, $now))->toHaveCount(366)
        ->and(TrendBuckets::make('day', 0, $now))->toHaveCount(1)
        ->and(TrendBuckets::make('day', -4, $now))->toHaveCount(1);

    TrendBuckets::make('hour', 3, $now);
})->throws(InvalidArgumentException::class);

it('keeps midnight boundaries across the spring-forward DST change in Europe/Kyiv', function () {
    // 2026-03-29: clocks go 03:00 -> 04:00 (EET +02:00 -> EEST +03:00), a 23 hour day.
    $buckets = TrendBuckets::make('day', 4, CarbonImmutable::parse('2026-04-01 10:00:00', 'Europe/Kyiv'));

    expect(bucketLabels($buckets))->toBe([
        '2026-03-29T00:00:00+02:00', '2026-03-30T00:00:00+03:00', '2026-03-31T00:00:00+03:00', '2026-04-01T00:00:00+03:00',
    ])->and($buckets[0][0]->diffInHours($buckets[0][1]))->toEqual(23)
        ->and($buckets[0][1]->toIso8601String())->toBe('2026-03-30T00:00:00+03:00');
});

it('keeps midnight boundaries across the fall-back DST change in Europe/Kyiv', function () {
    // 2026-10-25: clocks go 04:00 -> 03:00 (EEST +03:00 -> EET +02:00), a 25 hour day.
    $buckets = TrendBuckets::make('day', 3, CarbonImmutable::parse('2026-10-26 09:00:00', 'Europe/Kyiv'));

    expect(bucketLabels($buckets))->toBe([
        '2026-10-24T00:00:00+03:00', '2026-10-25T00:00:00+03:00', '2026-10-26T00:00:00+02:00',
    ])->and($buckets[1][0]->diffInHours($buckets[1][1]))->toEqual(25);
});

it('keeps week and month boundaries on local midnight across DST', function () {
    $weeks = TrendBuckets::make('week', 2, CarbonImmutable::parse('2026-10-28 12:00:00', 'Europe/Kyiv'));
    expect(bucketLabels($weeks))->toBe(['2026-10-19T00:00:00+03:00', '2026-10-26T00:00:00+02:00'])
        ->and($weeks[0][1]->toIso8601String())->toBe('2026-10-26T00:00:00+02:00');

    $months = TrendBuckets::make('month', 2, CarbonImmutable::parse('2026-11-10 12:00:00', 'Europe/Kyiv'));
    expect(bucketLabels($months))->toBe(['2026-10-01T00:00:00+03:00', '2026-11-01T00:00:00+02:00']);
});

it('assigns rows around the fall-back DST night to the right local day (UTC storage)', function () {
    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => 'Europe/Kyiv']);

    insertPost('2026-10-24 20:59:59'); // 23:59:59 +03:00 on the 24th
    insertPost('2026-10-24 21:00:00'); // 00:00:00 +03:00 on the 25th
    insertPost('2026-10-25 21:59:59'); // 23:59:59 +02:00 on the 25th (the 25 hour day)
    insertPost('2026-10-25 22:00:00'); // 00:00:00 +02:00 on the 26th
    insertPost('2026-10-23 00:00:00'); // outside the 3 day window

    $values = trendValues(3, '2026-10-26 09:00:00', 'Europe/Kyiv');

    expect($values)->toBe([
        '2026-10-24T00:00:00+03:00' => 1,
        '2026-10-25T00:00:00+03:00' => 2,
        '2026-10-26T00:00:00+02:00' => 1,
    ]);
});

it('assigns rows around the spring-forward DST night to the right local day (UTC storage)', function () {
    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => 'Europe/Kyiv']);

    insertPost('2026-03-28 21:59:59'); // 23:59:59 +02:00 on the 28th
    insertPost('2026-03-28 22:00:00'); // 00:00 +02:00 on the 29th (the 23 hour day)
    insertPost('2026-03-29 20:59:59'); // 23:59:59 +03:00 on the 29th
    insertPost('2026-03-29 21:00:00'); // 00:00 +03:00 on the 30th

    $values = trendValues(3, '2026-03-30 12:00:00', 'Europe/Kyiv');

    expect(array_values($values))->toBe([1, 2, 1]);
});

it('differs from a UTC trend: the same rows land in other buckets', function () {
    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => 'Europe/Kyiv']);
    insertPost('2026-10-20 22:30:00'); // 21 Oct 01:30 in Kyiv, still 20 Oct in UTC

    expect(trendValues(2, '2026-10-21 12:00:00', 'Europe/Kyiv'))->toBe([
        '2026-10-20T00:00:00+03:00' => 0,
        '2026-10-21T00:00:00+03:00' => 1,
    ]);

    config(['larsadmin.timezone' => 'UTC']);

    expect(trendValues(2, '2026-10-21 12:00:00', 'UTC'))->toBe([
        '2026-10-20T00:00:00+00:00' => 1,
        '2026-10-21T00:00:00+00:00' => 0,
    ]);
});

it('compares stored dates in the app timezone when it is not UTC', function () {
    // Dates are stored as local time of app.timezone (America/New_York, EDT = UTC-4).
    config(['app.timezone' => 'America/New_York', 'larsadmin.timezone' => 'UTC']);

    insertPost('2026-10-20 19:59:59'); // 23:59:59 UTC on the 20th
    insertPost('2026-10-20 20:00:00'); // 00:00:00 UTC on the 21st
    insertPost('2026-10-21 00:30:00'); // 04:30 UTC on the 21st

    expect(trendValues(2, '2026-10-21 12:00:00', 'UTC'))->toBe([
        '2026-10-20T00:00:00+00:00' => 1,
        '2026-10-21T00:00:00+00:00' => 2,
    ]);
});

it('handles a half-hour offset timezone', function () {
    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => 'Asia/Kolkata']); // +05:30

    insertPost('2026-10-20 18:29:59'); // 23:59:59 IST on the 20th
    insertPost('2026-10-20 18:30:00'); // 00:00 IST on the 21st

    expect(array_values(trendValues(2, '2026-10-21 12:00:00', 'Asia/Kolkata')))->toBe([1, 1]);
});

it('zero-fills count and sum and null-fills average, min and max buckets', function () {
    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => 'UTC']);
    insertPost('2026-10-19 10:00:00', 10);
    insertPost('2026-10-19 11:00:00', 30);

    $now = '2026-10-20 12:00:00';

    expect(array_values(trendValues(3, $now, 'UTC')))->toBe([0, 2, 0])
        ->and(array_values(trendValues(3, $now, 'UTC', 'sum', 'views')))->toBe([0, 40, 0])
        ->and(array_values(trendValues(3, $now, 'UTC', 'avg', 'views')))->toEqual([null, 20, null])
        ->and(array_values(trendValues(3, $now, 'UTC', 'min', 'views')))->toBe([null, 10, null])
        ->and(array_values(trendValues(3, $now, 'UTC', 'max', 'views')))->toBe([null, 30, null]);
});

it('uses one portable CASE WHEN query without driver specific date functions', function () {
    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => 'UTC']);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query;
    });

    trendValues(366, '2026-10-20 12:00:00', 'UTC');

    expect($queries)->toHaveCount(1);

    $sql = strtolower($queries[0]->sql);

    expect($sql)->toContain('case when')
        ->toContain('group by "larsadmin_bucket"')
        ->not->toContain('strftime')
        ->not->toContain('date_format')
        ->not->toContain('date_trunc')
        ->not->toContain('convert_tz')
        ->not->toContain('at time zone')
        // 2 bindings per bucket + the 2 WHERE bounds: below sqlite's legacy 999 limit.
        ->and(count($queries[0]->bindings))->toBe(366 * 2 + 2);
});

it('quotes the CASE columns with the grammar of every supported driver', function () {
    $connection = fn (string $class) => new $class(fn () => null, '', '', []);

    $mysql = (new MySqlGrammar($connection(MySqlConnection::class)))->wrap('posts.created_at');
    $pgsql = (new PostgresGrammar($connection(PostgresConnection::class)))->wrap('posts.created_at');
    $sqlite = (new SQLiteGrammar($connection(SQLiteConnection::class)))->wrap('posts.created_at');

    expect($mysql)->toBe('`posts`.`created_at`')
        ->and($pgsql)->toBe('"posts"."created_at"')
        ->and($sqlite)->toBe('"posts"."created_at"');
});

it('resolves the metrics timezone from the callback, config and app timezone', function () {
    $request = Request::create('/');
    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => null]);

    expect(Larsadmin::timezone($request))->toBe('UTC');

    config(['larsadmin.timezone' => 'Europe/Kyiv']);
    expect(Larsadmin::timezone($request))->toBe('Europe/Kyiv');

    Larsadmin::timezoneUsing(fn () => 'Asia/Tokyo');
    expect(Larsadmin::timezone($request))->toBe('Asia/Tokyo');

    // An unknown identifier is skipped, never trusted.
    Larsadmin::timezoneUsing(fn () => 'Mars/Olympus');
    expect(Larsadmin::timezone($request))->toBe('Europe/Kyiv');

    Larsadmin::timezoneUsing(fn () => null);
    expect(Larsadmin::timezone($request))->toBe('Europe/Kyiv');

    Larsadmin::timezoneUsing(fn () => 'Asia/Tokyo');
    Larsadmin::flushState();
    expect(Larsadmin::timezone($request))->toBe('Europe/Kyiv');
});
