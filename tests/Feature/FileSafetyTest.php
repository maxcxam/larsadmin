<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maxcxam\Larsadmin\Files\FileResponse;
use Maxcxam\Larsadmin\Files\FileSafety;
use Maxcxam\Larsadmin\Files\FileTypes;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Uploads;

/*
 * The public file safety facade (ADR-041, SECX-03). The corpus is the one the medialibrary spike ran
 * against its copy: the facade must answer exactly like the internal rules the built-in fields use.
 */

beforeEach(function () {
    config(['larsadmin.files.blocked_extensions' => ['php', 'phtml', 'phar', 'exe', 'bat', 'sh', 'htaccess']]);
});

dataset('safety uploads', fn () => [
    'php content as jpg' => ['shell.jpg', '<?php system($_GET["c"]); ?>'],
    'php short tag as txt' => ['note.txt', "<?php\necho 1;"],
    'shell script as png' => ['run.png', "#!/bin/sh\nrm -rf /\n"],
    'bash script, no extension' => ['run', "#!/bin/bash\necho hi\n"],
    'real png' => ['photo.png', base64_decode(Uploads::PNG)],
    'png named exe' => ['photo.exe', base64_decode(Uploads::PNG)],
    'multi extension' => ['x.php.jpg', base64_decode(Uploads::PNG)],
    'trailing dots and spaces' => ['evil.php. .', 'hello'],
    'html' => ['page.html', '<!doctype html><html><body><script>alert(1)</script></body></html>'],
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'htaccess' => ['.htaccess', 'AddType application/x-httpd-php .jpg'],
    'quoted bat' => ['run.bat"', '@echo off'],
    'upper case' => ['SHELL.PHTML', 'x'],
    'pdf' => ['doc.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"],
    'plain text' => ['readme.txt', 'just text'],
    'unicode' => ['Звіт за жовтень.txt', 'текст'],
]);

it('blocks and names uploads exactly like the built-in fields', function (string $name, string $content) {
    $file = Uploads::make($name, $content);

    foreach ([true, false] as $public) {
        expect(FileSafety::blockedExtension($file, $public))->toBe(FileTypes::blockedExtension($file, $public))
            ->and(FileSafety::storedExtension($file, $public))->toBe(FileTypes::storedExtension($file, $public));
    }
})->with('safety uploads');

it('blocks by name segment and by the content the mime sniff reveals', function () {
    expect(FileSafety::blockedExtension(Uploads::make('shell.jpg', '<?php system($_GET["c"]); ?>')))->toBe('php')
        ->and(FileSafety::blockedExtension(Uploads::make('x.php.jpg', base64_decode(Uploads::PNG))))->toBe('php')
        ->and(FileSafety::blockedExtension(Uploads::make('evil.php. .', 'hello')))->toBe('php')
        ->and(FileSafety::blockedExtension(Uploads::make('run.bat"', '@echo off')))->toBe('bat')
        ->and(FileSafety::blockedExtension(Uploads::make('.htaccess', 'x')))->toBe('htaccess')
        ->and(FileSafety::blockedExtension(Uploads::png()))->toBeNull()
        ->and(FileSafety::blockedExtension(Uploads::pdf()))->toBeNull();
});

it('blocks markup and scripts on public disks only, whatever the config', function () {
    config(['larsadmin.files.blocked_extensions' => []]);

    expect(FileSafety::blockedExtension(Uploads::svg()))->toBeNull()
        ->and(FileSafety::blockedExtension(Uploads::svg(), publicDisk: true))->toBe('svg')
        ->and(FileSafety::blockedExtension(Uploads::html(), publicDisk: true))->toBe('html');
});

it('stores uploads with the detected extension, never the client one', function () {
    expect(FileSafety::storedExtension(Uploads::png('x.gif')))->toBe('png')
        ->and(FileSafety::storedExtension(Uploads::pdf('report.exe')))->toBe('pdf')
        ->and(FileSafety::storedExtension(Uploads::make('a.txt', "#!/bin/sh\nid\n")))->toBe('bin')
        ->and(FileSafety::storedExtension(Uploads::php('x.jpg')))->toBe('bin')
        ->and(FileSafety::storedExtension(Uploads::make('x', "\x00\x01\x02\x03\xFE\xFF")))->toBe('bin');
});

it('gives a validation rule that fails for blocked uploads and passes others', function () {
    $validate = fn ($file, bool $public = false) => Validator::make(['file' => $file], ['file' => [FileSafety::rule($public)]]);

    expect($validate(Uploads::php('shell.jpg'))->fails())->toBeTrue()
        ->and($validate(Uploads::php('shell.jpg'))->errors()->first('file'))->toContain('php')
        ->and($validate(Uploads::png())->passes())->toBeTrue()
        ->and($validate(Uploads::svg())->passes())->toBeTrue()
        ->and($validate(Uploads::svg(), true)->fails())->toBeTrue()
        ->and($validate('not a file')->passes())->toBeTrue();
});

it('sanitizes and ascii-folds names exactly like the built-in fields', function (string $name) {
    expect(FileSafety::sanitizeName($name))->toBe(FileTypes::sanitizeName($name))
        ->and(FileSafety::asciiName($name))->toBe(FileTypes::asciiName($name));
})->with([
    'plain' => 'photo.jpg',
    'path' => '..\\..\\windows/system32/evil.exe',
    'quotes and newline' => "a\"b\nc.pdf",
    'bidi override' => "invoice\u{202E}gpj.exe",
    'zero width' => "na\u{200B}me.txt",
    'reserved chars' => 'a<b>c:d*e?f|g.txt',
    'whitespace' => "  lots   of\tspace .txt ",
    'long with extension' => str_repeat('я', 250).'.jpeg',
    'long ascii' => str_repeat('a', 300).'.pdf',
    'long, long extension' => str_repeat('b', 300).'.'.str_repeat('x', 12),
    'dotfile' => '.env',
    'only unicode' => 'Звіт.pdf',
    'invalid utf8' => "bad\xC3\x28.txt",
    'empty' => '',
]);

it('cleans hostile names', function () {
    expect(FileSafety::sanitizeName('..\\..\\windows/system32/evil.exe'))->toBe('evil.exe')
        ->and(FileSafety::sanitizeName("invoice\u{202E}gpj.exe"))->toBe('invoicegpj.exe')
        ->and(FileSafety::sanitizeName("a\"b\nc.pdf"))->toBe('abc.pdf')
        ->and(mb_strlen(FileSafety::sanitizeName(str_repeat('a', 300).'.pdf')))->toBe(200)
        ->and(FileSafety::sanitizeName(str_repeat('a', 300).'.pdf'))->toEndWith('.pdf')
        ->and(FileSafety::asciiName('Звіт.pdf'))->toBe('Zvit.pdf')
        ->and(FileSafety::asciiName('.env'))->toBe('download.env');
});

it('treats only raster images as images, and checks the mime type when given', function () {
    expect(FileSafety::isRasterImage('a/photo.PNG'))->toBeTrue()
        ->and(FileSafety::isRasterImage('photo.avif'))->toBeTrue()
        ->and(FileSafety::isRasterImage('logo.svg'))->toBeFalse()
        ->and(FileSafety::isRasterImage('page.html'))->toBeFalse()
        ->and(FileSafety::isRasterImage('noextension'))->toBeFalse()
        ->and(FileSafety::isRasterImage('photo.png', 'image/png'))->toBeTrue()
        ->and(FileSafety::isRasterImage('photo.jpg', 'image/webp'))->toBeTrue()
        ->and(FileSafety::isRasterImage('photo.png', 'image/svg+xml'))->toBeFalse()
        ->and(FileSafety::isRasterImage('photo.png', 'text/html'))->toBeFalse()
        ->and(FileSafety::isRasterImage('photo.png', ''))->toBeFalse()
        ->and(FileSafety::isRasterImage('logo.svg', 'image/png'))->toBeFalse();
});

it('serves raster images inline with the panel headers', function () {
    Storage::fake('local');
    Storage::disk('local')->put('files/abc.png', base64_decode(Uploads::PNG));
    config(['larsadmin.files.url_ttl' => 10]);

    $response = FileSafety::response(Storage::disk('local'), 'files/abc.png', 'Фото "1".png');

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->headers->get('Content-Disposition'))->toContain('filename="Foto 1.png"')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe(FileResponse::CONTENT_SECURITY_POLICY)
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=600')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    expect(FileSafety::response(Storage::disk('local'), 'files/abc.png', ttlMinutes: 2)->headers->get('Cache-Control'))
        ->toContain('max-age=120');
});

