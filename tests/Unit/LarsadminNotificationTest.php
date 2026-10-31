<?php

use Illuminate\Support\Facades\Notification;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Maxcxam\Larsadmin\Support\PanelUrl;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;

it('builds the stored data with defaults', function () {
    $notification = LarsadminNotification::make('  Import finished  ');

    expect($notification->toArray(new NotifiableUser))->toBe([
        'v' => 1,
        'title' => 'Import finished',
        'body' => null,
        'level' => 'info',
        'icon' => null,
        'url' => null,
        'urlLabel' => null,
        'run' => null,
    ])
        ->and($notification->via(new NotifiableUser))->toBe(['database'])
        ->and($notification->databaseType(new NotifiableUser))->toBe('larsadmin');
});

it('sets the body, level, icon and url fluently', function () {
    $data = LarsadminNotification::make('Done')
        ->title('Import done')
        ->body("Added 120 products.\nSkipped 2.")
        ->success()
        ->icon('check')
        ->url('/admin/entities/products', 'View')
        ->toArray(new NotifiableUser);

    expect($data)->toMatchArray([
        'title' => 'Import done',
        'body' => "Added 120 products.\nSkipped 2.",
        'level' => 'success',
        'icon' => 'check',
        'url' => '/admin/entities/products',
        'urlLabel' => 'View',
    ]);
});

it('switches between the levels', function (string $method) {
    expect(LarsadminNotification::make('x')->{$method}()->toArray(new NotifiableUser)['level'])->toBe($method);
})->with(['info' => ['info'], 'success' => ['success'], 'warning' => ['warning'], 'danger' => ['danger']]);

it('cuts the title, body and label to their limits (multibyte)', function () {
    $data = LarsadminNotification::make(str_repeat('ї', 250))
        ->body(str_repeat('є', 2500))
        ->url('/admin', str_repeat('ґ', 300))
        ->toArray(new NotifiableUser);

    expect(mb_strlen($data['title']))->toBe(200)
        ->and(mb_strlen($data['body']))->toBe(2000)
        ->and(mb_strlen($data['urlLabel']))->toBe(200);
});

it('keeps an empty body, label or url as null', function () {
    $data = LarsadminNotification::make('x')->body('   ')->url(null, '  ')->icon(null)->toArray(new NotifiableUser);

    expect($data['body'])->toBeNull()->and($data['urlLabel'])->toBeNull()->and($data['url'])->toBeNull()->and($data['icon'])->toBeNull();
});

it('rejects an empty title, an unknown level and an unknown icon', function (Closure $build) {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'empty title' => [fn () => LarsadminNotification::make('   ')],
    'level' => [fn () => LarsadminNotification::make('x')->level('critical')],
    'icon' => [fn () => LarsadminNotification::make('x')->icon('skull')],
]);

it('accepts panel-internal urls only', function (string $url, bool $accepted) {
    config(['app.url' => 'https://app.example.com', 'larsadmin.domain' => 'admin.example.com']);

    $build = fn () => LarsadminNotification::make('x')->url($url);

    if ($accepted) {
        expect($build()->toArray(new NotifiableUser)['url'])->toBe($url);
    } else {
        expect($build)->toThrow(InvalidArgumentException::class);
    }
})->with([
    'relative' => ['/admin/entities/posts?x=1', true],
    'request host' => ['http://localhost/admin', true],
    'app url host' => ['https://app.example.com/admin', true],
    'panel domain' => ['https://admin.example.com/', true],
    'protocol relative' => ['//evil.com/x', false],
    'backslash' => ['/\\evil.com', false],
    'javascript' => ['javascript:alert(1)', false],
    'other host' => ['https://evil.com/admin', false],
    'credentials' => ['https://user@localhost/admin', false],
    'whitespace' => ['/admin x', false],
    'relative without slash' => ['admin', false],
]);

it('links run notifications by id', function () {
    $data = LarsadminNotification::make('x')->url('/admin')->forRun('01jc0000000000000000000000', 'Open')->toArray(new NotifiableUser);

    expect($data['run'])->toBe('01jc0000000000000000000000')->and($data['url'])->toBeNull()->and($data['urlLabel'])->toBe('Open');
});

it('sends to notifiables through the facade', function () {
    Notification::fake();
    $user = NotifiableUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);

    LarsadminNotification::make('Hello')->warning()->sendTo($user);

    Notification::assertSentTo($user, LarsadminNotification::class, fn (LarsadminNotification $n) => $n->toArray($user)['level'] === 'warning');
});

it('guards action responses with the same rule as before', function () {
    expect(ActionResponse::redirect('/admin/x')->url)->toBe('/admin/x')
        ->and(ActionResponse::redirect('http://localhost/admin')->url)->toBe('http://localhost/admin')
        ->and(fn () => ActionResponse::redirect('https://evil.com'))->toThrow(InvalidArgumentException::class)
        ->and(PanelUrl::isSafe(null, 'localhost'))->toBeFalse()
        ->and(PanelUrl::isSafe('https:///nohost', 'localhost'))->toBeFalse()
        ->and(PanelUrl::guard('https://LOCALHOST/a', ['', 'localhost']))->toBe('https://LOCALHOST/a');
});

it('builds file responses with a safe path and a configured disk', function () {
    $file = ActionResponse::file('local', 'exports/posts.csv');

    expect($file->type)->toBe(ActionResponse::FILE)
        ->and($file->disk)->toBe('local')
        ->and($file->path)->toBe('exports/posts.csv')
        ->and($file->filename)->toBe('posts.csv')
        ->and(ActionResponse::file('local', 'a/b.csv', "re\x01port/.csv")->filename)->toBe('report.csv')
        ->and(ActionResponse::file('local', 'a/b.csv', "\x01")->filename)->toBe('b.csv')
        ->and(fn () => ActionResponse::file('local', '../secret'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ActionResponse::file('local', '/etc/passwd'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ActionResponse::file('missing-disk', 'a.csv'))->toThrow(InvalidArgumentException::class);
});
