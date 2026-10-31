<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\In;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Testing\TestResponse;
use Maxcxam\Larsadmin\Dashboards\Main;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Image;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Mcp\Introspection\FieldMetadata;
use Maxcxam\Larsadmin\Mcp\Introspection\Metadata;
use Maxcxam\Larsadmin\Mcp\LarsadminServer;
use Maxcxam\Larsadmin\Mcp\Tools\ApplicationInfo;
use Maxcxam\Larsadmin\Mcp\Tools\DescribeEntity;
use Maxcxam\Larsadmin\Mcp\Tools\ListDashboards;
use Maxcxam\Larsadmin\Mcp\Tools\ListEntities;
use Maxcxam\Larsadmin\Mcp\Tools\ListExtensions;
use Maxcxam\Larsadmin\Rules\RelatableExists;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\FixtureExtensions;
use Maxcxam\Larsadmin\Tests\Fixtures\Fields\Rating;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\BrokenDashboard;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\BrokenEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\CanaryDashboard;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\CanaryDataCard;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\CanaryOptionsFilter;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\CanaryTool;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\CanaryValue;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\IntrospectedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\IntrospectedTag;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\IntrospectedUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\McpResult;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\McpSpy;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\PublishPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\UserBoundEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Tools\Backups;

beforeEach(function () {
    $this->app->register(McpServiceProvider::class);

    app()['env'] = 'local';
    config(['larsadmin.mcp.enabled' => null]);

    McpSpy::reset();
    Gate::policy(Post::class, PostPolicy::class);

    Larsadmin::entities([IntrospectedPost::class, IntrospectedUser::class, IntrospectedTag::class, BrokenEntity::class, UserBoundEntity::class]);
    Larsadmin::dashboards([CanaryDashboard::class, BrokenDashboard::class]);
    Larsadmin::tools([CanaryTool::class, Backups::class]);
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist')->translations('acme-demo', 'ui');
});

/**
 * The JSON result of a tool response (version-neutral: the text content, checked against structured content).
 *
 * @return array<string, mixed>
 */
function mcpStructured(TestResponse $response): array
{
    return McpResult::json($response);
}

it('registers the five introspection tools as read-only and idempotent', function () {
    $tools = [ApplicationInfo::class, ListEntities::class, DescribeEntity::class, ListDashboards::class, ListExtensions::class];

    expect(array_intersect($tools, LarsadminServer::toolClasses()))->toBe($tools);

    foreach ($tools as $tool) {
        $annotations = app($tool)->toArray()['annotations'];

        expect($annotations['readOnlyHint'])->toBeTrue()
            ->and($annotations['idempotentHint'])->toBeTrue();
    }

    expect(app(DescribeEntity::class)->name())->toBe('describe-entity')
        ->and(app(DescribeEntity::class)->toArray()['inputSchema']['required'])->toBe(['entity']);
});

it('refuses to run and is not registered while the gate is closed', function () {
    app()['env'] = 'production';
    config(['larsadmin.mcp.enabled' => true]);

    expect(app(ListEntities::class)->shouldRegister())->toBeFalse();

    $response = LarsadminServer::tool(ListEntities::class);
    $response->assertHasErrors();

    // Called directly, handle() checks the gate itself.
    $direct = app(ListEntities::class)->handle(new Request([]));
    expect($direct->isError())->toBeTrue()
        ->and((string) json_encode($direct->content()->toArray()))->toContain('production');
});

