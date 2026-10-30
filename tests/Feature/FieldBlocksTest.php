<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\HasMany;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Layout\FieldLayout;
use Maxcxam\Larsadmin\Layout\Panel;
use Maxcxam\Larsadmin\Layout\Tabs;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\ConfigurablePost;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\VisibleUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

const BLOCKS = '/admin/entities/configurable-posts';

beforeEach(function () {
    ConfigurablePost::reset();
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([ConfigurablePost::class]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

afterEach(fn () => ConfigurablePost::reset());

function blockFields(Closure $factory): void
{
    ConfigurablePost::$factory = $factory;
}

function blockEntity(): ConfigurablePost
{
    return ConfigurablePost::make(new Post);
}

function blockPost(): Post
{
    return Post::create(['title' => 'Hello', 'status' => 'draft', 'body' => 'b', 'subtitle' => 's']);
}

function mixedFields(): array
{
    return [
        ID::make(),
        Text::make('Title')->rules('required'),
        Panel::make('Main', [Text::make('Subtitle')->fullWidth(), Textarea::make('Body')])
            ->description('Desc')->collapsible()->columns(2),
        Tabs::make('Details', [
            Panel::make('SEO', [Number::make('Views')]),
            Panel::make('Misc', [Text::make('Status')]),
        ]),
    ];
}

function flatFields(): array
{
    return [
        ID::make(), Text::make('Title')->rules('required'), Text::make('Subtitle')->fullWidth(), Textarea::make('Body'),
        Number::make('Views'), Text::make('Status'),
    ];
}

function serverContract(): array
{
    $request = Request::create('/');
    $entity = blockEntity();

    return [
        $entity->fieldPrototypes($request)->map->attribute->all(),
        $entity->rules($request, 'create'),
        $entity->validationAttributes($request, 'create'),
        ConfigurablePost::indexColumns($request),
    ];
}

it('flattens blocks into ordered prototypes', function () {
    blockFields(fn () => mixedFields());

    expect(blockEntity()->fieldPrototypes(request())->map->attribute->all())
        ->toBe(['id', 'title', 'subtitle', 'body', 'views', 'status']);
});

it('keeps order, rules, validation attributes and index columns identical to the same fields without blocks', function () {
    blockFields(fn () => mixedFields());
    $withBlocks = serverContract();

    blockFields(fn () => flatFields());

    expect($withBlocks)->toEqual(serverContract());
});

it('saves fields declared inside blocks', function () {
    blockFields(fn () => mixedFields());

    $this->post(BLOCKS, ['title' => 'New', 'subtitle' => 'x', 'body' => 'y', 'views' => 3, 'status' => 'live'])->assertRedirect();

    expect(Post::first()->only(['title', 'subtitle', 'views', 'status']))->toBe(['title' => 'New', 'subtitle' => 'x', 'views' => 3, 'status' => 'live']);
});

it('serializes the layout tree on detail, edit and create', function () {
    blockFields(fn () => mixedFields());
    $post = blockPost();

    $panel = ['type' => 'panel', 'description' => null, 'collapsible' => false, 'collapsed' => false, 'columns' => 1];
    $tree = [
        ['key' => 'p0', 'name' => null, 'fields' => ['id', 'title']] + $panel,
        ['key' => 'p1', 'name' => 'Main', 'description' => 'Desc', 'collapsible' => true, 'columns' => 2, 'fields' => ['subtitle', 'body']] + $panel,
        ['type' => 'tabs', 'key' => 't2', 'name' => 'Details', 'tabs' => [
            ['key' => 't2-0', 'name' => 'SEO', 'fields' => ['views']] + $panel,
            ['key' => 't2-1', 'name' => 'Misc', 'fields' => ['status']] + $panel,
        ]],
    ];

    $this->get(BLOCKS.'/'.$post->id)->assertInertia(fn (Assert $page) => $page->where('record.layout', $tree));

    // The ID field is not on the create and edit forms.
    $form = $tree;
    $form[0]['fields'] = ['title'];
    $this->get(BLOCKS.'/'.$post->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('record.layout', $form));
    $this->get(BLOCKS.'/create')->assertInertia(fn (Assert $page) => $page->where('layout', $form));
});

it('serializes fullWidth on fields', function () {
    blockFields(fn () => mixedFields());
    $post = blockPost();

    $this->get(BLOCKS.'/'.$post->id)->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.attribute', 'subtitle')
        ->where('record.fields.2.fullWidth', true)
        ->where('record.fields.1.fullWidth', false));
});

it('drops fields hidden per record and empty panels and tabs from the layout', function () {
    blockFields(fn () => [
        Text::make('Title'),
        Panel::make('Hidden', [Text::make('Subtitle')->showOnDetail(fn () => false)]),
        Tabs::make(null, [
            Panel::make('Gone', [Text::make('Body')->canSee(fn () => false)]),
            Panel::make('Kept', [Text::make('Status')]),
        ]),
        Tabs::make('Empty', [Panel::make('Nothing', [Text::make('Views')->hideFromDetail()])]),
    ]);
    $post = blockPost();

    $this->get(BLOCKS.'/'.$post->id)->assertInertia(fn (Assert $page) => $page
        ->has('record.layout', 2)
        ->where('record.layout.0.fields', ['title'])
        ->where('record.layout.1.type', 'tabs')
        ->has('record.layout.1.tabs', 1)
        ->where('record.layout.1.tabs.0.name', 'Kept'));
});

it('leaves loose relation fields out of the layout', function () {
    [$fields, $tree] = FieldLayout::flatten([Text::make('Title'), HasMany::make('Posts', 'posts', ConfigurablePost::class)]);

    expect(FieldLayout::serialize($tree, collect($fields)))->toHaveCount(1)
        ->and(FieldLayout::serialize($tree, collect($fields))[0]['fields'])->toBe(['title']);
});

it('rejects invalid blocks', function (Closure $fields) {
    blockFields($fields);

    blockEntity()->fieldPrototypes(request());
})->throws(LogicException::class)->with([
    'relation in panel' => [fn () => [Panel::make('P', [HasMany::make('Posts', 'posts', ConfigurablePost::class)])]],
    'duplicate attribute' => [fn () => [Text::make('Title'), Panel::make('P', [Text::make('Title')])]],
    'duplicate loose attribute' => [fn () => [Text::make('Title'), Text::make('Title')]],
    'nested panel' => [fn () => [Panel::make('P', [Panel::make('Q', [])])]],
    'nested tabs' => [fn () => [Panel::make('P', [Tabs::make('T', [Panel::make('Q', [])])])]],
    'bad item' => [fn () => [Text::make('Title'), 'oops']],
]);

it('rejects bad block definitions', function () {
    expect(fn () => Panel::make('P', [])->columns(3))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Tabs::make('T', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Tabs::make('T', [Text::make('Title')]))->toThrow(LogicException::class);
});

it('treats collapsed as collapsible', function () {
    expect(Panel::make('P', [])->collapsed()->toNode('p0', []))->toMatchArray(['collapsible' => true, 'collapsed' => true]);
});

it('keeps the index row shape without a layout', function () {
    blockFields(fn () => mixedFields());
    blockPost();

    $this->get(BLOCKS)->assertInertia(fn (Assert $page) => $page
        ->missing('records.data.0.layout')
        ->where('records.data.0.fields.0.attribute', 'id'));
});

it('builds fields once per request', function () {
    $calls = 0;
    blockFields(function () use (&$calls) {
        $calls++;

        return mixedFields();
    });

    $request = request();
    blockEntity()->fieldLayout($request);
    blockEntity()->fieldPrototypes($request);
    blockEntity()->fieldLayout($request);

    expect($calls)->toBe(1);
});

it('does not add queries to a detail page with a BelongsTo inside a panel', function () {
    Larsadmin::entities([ConfigurablePost::class, VisibleUser::class]);
    $author = fn () => BelongsTo::make('Author', 'author', VisibleUser::class);

    $measure = function (Closure $fields): int {
        blockFields($fields);
        $post = Post::create(['title' => 'T', 'status' => 'draft', 'author_id' => User::first()->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(BLOCKS.'/'.$post->id)->assertOk();

        return count(DB::getQueryLog());
    };

    $flat = $measure(fn () => [Text::make('Title'), $author()]);
    $blocks = $measure(fn () => [Panel::make('P', [Text::make('Title'), $author()])]);

    expect($blocks)->toBe($flat);
});
