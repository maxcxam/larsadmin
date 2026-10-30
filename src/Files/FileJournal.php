<?php

namespace Maxcxam\Larsadmin\Files;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The files one save (or delete) wrote and made obsolete (ADR-017). Disks are not transactional:
 * written files are discarded when the save fails, obsolete ones purged after the commit.
 * Storage failures are reported, never thrown.
 *
 * @internal
 */
class FileJournal
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    protected array $created = [];

    /**
     * @var list<array{0: string, 1: string}>
     */
    protected array $obsolete = [];

    /**
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * Extra context (entity, record key) for reported storage failures.
     *
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): static
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    public function created(string $disk, string $path): void
    {
        $this->created[] = [$disk, $path];
    }

    public function obsolete(string $disk, string $path): void
    {
        $this->obsolete[] = [$disk, $path];
    }

    /**
     * Forget the files written by this request: the record referencing them was committed, so a
     * later failure must not discard them.
     */
    public function forgetCreated(): void
    {
        $this->created = [];
    }

    /**
     * Delete the files written by this request (the save failed).
     */
    public function discardCreated(): void
    {
        $files = $this->created;
        $this->created = [];

        $this->delete($files);
    }

    /**
     * Delete the files that are no longer referenced (after the commit).
     */
    public function purgeObsolete(): void
    {
        $files = $this->obsolete;
        $this->obsolete = [];

        $this->delete($files);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $files
     */
    protected function delete(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            try {
                $deleted = Storage::disk($disk)->delete($path);
                $error = null;
            } catch (Throwable $e) {
                $deleted = false;
                $error = $e;
            }

            if (! $deleted) {
                report($this->failure($disk, $path, $error));
            }
        }
    }

    protected function failure(string $disk, string $path, ?Throwable $previous): RuntimeException
    {
        $context = ['disk' => $disk, 'path' => $path] + $this->context;

        return new class("Larsadmin could not delete the file [{$path}] on the disk [{$disk}].", $context, $previous) extends RuntimeException
        {
            /**
             * @param  array<string, mixed>  $context
             */
            public function __construct(string $message, private readonly array $context, ?Throwable $previous)
            {
                parent::__construct($message, 0, $previous);
            }

            /**
             * @return array<string, mixed>
             */
            public function context(): array
            {
                return $this->context;
            }
        };
    }
}