it('describes the application from allow-listed config keys', function () {
    config([
        'larsadmin.path' => '/backoffice/',
        'larsadmin.guard' => 'admin',
        'larsadmin.locale' => 'en',
        'larsadmin.locales' => ['en' => 'English', 'uk' => 'Українська'],
        'larsadmin.timezone' => 'Europe/Kyiv',
        'larsadmin.domain' => 'admin.example.test',
        'larsadmin.audit.enabled' => true,
    ]);

    $info = mcpStructured(LarsadminServer::tool(ApplicationInfo::class)->assertOk()->assertHasNoErrors());

    expect($info)->toMatchArray([
        'apiVersion' => Larsadmin::API_VERSION,
        'mcp' => ['writesAllowed' => true],
        'counts' => ['entities' => 5, 'dashboards' => 2, 'tools' => 2, 'extensions' => 1],
    ])
        ->and($info['panel'])->toMatchArray([
            'path' => 'backoffice',
            'domainSet' => true,
            'guard' => 'admin',
            'locale' => 'en',
            'locales' => ['en', 'uk'],
            'timezone' => 'Europe/Kyiv',
        ])
        ->and($info['features'])->toMatchArray(['audit' => true, 'notifications' => false, 'realtime' => false, 'globalSearch' => true])
        ->and(json_encode($info))->not->toContain('admin.example.test');
});

it('lists the registered entities with counts', function () {
    $entities = collect(mcpStructured(LarsadminServer::tool(ListEntities::class)->assertOk())['entities'])->keyBy('key');

    expect($entities->keys()->all())->toBe(['introspected-posts', 'introspected-users', 'introspected-tags', 'broken-entities', 'user-bound-entities'])
        ->and($entities['introspected-posts'])->toMatchArray([
            'class' => IntrospectedPost::class,
            'model' => Post::class,
            'label' => 'Introspected Posts',
            'singularLabel' => 'Introspected Post',
            'group' => 'Content',
            'inNavigation' => true,
            'globallySearchable' => true,
            'auditable' => true,
            'counts' => ['fields' => 11, 'actions' => 1, 'filters' => 5, 'cards' => 2],
        ])
        ->and($entities['introspected-users']['inNavigation'])->toBeFalse()
        ->and($entities['broken-entities']['counts'])->toBe(['fields' => null, 'actions' => null, 'filters' => 0, 'cards' => 0])
        ->and($entities['broken-entities']['unavailable'])->toBe([
            ['unavailable' => 'fields', 'reason' => 'RuntimeException without an authenticated user'],
            ['unavailable' => 'actions', 'reason' => 'RuntimeException without an authenticated user'],
        ])
        ->and($entities['user-bound-entities']['unavailable'][0]['reason'])->toBe('ErrorException without an authenticated user');
});

it('describes an entity by uriKey or class', function () {
    $byKey = mcpStructured(LarsadminServer::tool(DescribeEntity::class, ['entity' => 'introspected-posts'])->assertOk());
    $byClass = mcpStructured(LarsadminServer::tool(DescribeEntity::class, ['entity' => '\\'.IntrospectedPost::class])->assertOk());

    expect($byClass)->toBe($byKey)
        ->and($byKey['statics'])->toBe([
            'title' => 'title', 'search' => ['id', 'title'], 'with' => ['author'], 'sort' => ['id' => 'desc'],
            'perPage' => 25, 'perPageOptions' => [25, 50, 100], 'group' => 'Content', 'inNavigation' => true,
            'globallySearchable' => true, 'subtitle' => 'status', 'auditable' => true,
        ])
        ->and($byKey['scoping'])->toBe(['indexQuery' => 'overridden', 'detailQuery' => 'inherited', 'relatableQuery' => 'inherited'])
        ->and($byKey['policy']['class'])->toBe(PostPolicy::class)
        ->and($byKey['truncated'])->toBeFalse();
});

