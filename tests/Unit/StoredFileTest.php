<?php

use Maxcxam\Larsadmin\Files\StoredFile;

it('accepts only safe relative paths', function (string $path, bool $safe) {
    expect(StoredFile::isSafePath($path))->toBe($safe);
})->with([
    'relative' => ['larsadmin/posts/cover/a.png', true],
    'single segment' => ['a.png', true],
    'empty' => ['', false],
    'absolute' => ['/etc/passwd', false],
    'parent' => ['a/../../.env', false],
    'dot segment' => ['a/./b', false],
    'empty segment' => ['a//b', false],
    'backslash' => ['a\\b', false],
    'drive or wrapper' => ['phar://x', false],
    'nul' => ["a\0b", false],
    'newline' => ["a\nb", false],
]);

it('reads stored entries tolerantly', function () {
    expect(StoredFile::fromStored('docs/a.pdf'))->toEqual(new StoredFile('docs/a.pdf', 'a.pdf'))
        ->and(StoredFile::fromStored(['path' => 'docs/a.pdf', 'name' => 'Звіт.pdf', 'size' => 10, 'mime' => 'application/pdf']))
        ->toEqual(new StoredFile('docs/a.pdf', 'Звіт.pdf', 10, 'application/pdf'))
        ->and(StoredFile::fromStored((object) ['path' => 'docs/b.pdf', 'size' => '10', 'mime' => 5]))
        ->toEqual(new StoredFile('docs/b.pdf', 'b.pdf'))
        ->and(StoredFile::fromStored(['path' => 'x.pdf', 'name' => "\n"]))->toEqual(new StoredFile('x.pdf', 'x.pdf'))
        ->and(StoredFile::fromStored(['path' => 'd/x.PDF'], 'Звіт (2)'))->toEqual(new StoredFile('d/x.PDF', 'Звіт (2).pdf'))
        ->and(StoredFile::fromStored(['path' => 'd/x.pdf', 'name' => 'Mine.pdf'], 'Label'))->toEqual(new StoredFile('d/x.pdf', 'Mine.pdf'))
        ->and(StoredFile::fromStored('d/x.pdf', '<>'))->toEqual(new StoredFile('d/x.pdf', '.pdf'))
        ->and(StoredFile::fromStored(['path' => '../x']))->toBeNull()
        ->and(StoredFile::fromStored(['name' => 'x']))->toBeNull()
        ->and(StoredFile::fromStored(null))->toBeNull()
        ->and(StoredFile::fromStored(42))->toBeNull();
});

it('derives a stable 32-hex token from the app key, disk and path', function () {
    $file = new StoredFile('docs/a.pdf', 'a.pdf');
    $token = $file->token('local');

    expect($token)->toMatch('/^[a-f0-9]{32}$/')
        ->and((new StoredFile('docs/a.pdf', 'other.pdf'))->token('local'))->toBe($token)
        ->and($file->token('public'))->not->toBe($token)
        ->and((new StoredFile('docs/b.pdf', 'a.pdf'))->token('local'))->not->toBe($token);

    config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);

    expect($file->token('local'))->not->toBe($token)
        ->and($file->token('local'))->toBe(substr(hash_hmac('sha256', "larsadmin.file-token\0local\0docs/a.pdf", (string) config('app.key')), 0, 32))
        ->and($file->token('local'))->not->toBe(substr(hash_hmac('sha256', "local\0docs/a.pdf", (string) config('app.key')), 0, 32));
});

it('knows its extension and whether it is a raster image', function () {
    expect((new StoredFile('a/B.JPG', 'x'))->extension())->toBe('jpg')
        ->and((new StoredFile('a/B.JPG', 'x'))->isRasterImage())->toBeTrue()
        ->and((new StoredFile('a/logo.svg', 'x'))->isRasterImage())->toBeFalse()
        ->and((new StoredFile('a/noext', 'x'))->extension())->toBe('')
        ->and((new StoredFile('a.pdf', 'A', 3, 'application/pdf'))->toArray())
        ->toBe(['path' => 'a.pdf', 'name' => 'A', 'size' => 3, 'mime' => 'application/pdf']);
});
