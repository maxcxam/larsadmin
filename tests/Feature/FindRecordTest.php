<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Post as PostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\ScopedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\NoUpdatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\UpdateOnlyPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    PostPolicy::$allowDelete = false;
    $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);

    $this->live = Post::create(['title' => 'Live', 'status' => 'live']);
    $this->draft = Post::create(['title' => 'Draft', 'status' => 'draft']);

    $this->request = Request::create('/admin/ext/x');
    $this->request->setUserResolver(fn () => $this->user);
});

afterEach(function () {
    PostPolicy::$allowDelete = false;
});

/**
 * The HTTP status findRecord() aborts with, or 200 when it returns the record.
 */
function findRecordStatus(Request $request, string $entity, int|string $id, string $ability = 'view'): int
{
    try {
        Larsadmin::findRecord($request, $entity, $id, $ability);

        return 200;
    } catch (HttpException $e) {
        return $e->getStatusCode();
    } catch (ModelNotFoundException) {
        return 404;
    }
}

it('finds a record through the entity', function () {
    Larsadmin::entities([PostEntity::class]);
    Gate::policy(Post::class, PostPolicy::class);

    $record = Larsadmin::findRecord($this->request, 'posts', $this->live->id);

    expect($record)->toBeInstanceOf(Post::class)
        ->and($record->is($this->live))->toBeTrue()
        ->and(Larsadmin::findRecord($this->request, 'posts', (string) $this->live->id, 'update')->is($this->live))->toBeTrue();
});

it('answers 404 for an unknown entity or record', function () {
    Larsadmin::entities([PostEntity::class]);

    expect(findRecordStatus($this->request, 'nope', $this->live->id))->toBe(404)
        ->and(findRecordStatus($this->request, 'posts', 999))->toBe(404);
});

it('answers 404 for a record outside the detail scope', function () {
    Larsadmin::entities([ScopedPost::class]);

    expect(findRecordStatus($this->request, 'scoped-posts', $this->draft->id))->toBe(404)
        ->and(findRecordStatus($this->request, 'scoped-posts', $this->live->id))->toBe(200);
});

it('answers 403 without viewAny before looking up the record', function () {
    Larsadmin::entities([PostEntity::class]);
    Gate::policy(Post::class, UpdateOnlyPolicy::class);

    // Also for a missing record: unauthorized users cannot probe for keys.
    expect(findRecordStatus($this->request, 'posts', $this->live->id, 'update'))->toBe(403)
        ->and(findRecordStatus($this->request, 'posts', 999, 'update'))->toBe(403);
});

it('answers 403 when the policy denies the ability on the record', function (string $ability, int $status) {
    Larsadmin::entities([PostEntity::class]);
    Gate::policy(Post::class, NoUpdatePolicy::class);

    expect(findRecordStatus($this->request, 'posts', $this->live->id, $ability))->toBe($status);
})->with([
    'view allowed' => ['view', 200],
    'update denied' => ['update', 403],
    'delete denied' => ['delete', 403],
]);

it('allows delete when the policy does', function () {
    Larsadmin::entities([PostEntity::class]);
    Gate::policy(Post::class, PostPolicy::class);
    PostPolicy::$allowDelete = true;

    expect(findRecordStatus($this->request, 'posts', $this->live->id, 'delete'))->toBe(200);
});

it('refuses other abilities', function () {
    Larsadmin::entities([PostEntity::class]);

    Larsadmin::findRecord($this->request, 'posts', $this->live->id, 'forceDelete');
})->throws(InvalidArgumentException::class, 'Unsupported ability');
