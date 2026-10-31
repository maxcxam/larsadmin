<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Files\FileResponse;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Larsadmin;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The file of a synchronous ActionResponse::file() (ADR-029): an encrypted token holding the disk, path,
 * file name, the user it was made for and its expiry. Anything else answers 404.
 *
 * @internal Not part of the public API.
 */
class DownloadController
{
    public function __invoke(Request $request, string $token): StreamedResponse
    {
        $user = $request->user(Larsadmin::guard()) ?? abort(404);
        $data = self::decode($token);

        abort_unless(
            is_array($data)
            && is_string($data['disk'] ?? null)
            && is_string($data['path'] ?? null)
            && is_string($data['filename'] ?? null)
            && is_int($data['exp'] ?? null)
            && $data['exp'] >= now()->getTimestamp()
            && is_string($data['uid'] ?? null)
            && hash_equals(self::uid($user), $data['uid'])
            && StoredFile::isSafePath($data['path'])
            && is_array(config("filesystems.disks.{$data['disk']}")),
            404,
        );

        $disk = Storage::disk($data['disk']);

        abort_unless($disk->exists($data['path']), 404);

        return FileResponse::make($disk, new StoredFile($data['path'], $data['filename']), true, max(1, (int) config('larsadmin.files.url_ttl', 30)));
    }

    /**
     * The relative download URL of a file response for the request's user, valid for "files.url_ttl" minutes.
     */
    public static function url(Request $request, ActionResponse $response): string
    {
        $user = $request->user(Larsadmin::guard());

        if ($user === null || $response->type !== ActionResponse::FILE) {
            throw new LogicException('A file download URL needs a file response and a panel user.');
        }

        $token = Crypt::encryptString((string) json_encode([
            'disk' => $response->disk,
            'path' => $response->path,
            'filename' => $response->filename,
            'uid' => self::uid($user),
            'exp' => now()->addMinutes(max(1, (int) config('larsadmin.files.url_ttl', 30)))->getTimestamp(),
        ]));

        return route('larsadmin.downloads.show', rtrim(strtr(base64_encode($token), '+/', '-_'), '='), false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $token): ?array
    {
        try {
            $decoded = base64_decode(strtr($token, '-_', '+/'), true);

            if ($decoded === false) {
                return null;
            }

            $data = json_decode(Crypt::decryptString($decoded), true);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function uid(Authenticatable $user): string
    {
        return ($user instanceof Model ? $user->getMorphClass() : $user::class).'|'.$user->getAuthIdentifier();
    }
}
