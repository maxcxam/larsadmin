<?php

namespace Maxcxam\Larsadmin\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Maxcxam\Larsadmin\Fields\File;
use RuntimeException;

/**
 * Validates the set of files a File field ends up with: required means at least one, and a
 * multiple() field may hold at most maxFiles. Implicit, so it runs when the field is absent
 * (then the record's current files stay).
 *
 * @internal
 */
class FileFieldState implements ValidationRule
{
    /**
     * PHP upload errors caused by the server, not by the user's file: the user only sees
     * Laravel's generic "could not be uploaded" message, so they are reported for the developer.
     */
    public const array SERVER_UPLOAD_ERRORS = [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION];

    public bool $implicit = true;

    public function __construct(
        private readonly File $field,
        private readonly ?Model $record,
        private readonly bool $required,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A non-array value fails the "array" rule; nothing is stored either way.
        if ($value !== null && ! is_array($value)) {
            return;
        }

        $this->reportServerUploadErrors($attribute, $value);

        $count = $this->field->finalCount($this->record, $value);

        if ($this->required && $count === 0) {
            $fail('larsadmin::messages.file_required')->translate();
        } elseif ($count > $this->field->maxFileCount()) {
            $fail('larsadmin::messages.file_too_many')->translate(['max' => $this->field->maxFileCount()]);
        }
    }

    /**
     * @param  array<array-key, mixed>|null  $value
     */
    private function reportServerUploadErrors(string $attribute, ?array $value): void
    {
        $uploads = is_array($value['upload'] ?? null) ? $value['upload'] : [];

        foreach ($uploads as $upload) {
            if ($upload instanceof UploadedFile && in_array($upload->getError(), self::SERVER_UPLOAD_ERRORS, true)) {
                report(new RuntimeException(sprintf(
                    'PHP could not receive an upload for the file field [%s]: %s Check "upload_tmp_dir" and that the TEMP/TMP environment variables reach PHP (e.g. "php artisan serve" on Windows does not pass them to the server process).',
                    $attribute,
                    $upload->getErrorMessage(),
                )));

                return;
            }
        }
    }
}
