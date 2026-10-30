<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\ConfigurablePost;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

const BASE = '/admin/entities/configurable-posts';

beforeEach(function () {
    ConfigurablePost::reset();
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([ConfigurablePost::class]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

afterEach(fn () => ConfigurablePost::reset());

function fieldsAre(Closure $factory): void
{
    ConfigurablePost::$factory = $factory;
}

function newPost(array $attributes = []): Post
{
    return Post::create($attributes + ['title' => 'Hello', 'status' => 'draft', 'subtitle' => 'orig']);
}

it('serializes column options on the index', function () {
    fieldsAre(fn () => [
        ID::make()->sortable(),
        Text::make('Title')->width('20%')->truncate(30)->sortable(),
        Number::make('Views')->width(80),
        Boolean::make('Published')->hideByDefault(),
        Text::make('Status')->wrap()->align('center'),
    ]);
    newPost();

    $this->get(BASE)->assertOk()->assertInertia(fn ($page) => $page
        ->where('columns.1.width', '20%')
        ->where('columns.1.truncate', 30)
        ->where('columns.1.wrap', false)
        ->where('columns.1.align', 'left')
        ->where('columns.1.hideByDefault', false)
        ->where('columns.2.width', '80px')
        ->where('columns.2.align', 'right')
        ->where('columns.3.align', 'center')
        ->where('columns.3.hideByDefault', true)
        ->where('columns.3.width', null)
        ->where('columns.4.wrap', true)
        ->where('columns.4.truncate', null)
        ->where('records.data.0.fields.2.align', 'right')
        ->where('records.data.0.fields.1.truncate', 30)
        ->where('records.data.0.fields.4.wrap', true));
});

it('applies the entity default sort when the request has no valid sort', function () {
    fieldsAre(fn () => [ID::make()->sortable(), Text::make('Title')->sortable(), Number::make('Views')->sortable()]);
    ConfigurablePost::$sort = ['views' => 'desc'];
    newPost(['title' => 'A', 'views' => 1]);
    newPost(['title' => 'B', 'views' => 9]);
    newPost(['title' => 'C', 'views' => 5]);

    $this->get(BASE)->assertInertia(fn ($page) => $page
        ->where('records.data.0.title', 'B')
        ->where('records.data.1.title', 'C')
        ->where('query.sort', null)
        ->where('query.direction', null)
        ->where('query.defaultSort', ['attribute' => 'views', 'direction' => 'desc']));

    // An invalid requested sort falls back to the default.
    $this->get(BASE.'?sort=nope&direction=asc')->assertInertia(fn ($page) => $page
        ->where('records.data.0.title', 'B')
        ->where('query.sort', null));

    // A valid requested sort wins and is echoed; the default stays advertised.
    $this->get(BASE.'?sort=title&direction=asc')->assertInertia(fn ($page) => $page
        ->where('records.data.0.title', 'A')
        ->where('query.sort', 'title')
        ->where('query.direction', 'asc')
        ->where('query.defaultSort', ['attribute' => 'views', 'direction' => 'desc']));
});

it('reports a null default sort when none is configured', function () {
    fieldsAre(fn () => [ID::make()->sortable(), Text::make('Title')]);

    $this->get(BASE)->assertInertia(fn ($page) => $page->where('query.defaultSort', null));
});

it('orders by the default sort before the key tiebreaker', function () {
    fieldsAre(fn () => [ID::make()->sortable(), Number::make('Views')->sortable()]);
    ConfigurablePost::$sort = ['views' => 'asc'];

    $sql = EntityQuery::fromRequest(Request::create('/'), ConfigurablePost::class)->applyRequest()->builder()->toSql();

    expect($sql)->toEndWith('order by "posts"."views" asc, "posts"."id" desc');
});

it('fails with a clear message for an invalid default sort', function (array $sort, string $message) {
    fieldsAre(fn () => [ID::make()->sortable(), Text::make('Title'), Text::make('Secret')->sortable()->canSee(fn () => false)]);
    ConfigurablePost::$sort = $sort;

    expect(fn () => EntityQuery::fromRequest(Request::create('/'), ConfigurablePost::class))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unsortable field' => [['title' => 'asc'], '[title] is not a sortable field'],
    'unknown attribute' => [['nope' => 'asc'], '[nope] is not a sortable field'],
    'hidden field' => [['secret' => 'asc'], '[secret] is not a sortable field'],
    'bad direction' => [['id' => 'sideways'], 'use [\'attribute\' => \'asc\'|\'desc\']'],
]);

it('evaluates a readonly closure per record and serializes the bool', function () {
    fieldsAre(fn () => [
        ID::make(),
        Text::make('Title')->readonly(fn (Request $request, $record) => $record->status === 'live'),
    ]);
    $live = newPost(['status' => 'live']);
    $draft = newPost(['status' => 'draft']);

    $this->get(BASE)->assertInertia(fn ($page) => $page
        ->where('records.data.0.fields.1.readonly', false) // newest first: draft
        ->where('records.data.1.fields.1.readonly', true));

    $this->get(BASE."/{$live->id}/edit")->assertInertia(fn ($page) => $page->where('record.fields.0.readonly', true));
    $this->get(BASE."/{$draft->id}/edit")->assertInertia(fn ($page) => $page->where('record.fields.0.readonly', false));
    $this->get(BASE.'/create')->assertInertia(fn ($page) => $page->where('fields.0.readonly', false));
});

it('does not leak a per-record decision to another record through shared prototypes', function () {
    fieldsAre(fn () => [
        ID::make(),
        Text::make('Title')->readonly(fn ($request, $record) => $record->status === 'live'),
        Text::make('Subtitle')->hideFromDetail(fn ($request, $record) => $record?->status === 'live'),
    ]);
    $live = newPost(['status' => 'live']);
    $draft = newPost(['status' => 'draft']);
    $request = Request::create('/');

    $a = ConfigurablePost::make($live)->resolveFields($request, 'detail');
    $b = ConfigurablePost::make($draft)->resolveFields($request, 'detail');
    $c = ConfigurablePost::make($live)->resolveFields($request, 'detail');

    expect($a->pluck('attribute')->all())->toBe(['id', 'title'])
        ->and($b->pluck('attribute')->all())->toBe(['id', 'title', 'subtitle'])
        ->and($c->pluck('attribute')->all())->toBe(['id', 'title'])
        ->and([$a[1]->readonly, $b[1]->readonly, $c[1]->readonly])->toBe([true, false, true]);
});

it('does not write a conditionally readonly field on a crafted update', function () {
    fieldsAre(fn () => [
        ID::make(),
        Text::make('Title')->readonly(fn ($request, $record) => $record->status === 'live')->rules('required'),
        Select::make('Status')->options(['draft' => 'Draft', 'live' => 'Live'])->rules('required'),
    ]);
    $live = newPost(['status' => 'live']);
    $draft = newPost(['status' => 'draft']);

    $this->put(BASE."/{$live->id}", ['title' => 'Hacked', 'status' => 'live'])->assertRedirect();
    $this->put(BASE."/{$draft->id}", ['title' => 'Changed', 'status' => 'draft'])->assertRedirect();

    expect($live->fresh()->title)->toBe('Hello')->and($draft->fresh()->title)->toBe('Changed');

    // Readonly fields are not validated either: an invalid value for one does not fail the request.
    $this->put(BASE."/{$live->id}", ['title' => '', 'status' => 'live'])->assertSessionHasNoErrors();
});

it('does not write or validate a conditionally hidden field on a crafted write', function () {
    fieldsAre(fn () => [
        ID::make(),
        Text::make('Title')->rules('required'),
        Text::make('Subtitle')->hideWhenUpdating(fn ($request, $record) => $record->status === 'live')->rules('required'),
        Text::make('Body')->hideWhenCreating(fn () => true)->nullable(),
        Select::make('Status')->options(['draft' => 'Draft', 'live' => 'Live'])->rules('required'),
    ]);
    $live = newPost(['status' => 'live']);
    $draft = newPost(['status' => 'draft']);

    $this->put(BASE."/{$live->id}", ['title' => 'T', 'status' => 'live', 'subtitle' => 'hacked'])->assertSessionHasNoErrors();
    $this->put(BASE."/{$draft->id}", ['title' => 'T', 'status' => 'draft', 'subtitle' => 'ok'])->assertSessionHasNoErrors();
    $this->put(BASE."/{$draft->id}", ['title' => 'T', 'status' => 'draft'])->assertSessionHasErrors('subtitle');

    expect($live->fresh()->subtitle)->toBe('orig')->and($draft->fresh()->subtitle)->toBe('ok');

    $this->post(BASE, ['title' => 'N', 'status' => 'draft', 'subtitle' => 's', 'body' => 'injected'])->assertSessionHasNoErrors();

    expect(Post::latest('id')->first()->body)->toBeNull();
});

it('removes a canSee false field from every context', function () {
    fieldsAre(fn () => [
        ID::make()->sortable(),
        Text::make('Title')->sortable(),
        Text::make('Subtitle')->sortable()->rules('required')->canSee(fn () => false),
        Select::make('Status')->options(['draft' => 'Draft'])->rules('required')->hideFromIndex()->hideFromDetail(),
    ]);
    $post = newPost(['subtitle' => 'orig', 'title' => 'findme']);

    $this->get(BASE.'?sort=subtitle')->assertInertia(fn ($page) => $page
        ->has('columns', 2)
        ->where('query.sort', null)
        ->has('records.data.0.fields', 2));
    $this->get(BASE."/{$post->id}")->assertInertia(fn ($page) => $page->has('record.fields', 2));
    $this->get(BASE."/{$post->id}/edit")->assertInertia(fn ($page) => $page->has('record.fields', 2));
    $this->get(BASE.'/create')->assertInertia(fn ($page) => $page->has('fields', 2));

    // Neither validated nor written.
    $this->put(BASE."/{$post->id}", ['title' => 'T', 'subtitle' => 'hacked', 'status' => 'draft'])->assertSessionHasNoErrors();
    $this->post(BASE, ['title' => 'N', 'status' => 'draft', 'subtitle' => 'injected'])->assertSessionHasNoErrors();

    expect($post->fresh()->subtitle)->toBe('orig')
        ->and(Post::latest('id')->first()->subtitle)->toBe('');
});

it('excludes a canSee false field from search', function () {
    fieldsAre(fn () => [ID::make(), Text::make('Title'), Text::make('Subtitle')->canSee(fn () => false)]);
    newPost(['title' => 'Plain', 'subtitle' => 'needle']);

    $this->get(BASE.'?search=needle')->assertInertia(fn ($page) => $page->where('records.total', 0));

    fieldsAre(fn () => [ID::make(), Text::make('Title'), Text::make('Subtitle')->canSee(fn () => true)]);

    $this->get(BASE.'?search=needle')->assertInertia(fn ($page) => $page->where('records.total', 1));
});

it('evaluates index visibility closures per request with a null record', function () {
    $records = [];
    fieldsAre(function () use (&$records) {
        return [
            ID::make(),
            Text::make('Title')->hideFromIndex(function ($request, $record) use (&$records) {
                $records[] = $record;

                return $request->query('compact') === '1';
            }),
        ];
    });
    newPost();

    $this->get(BASE)->assertInertia(fn ($page) => $page->has('columns', 2)->has('records.data.0.fields', 2));
    $this->get(BASE.'?compact=1')->assertInertia(fn ($page) => $page->has('columns', 1)->has('records.data.0.fields', 1));

    expect(array_filter($records, fn ($record) => $record !== null))->toBeEmpty();
});

it('keeps the query count of the index unchanged by closures', function () {
    fieldsAre(fn () => [
        ID::make()->sortable(),
        Text::make('Title')->readonly(fn () => true)->hideFromDetail(fn () => false)->canSee(fn () => true),
    ]);
    ConfigurablePost::$sort = ['id' => 'desc'];
    foreach (range(1, 5) as $i) {
        newPost();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(BASE)->assertOk();
    $count = count(DB::getQueryLog());

    foreach (range(1, 10) as $i) {
        newPost();
    }

    DB::flushQueryLog();
    $this->get(BASE)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($count);
});
