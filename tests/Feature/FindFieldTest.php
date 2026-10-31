<?php

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\ExtensionHooks\HookedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\ExtensionHooks\TagIds;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    HookedPost::reset();
    Larsadmin::entities([HookedPost::class]);

    $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->request = Request::create('/admin/ext/acme/x');
    $this->request->setUserResolver(fn () => $this->user);

    $this->draft = Post::create(['title' => 'Draft', 'status' => 'draft']);
    $this->live = Post::create(['title' => 'Live', 'status' => 'live']);
});

/**
 * The HTTP status findField() aborts with, or 200.
 */
function findFieldStatus(Request $request, string $entity, Post $record, string $attribute, string $context = Field::UPDATE): int
{
    try {
        Larsadmin::findField($request, $entity, $record, $attribute, $context);

        return 200;
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }
}

it('returns a bound clone of the field', function () {
    $field = Larsadmin::findField($this->request, 'hooked-posts', $this->draft, 'tag_ids');
    $again = Larsadmin::findField($this->request, 'hooked-posts', $this->draft, 'tag_ids');

    expect($field)->toBeInstanceOf(TagIds::class)
        ->and($field->boundTo)->toBe(HookedPost::class)
        ->and($field)->not->toBe($again);

    $field->resolve($this->draft);

    expect($again->value)->toBeNull();
});

it('answers by context and record', function (string $attribute, string $context, string $record, int $status) {
    expect(findFieldStatus($this->request, 'hooked-posts', $this->{$record}, $attribute, $context))->toBe($status);
})->with([
    'editable on update' => ['title', Field::UPDATE, 'draft', 200],
    'editable on create' => ['title', Field::CREATE, 'draft', 200],
    'read-only on update' => ['subtitle', Field::UPDATE, 'draft', 403],
    'read-only on detail' => ['subtitle', Field::DETAIL, 'draft', 200],
    'hidden on update' => ['body', Field::UPDATE, 'draft', 404],
    'shown on create' => ['body', Field::CREATE, 'draft', 200],
    'invisible' => ['views', Field::DETAIL, 'draft', 404],
    'unknown attribute' => ['missing', Field::UPDATE, 'draft', 404],
    'per record hidden' => ['published_at', Field::DETAIL, 'draft', 404],
    'per record shown' => ['published_at', Field::DETAIL, 'live', 200],
    'index' => ['title', Field::INDEX, 'draft', 200],
]);

it('answers 404 for an unknown entity', function () {
    expect(findFieldStatus($this->request, 'nope', $this->draft, 'title'))->toBe(404);
});

it('refuses an unknown context', function () {
    Larsadmin::findField($this->request, 'hooked-posts', $this->draft, 'title', 'edit');
})->throws(InvalidArgumentException::class, 'Unknown field context');
