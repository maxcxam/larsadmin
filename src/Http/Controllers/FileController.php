<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Files\FileResponse;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves private files through relative signed URLs (ADR-016). The signature only bounds a link
 * in time: every request is re-authorized like the record's detail page (404 before 403 rules of
 * EntityController), and the file is found by its token among the record's current files, never
 * by a path from the request.
 */
class FileController
{
    public function show(Request $request, string $entity, string $id, string $field, string $token): StreamedResponse
    {
        /** @var class-string<Entity> $entity */
        $entity = Larsadmin::entityForKey($entity) ?? abort(404);

        abort_unless($entity::authorizedToViewAny($request), 403);

        $model = $entity::newModel();
        $integerKey = $model->getIncrementing() && in_array($model->getKeyType(), ['int', 'integer'], true);

        if ($integerKey && (! ctype_digit($id) || strlen($id) > 18)) {
            abort(404);
        }

        $record = EntityQuery::for($entity, $request)
            ->scope('detail')
            ->builder()
            ->where($model->getQualifiedKeyName(), $integerKey ? (int) $id : $id)
            ->first() ?? abort(404);

        $instance = $entity::make($record);

        abort_unless($instance->authorizedToView($request), 403);

        $prototype = $instance->fieldPrototypes($request)
            ->first(fn (Field $candidate) => $candidate instanceof File && $candidate->attribute === $field);

        abort_unless($prototype instanceof File && $this->visible($request, $instance, $prototype), 404);

        $file = collect($prototype->filesOf($record))
            ->first(fn (StoredFile $candidate) => hash_equals($candidate->token($prototype->diskName()), $token));

        $disk = Storage::disk($prototype->diskName());

        abort_if($file === null || ! $disk->exists($file->path), 404);

        return FileResponse::make(
            $disk,
            $file,
            $request->boolean('download'),
            max(1, (int) config('larsadmin.files.url_ttl', 30)),
        );
    }

    /**
     * The field must be shown to the user for the record in a context that displays its files:
     * detail, index, or the edit form when the user may update the record.
     */
    protected function visible(Request $request, Entity $instance, File $field): bool
    {
        return $field->isShownOn(Field::DETAIL, $request, $instance->record)
            || $field->isShownOn(Field::INDEX, $request, $instance->record)
            || ($field->isShownOn(Field::UPDATE, $request, $instance->record) && $instance->authorizedToUpdate($request));
    }
}
