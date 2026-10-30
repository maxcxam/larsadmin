<?php

namespace Maxcxam\Larsadmin\Files;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Builds the URLs of the files of one resolved field (ADR-016): public files get the disk URL,
 * private files a temporary URL of a non-local disk that provides them (S3), otherwise a relative
 * signed package route that re-authorizes on use. Never touches the disk contents.
 *
 * @internal
 */
class FileUrls
{
    /**
     * Whether a URL that expires was issued.
     */
    protected bool $expiring = false;

    public function __construct(
        protected string $disk,
        protected bool $public,
        protected string $entity,
        protected int|string $recordKey,
        protected string $attribute,
        protected DateTimeInterface $expires,
    ) {}

    /**
     * The URL opening the file (inline for raster images) and the URL downloading it.
     *
     * @return array{url: string, downloadUrl: string}
     */
    public function for(StoredFile $file): array
    {
        if ($this->public) {
            $url = Storage::disk($this->disk)->url($file->path);

            return ['url' => $url, 'downloadUrl' => $url];
        }

        $this->expiring = true;
        $download = $this->url($file, true);

        return ['url' => $file->isRasterImage() ? $this->url($file, false) : $download, 'downloadUrl' => $download];
    }

    /**
     * The expiry of the issued URLs, or null when none expires.
     */
    public function expiresAt(): ?DateTimeInterface
    {
        return $this->expiring ? $this->expires : null;
    }

    protected function url(StoredFile $file, bool $download): string
    {
        $storage = Storage::disk($this->disk);

        if (config("filesystems.disks.{$this->disk}.driver") !== 'local' && $storage->providesTemporaryUrls()) {
            $inline = ! $download && $file->isRasterImage();

            return $storage->temporaryUrl($file->path, $this->expires, [
                'ResponseContentType' => $inline ? FileTypes::safeContentType($file->extension()) : 'application/octet-stream',
                'ResponseCacheControl' => FileResponse::cacheControl($inline),
                'ResponseContentDisposition' => HeaderUtils::makeDisposition(
                    $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                    $file->name,
                    FileTypes::asciiName($file->name),
                ),
            ]);
        }

        return URL::temporarySignedRoute('larsadmin.files.show', $this->expires, array_filter([
            'entity' => $this->entity,
            'id' => $this->recordKey,
            'field' => $this->attribute,
            'token' => $file->token($this->disk),
            'download' => $download ? 1 : null,
        ], fn ($value) => $value !== null), false);
    }
}