it('serves everything else, and requested downloads, as an attachment that is never cached', function (string $path, bool $download) {
    Storage::fake('local');
    Storage::disk('local')->put($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $response = FileSafety::response(Storage::disk('local'), $path, download: $download);

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename='.basename($path))
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe(FileResponse::CONTENT_SECURITY_POLICY)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'svg' => ['files/logo.svg', false],
    'html' => ['files/page.html', false],
    'no extension' => ['files/blob', false],
    'png download' => ['files/abc.png', true],
]);

it('falls back to the base name when the given name is empty after cleaning', function () {
    Storage::fake('local');
    Storage::disk('local')->put('files/report.pdf', 'x');

    expect(FileSafety::response(Storage::disk('local'), 'files/report.pdf', "\u{202E}\"")->headers->get('Content-Disposition'))
        ->toBe('attachment; filename=report.pdf');
});

it('answers like the response of the built-in fields', function (string $path, string $name, bool $download) {
    Storage::fake('local');
    Storage::disk('local')->put($path, 'x');
    $disk = Storage::disk('local');

    $public = FileSafety::response($disk, $path, $name, $download, 30)->headers->all();
    $core = FileResponse::make($disk, new StoredFile($path, $name), $download, 30)->headers->all();
    unset($public['date'], $core['date']);

    expect($public)->toBe($core);
})->with([
    'image inline' => ['a/photo.JPG', 'Photo.jpg', false],
    'image download' => ['a/photo.webp', 'Photo.webp', true],
    'svg' => ['a/logo.svg', 'logo.svg', false],
    'hostile name' => ['a/x.pdf', "..\\evil\u{202E}\".pdf", false],
]);

it('rejects a cache lifetime below one minute', function (int $ttl) {
    Storage::fake('local');
    Storage::disk('local')->put('files/abc.png', base64_decode(Uploads::PNG));

    FileSafety::response(Storage::disk('local'), 'files/abc.png', ttlMinutes: $ttl);
})->with([0, -5])->throws(InvalidArgumentException::class, 'at least 1 minute');