it('describes fields with flags, rule summaries, relations and option counts', function () {
    $fields = collect(mcpStructured(LarsadminServer::tool(DescribeEntity::class, ['entity' => 'introspected-posts']))['fields'])->keyBy('attribute');

    expect($fields->keys()->all())->toBe(['id', 'title', 'body', 'views', 'views_score', 'status', 'subtitle', 'published', 'published_at', 'author', 'tags'])
        ->and($fields['title'])->toMatchArray([
            'name' => 'Title',
            'type' => 'Maxcxam\\Larsadmin\\Fields\\Text',
            'component' => 'text-field',
            'contexts' => ['index' => true, 'detail' => true, 'create' => true, 'update' => true],
            'readonly' => false,
            'canSee' => false,
            'sortable' => true,
            'sensitive' => false,
            'rules' => [
                'create' => ['nullable', 'string', 'required', 'max:255', 'in', 'Illuminate\\Validation\\Rules\\In', 'closure'],
                'update' => ['nullable', 'string', 'required', 'max:255', 'in', 'Illuminate\\Validation\\Rules\\In', 'closure', 'unique', 'regex'],
            ],
            'rulesComputed' => true,
            'declaredRules' => [
                'create' => ['required', 'max:255', 'in', 'Illuminate\\Validation\\Rules\\In', 'closure'],
                'update' => ['required', 'max:255', 'in', 'Illuminate\\Validation\\Rules\\In', 'closure', 'unique', 'regex'],
            ],
            'relation' => null,
            'options' => null,
            'extension' => null,
        ])
        ->and($fields['body'])->toMatchArray([
            'contexts' => ['index' => 'closure', 'detail' => true, 'create' => true, 'update' => true],
            'readonly' => 'closure',
            'nullable' => true,
            'sensitive' => true,
        ])
        ->and($fields['views']['rules']['create'])->toBe(['numeric', 'min:0', 'max:1000', 'between:1,5', 'date_format:Y-m-d', 'in', 'not_in'])
        ->and($fields['views_score'])->toMatchArray(['type' => Rating::class, 'component' => 'acme/rating', 'extension' => 'acme/rating'])
        // An extension field's own typeRules() is host code: not run, so the complete rules are unknown.
        ->and($fields['views_score'])->toMatchArray(['rules' => null, 'rulesComputed' => false, 'declaredRules' => ['create' => [], 'update' => []]])
        ->and($fields['status']['options'])->toBe(['source' => 'array', 'count' => 3])
        // Select's implicit "in" rule is built from its options: not computed.
        ->and($fields['status'])->toMatchArray(['rules' => null, 'rulesComputed' => false, 'declaredRules' => ['create' => [], 'update' => []]])
        ->and($fields['subtitle'])->toMatchArray(['canSee' => true, 'options' => ['source' => 'closure', 'count' => null]])
        ->and($fields['published_at']['contexts'])->toBe(['index' => false, 'detail' => true, 'create' => false, 'update' => false])
        ->and($fields['author'])->toMatchArray([
            'relation' => ['type' => 'belongsTo', 'relationship' => 'author', 'entity' => IntrospectedUser::class],
            'rules' => ['create' => ['nullable', RelatableExists::class], 'update' => ['nullable', RelatableExists::class]],
            'rulesComputed' => true,
            'declaredRules' => ['create' => [], 'update' => []],
        ])
        ->and($fields['tags'])->toMatchArray([
            'relation' => ['type' => 'belongsToMany', 'relationship' => 'tags', 'entity' => IntrospectedTag::class],
            'contexts' => ['index' => false, 'detail' => true, 'create' => false, 'update' => false],
            'rules' => null,
            'rulesComputed' => false,
            'declaredRules' => null,
        ]);

    // A relation panel without an explicit entity is resolved from the relation's model.
    $users = collect(mcpStructured(LarsadminServer::tool(DescribeEntity::class, ['entity' => 'introspected-users']))['fields'])->keyBy('attribute');

    expect($users['posts']['relation'])->toBe(['type' => 'hasMany', 'relationship' => 'posts', 'entity' => IntrospectedPost::class])
        ->and($users['email']['rules']['update'])->toBe(['nullable', 'string', 'required', 'email', 'unique'])
        ->and($users['password'])->toMatchArray(['sensitive' => true, 'contexts' => ['index' => false, 'detail' => false, 'create' => true, 'update' => true]]);
});

