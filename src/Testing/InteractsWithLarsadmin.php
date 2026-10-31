<?php

namespace Maxcxam\Larsadmin\Testing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Files\FileJournal;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Metrics\DataCard;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Test helpers for extension packages (Pest: uses(InteractsWithLarsadmin::class); PHPUnit with Testbench).
 * They only use the public API.
 *
 * @api stable since 1.5
 */
trait InteractsWithLarsadmin
{
    /**
     * Serialize a field as the panel sends it to a page in the given context: resolved from the record, or
     * from its default without one. With $entity (an Entity class or a registered uriKey) the field is first
     * bound to it with bindTo(), as the entity does when it builds its fields.
     *
     * @return array<string, mixed>
     */
    protected function serializeField(Field $field, ?Model $record = null, string $context = Field::DETAIL, ?string $entity = null): array
    {
        $field = clone $field;

        if ($entity !== null) {
            $class = is_subclass_of($entity, Entity::class) ? $entity : Larsadmin::entityForKey($entity);

            Assert::assertNotNull($class, "[{$entity}] is not an Entity class or a registered entity key.");

            $field->bindTo(request(), $class);
        }

        $field->context = $context;

        if ($record !== null && $context !== Field::CREATE) {
            $field->readonly = $field->isReadonlyFor(request(), $record);
            $field->resolve($record);
        } else {
            $field->resolveDefault(request());
        }

        return json_decode(json_encode($field, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Validate the input with the field's rules for the context, fill the record, save it and run the deferred
     * closure fillInto() returned, in a transaction with a file journal, as the panel does on create and update.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, UploadedFile|array<mixed>>  $files
     *
     * @throws ValidationException when the input fails the field's rules
     */
    protected function fillField(Field $field, Model $record, array $input, array $files = [], string $context = Field::UPDATE): Model
    {
        $request = Request::create('/', 'POST', $input, [], $files);

        Validator::make($request->all(), $field->rulesFor($context, $record), [], $field->validationAttributeNames())->validate();

        // As Entity::save(): fillInto() with a file journal, the save and the deferred closure in one transaction;
        // files the field registered as created are deleted on failure, obsolete ones after the commit.
        $journal = new FileJournal;
        $connection = $record->getConnection();

        try {
            $after = $field->fillInto($request, $record, $journal);

            $connection->transaction(function () use ($record, $after, $journal, $connection) {
                $connection->afterCommit(fn () => $journal->forgetCreated());

                $record->save();

                if ($after !== null) {
                    $after($record);
                }

                $connection->afterCommit(fn () => $journal->purgeObsolete());
            });
        } catch (Throwable $e) {
            $journal->discardCreated();

            throw $e;
        }

        return $record;
    }

    /**
     * The data of a data card for the query as the user (the acting user when null): canSee(), then rules(),
     * then data().
     *
     * @param  array<string, mixed>  $query
     *
     * @throws AuthorizationException when the card is not visible
     * @throws ValidationException when the query fails the card's rules
     */
    protected function cardData(DataCard $card, array $query = [], ?Authenticatable $user = null, string $owner = 'dashboard:test'): mixed
    {
        if ($user !== null) {
            $this->actingAs($user, Larsadmin::guard());
        }

        $request = Request::create('/', 'GET', $query);
        $request->setUserResolver(fn (?string $guard = null) => auth()->guard($guard)->user());

        if (! $card->authorizedToSee($request)) {
            throw new AuthorizationException('The card ['.$card->uriKey().'] is not visible to the user.');
        }

        return $card->data($card->cardRequest($request, $owner));
    }

    protected function assertExtensionRegistered(string $name): void
    {
        Assert::assertNotNull(Larsadmin::extensionFor($name), "The extension [{$name}] is not registered.");
    }

    /**
     * GET a file of a registered extension through its versioned URL, as the acting user.
     */
    protected function extensionAssetResponse(string $name, string $file): TestResponse
    {
        $extension = Larsadmin::extensionFor($name);

        Assert::assertNotNull($extension, "The extension [{$name}] is not registered.");

        return $this->get($extension->url($file));
    }
}