it('describes the layout, actions, filters and cards', function () {
    $entity = mcpStructured(LarsadminServer::tool(DescribeEntity::class, ['entity' => 'introspected-posts']));

    expect($entity['layout'])->toBe([
        ['type' => 'panel', 'name' => null, 'columns' => 1, 'collapsible' => false, 'fields' => ['id']],
        ['type' => 'panel', 'name' => 'Main', 'columns' => 1, 'collapsible' => false, 'fields' => ['title', 'body']],
        ['type' => 'tabs', 'name' => 'More', 'tabs' => [
            ['type' => 'panel', 'name' => 'Numbers', 'columns' => 1, 'collapsible' => false, 'fields' => ['views', 'views_score']],
        ]],
        ['type' => 'panel', 'name' => null, 'columns' => 1, 'collapsible' => false, 'fields' => ['status', 'subtitle', 'published', 'published_at', 'author', 'tags']],
    ]);

    $action = $entity['actions'][0];

    expect($action)->toMatchArray([
        'uriKey' => 'publish-post',
        'class' => PublishPost::class,
        'name' => 'Publish Post',
        'destructive' => true,
        'standalone' => false,
        'queued' => true,
        'showOn' => ['index', 'detail', 'inline'],
        'canSee' => 'closure',
        'canRun' => 'closure',
    ])
        ->and(array_column($action['fields'], 'attribute'))->toBe(['channel', 'note'])
        ->and($action['fields'][0]['options'])->toBe(['source' => 'closure', 'count' => null])
        ->and($action['fields'][0])->toMatchArray(['rules' => null, 'rulesComputed' => false, 'declaredRules' => ['create' => ['required'], 'update' => ['required']]])
        ->and($action['fields'][1]['rules']['create'])->toBe(['nullable', 'string']);

    expect(collect($entity['filters'])->map(fn ($filter) => [$filter['key'], $filter['component'], $filter['canSee'], $filter['options']])->all())->toBe([
        ['status', 'select-filter', 'default', ['source' => 'array', 'count' => 2]],
        ['author', 'select-filter', 'closure', ['source' => 'closure', 'count' => null]],
        ['published', 'boolean-filter', 'default', ['source' => 'builtin', 'count' => 2]],
        ['published-range', 'date-range-filter', 'default', null],
        ['canary-options-filter', 'select-filter', 'default', ['source' => 'method', 'count' => null]],
    ])
        ->and($entity['filters'][4]['class'])->toBe(CanaryOptionsFilter::class);

    expect($entity['cards'])->toBe([
        [
            'uriKey' => 'canary-value', 'class' => CanaryValue::class, 'file' => null, 'name' => 'Canary Value',
            'type' => 'value', 'component' => 'value-metric', 'width' => '1/2', 'canSee' => true,
            'ranges' => [30, 60, 365, 'TODAY', 'MTD', 'QTD', 'YTD'], 'cacheFor' => 300,
        ],
        [
            'uriKey' => 'canary-data-card', 'class' => CanaryDataCard::class, 'file' => null, 'name' => 'Canary Data Card',
            'type' => 'data', 'component' => 'acme/canary', 'width' => 'full', 'canSee' => false, 'cacheFor' => null,
        ],
    ]);

    expect(McpSpy::$calls)->toBe([]);
});

it('reports static warnings', function () {
    config(['larsadmin.allow_without_policy' => false]);

    $tags = mcpStructured(LarsadminServer::tool(DescribeEntity::class, ['entity' => 'introspected-tags']));

    expect($tags['policy'])->toBe(['class' => null, 'file' => null])
        ->and($tags['warnings'])->toBe([
            'No policy is registered for Maxcxam\\Larsadmin\\Tests\\Fixtures\\Tag: every ability is denied (fail closed).',
            'The entity is globally searchable but $search is empty: the global search finds nothing.',
        ]);

    $posts = mcpStructured(LarsadminServer::tool(DescribeEntity::class, ['entity' => 'introspected-posts']));

    expect($posts['warnings'])->toBe([]);
});

it('reports a failing fields() as unavailable with the exception class only', function () {
    $broken = mcpStructured(LarsadminServer::tool(DescribeEntity::class, ['entity' => 'broken-entities'])->assertOk());

    expect($broken['fields'])->toBeNull()
        ->and($broken['actions'])->toBeNull()
        ->and($broken['filters'])->toBe([])
        ->and($broken['unavailable'])->toBe([
            ['unavailable' => 'fields', 'reason' => 'RuntimeException without an authenticated user'],
            ['unavailable' => 'actions', 'reason' => 'RuntimeException without an authenticated user'],
        ]);
});

it('rejects an unknown entity with the list of registered ones', function (mixed $entity) {
    LarsadminServer::tool(DescribeEntity::class, ['entity' => $entity])
        ->assertHasErrors(['Unknown entity: pass the uriKey or class of a registered entity. Registered: introspected-posts, introspected-users, introspected-tags, broken-entities, user-bound-entities.']);
})->with([
    'unknown key' => ['nope'],
    'unregistered class' => [Main::class],
    'empty' => [''],
    'not a string' => [['introspected-posts']],
    'too long' => [str_repeat('a', 300)],
]);

it('lists the dashboards with their cards', function () {
    $dashboards = mcpStructured(LarsadminServer::tool(ListDashboards::class)->assertOk())['dashboards'];

    expect($dashboards[0])->toMatchArray([
        'key' => 'canary',
        'class' => CanaryDashboard::class,
        'name' => 'Canary Dashboard',
        'inNavigation' => true,
        'isHome' => true,
        'canSee' => 'default',
    ])
        ->and(array_column($dashboards[0]['cards'], 'type'))->toBe(['value', 'data'])
        ->and($dashboards[0]['cards'][0]['canSee'])->toBeTrue()
        ->and($dashboards[1])->toMatchArray([
            'key' => 'broken-dashboard',
            'inNavigation' => false,
            'isHome' => false,
            'cards' => null,
            'unavailable' => [['unavailable' => 'cards', 'reason' => 'RuntimeException without an authenticated user']],
        ]);

    expect(McpSpy::$calls)->toBe([]);
});

it('lists the main dashboard when none is registered', function () {
    Larsadmin::dashboards([]);

    $dashboards = mcpStructured(LarsadminServer::tool(ListDashboards::class))['dashboards'];

    expect($dashboards)->toHaveCount(1)
        ->and($dashboards[0])->toMatchArray(['key' => 'main', 'class' => Main::class, 'isHome' => true, 'cards' => []]);
});

it('lists the extensions and tools', function () {
    Larsadmin::extension('acme/rating', __DIR__.'/../../Fixtures/Mcp/rating-extension/dist');

    $listed = mcpStructured(LarsadminServer::tool(ListExtensions::class)->assertOk());

    expect($listed['extensions'])->toHaveCount(2)
        ->and($listed['extensions'][0])->toMatchArray([
            'name' => 'acme/demo',
            'script' => 'extension.js',
            'style' => 'extension.css',
            'translations' => ['acme-demo' => 'ui'],
            'package' => null,
        ])
        // The PSR-4 namespaces from the composer.json above the registered directory (invalid names dropped).
        ->and($listed['extensions'][1])->toMatchArray([
            'name' => 'acme/rating',
            'style' => null,
            'package' => ['name' => 'acme/rating', 'namespaces' => ['Acme\\Rating\\'], 'source' => 'composer.json'],
        ])
        ->and($listed['extensions'][0]['version'])->toMatch('/^[0-9a-f]{12}$/')
        ->and($listed['tools'])->toBe([
            ['uriKey' => 'canary-tool', 'class' => CanaryTool::class, 'file' => null, 'name' => 'Canary Tool', 'component' => 'acme/canary-tool', 'icon' => 'database', 'canSee' => 'default'],
            ['uriKey' => 'backups', 'class' => Backups::class, 'file' => null, 'name' => 'Backups', 'component' => 'acme/backups', 'icon' => 'database', 'canSee' => 'default'],
        ]);

    expect(McpSpy::$calls)->toBe([]);
});

it('reports fields that upload files as multipart', function (Field $field, bool $multipart) {
    expect(FieldMetadata::describe($field)['multipart'])->toBe($multipart);
})->with([
    'file' => [fn () => File::make('Attachment'), true],
    'image' => [fn () => Image::make('Cover'), true],
    'multipart extension field' => [fn () => Rating::make('Score')->multipart(), true],
    'text' => [fn () => Text::make('Title'), false],
]);

it('computes the complete rules of core fields only', function (Field $field, ?array $rules) {
    $described = FieldMetadata::describe($field);

    expect($described['rules'])->toBe($rules === null ? null : ['create' => $rules, 'update' => $rules])
        ->and($described['rulesComputed'])->toBe($rules !== null)
        ->and($described['declaredRules'])->toBe(['create' => ['required'], 'update' => ['required']]);
})->with([
    'text' => [fn () => Text::make('Title')->rules('required'), ['nullable', 'string', 'required']],
    'id' => [fn () => ID::make()->rules('required'), ['required']],
    // File::rulesFor() adds size and count rules of its own.
    'file' => [fn () => File::make('Attachment')->rules('required'), null],
    'extension field' => [fn () => Rating::make('Score')->rules('required'), null],
    'select' => [fn () => Select::make('Status')->options(['a' => 'A'])->rules('required'), null],
]);

it('summarizes rules without data-like parameters', function (mixed $rule, string $summary) {
    expect(Metadata::rule($rule))->toBe($summary);
})->with([
    'plain' => ['required', 'required'],
    'numeric' => ['max:255', 'max:255'],
    'numeric list' => ['between:1,5.5', 'between:1,5.5'],
    'date format' => ['date_format:Y-m-d', 'date_format:Y-m-d'],
    'mimes' => ['mimes:jpg,png', 'mimes:jpg,png'],
    'in' => ['in:a,b', 'in'],
    'in with ids' => ['in:3,17,42', 'in'],
    'not_in with ids' => ['not_in:3', 'not_in'],
    'exists with id' => ['exists:7', 'exists'],
    'size' => ['size:10', 'size'.':10'],
    'exists' => ['exists:users,id', 'exists'],
    'unique with id' => ['unique:users,email,{{id}}', 'unique'],
    'regex' => ['regex:/^[a-z]+$/', 'regex'],
    'required_if' => ['required_if:status,secret', 'required_if'],
    'not a name' => ['a b:c', 'custom'],
    'closure' => [fn () => true, 'closure'],
    'rule object' => [new In(['x']), 'Illuminate\\Validation\\Rules\\In'],
    'anonymous rule' => [new class {}, 'class@anonymous'],
    'array' => [['x'], 'array'],
]);

it('returns files relative to the base path or the vendor directory, never absolute', function () {
    expect(Metadata::relativePath(base_path('composer.json')))->toBe('composer.json')
        ->and(Metadata::relativePath(__DIR__.'/../../../vendor/laravel/mcp/composer.json'))->toBe('vendor/laravel/mcp/composer.json')
        ->and(Metadata::relativePath(__FILE__))->toBeNull()
        ->and(Metadata::relativePath(__DIR__.'/missing.php'))->toBeNull();
});

it('truncates long lists to the response limit', function () {
    $payload = Metadata::fit(['items' => array_fill(0, 2000, str_repeat('x', 100))], ['items']);

    expect($payload['truncated'])->toBeTrue()
        ->and(strlen(json_encode($payload)))->toBeLessThanOrEqual(Metadata::MAX_LENGTH)
        ->and(count($payload['items']))->toBeGreaterThan(400);

    expect(Metadata::fit(['items' => [1, 2]], ['items']))->toBe(['items' => [1, 2], 'truncated' => false]);
});
