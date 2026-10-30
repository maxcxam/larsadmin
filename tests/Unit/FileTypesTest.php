<?php

use Maxcxam\Larsadmin\Files\FileTypes;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Uploads;

it('blocks the configured extensions, and markup and script types on public disks whatever the config (SECF-5)', function () {
    config(['larsadmin.files.blocked_extensions' => ['PHP', '.exe']]);

    expect(FileTypes::blocked(false))->toBe(['php', 'exe'])
        ->and(FileTypes::blocked(true))->toBe(array_values(array_unique(['php', 'exe', ...FileTypes::PUBLIC_BLOCKED])))
        ->and(FileTypes::blocked(true))->toContain('svg', 'html', 'xhtml', 'xml', 'js', 'mht', 'phtml', 'htaccess');

    config(['larsadmin.files.blocked_extensions' => null]);

    expect(FileTypes::blocked(false))->toBe([]);
});

it('checks every dot segment of the client name and the detected type', function (string $name, string $content, ?string $blocked) {
    expect(FileTypes::blockedExtension(Uploads::make($name, $content), false))->toBe($blocked);
})->with([
    'plain text' => ['notes.txt', 'hello', null],
    'php as the base name' => ['php.txt', 'hello', null],
    'double extension' => ['x.php.txt', 'hello', 'php'],
    'upper case' => ['x.PHTML', 'hello', 'phtml'],
    'dot file' => ['.htaccess', 'hello', 'htaccess'],
    'padded segment' => ['x.php .txt', 'hello', 'php'],
    'php content' => ['notes.txt', '<?php echo 1;', 'php'],
    'html content' => ['page.txt', "<!DOCTYPE html>\n<html><body>x</body></html>", 'html'],
    'quote stripped from the stored name' => ['run.bat"', 'echo', 'bat'],
    'angle bracket stripped from the stored name' => ['run.bat>', 'echo', 'bat'],
    'control character inside the extension' => ["run.b\x01at", 'echo', 'bat'],
    'bidi override inside the extension' => ["run.b\u{202E}at", 'echo', 'bat'],
    'trailing dot and space' => ['run.bat. ', 'echo', 'bat'],
    'windows executables from the default list' => ['invoice.scr', 'x', 'scr'],
    'script hosts from the default list' => ['report.ps1', 'x', 'ps1'],
]);

it('blocks the dangerous types of the default list', function (string $extension) {
    expect(FileTypes::blockedExtension(Uploads::make("a.{$extension}", 'x'), false))->toBe($extension);
})->with(collect(['scr', 'hta', 'vbs', 'ps1', 'lnk', 'jar', 'reg', 'wsf', 'xht', 'mht', 'appref-ms', 'settingcontent-ms'])
    ->mapWithKeys(fn (string $extension) => [$extension => [$extension]])
    ->all());

it('chooses the stored extension from the content', function () {
    expect(FileTypes::storedExtension(Uploads::png('x.gif'), true))->toBe('png')
        ->and(FileTypes::storedExtension(Uploads::php('x.jpg'), false))->toBe('bin')
        ->and(FileTypes::storedExtension(Uploads::make('x', "\x00\x01\x02\x03\xFE\xFF"), false))->toBe('bin');
});

it('maps types for serving and display', function () {
    expect(FileTypes::rasterType('JPG'))->toBe('image/jpeg')
        ->and(FileTypes::rasterType('svg'))->toBeNull()
        ->and(FileTypes::safeContentType('png'))->toBe('image/png')
        ->and(FileTypes::safeContentType('html'))->toBe('application/octet-stream')
        ->and(FileTypes::mimeFor('pdf'))->toBe('application/pdf')
        ->and(FileTypes::mimeFor(''))->toBeNull()
        ->and(FileTypes::mimeFor('nopeext'))->toBeNull();
});

it('sanitizes client file names', function (string $name, string $expected) {
    expect(FileTypes::sanitizeName($name))->toBe($expected);
})->with([
    'unicode kept' => ['Звіт за жовтень.pdf', 'Звіт за жовтень.pdf'],
    'windows path dropped' => ['C:\\Users\\me\\secret.pdf', 'secret.pdf'],
    'unix path dropped' => ['../../etc/passwd', 'passwd'],
    'quotes and controls' => ["a\"b\r\nc\0.pdf", 'abc.pdf'],
    'reserved characters' => ['a<b>c:d*e?f|g.txt', 'abcdefg.txt'],
    'whitespace collapsed' => ["  a \t  b.txt ", 'a b.txt'],
    'invalid utf-8' => ["a\xFFb.txt", 'ab.txt'],
    'bidi override' => ["invoice\u{202E}fdp.scr", 'invoicefdp.scr'],
    'bidi isolates and marks' => ["a\u{2066}b\u{2069}c\u{200F}d.pdf", 'abcd.pdf'],
    'zero-width and BOM' => ["\u{FEFF}a\u{200B}b\u{200D}.pdf", 'ab.pdf'],
    'C1 control' => ["a\u{0085}b.pdf", 'ab.pdf'],
    'empty' => ['"""', ''],
]);

it('limits long names to 200 characters keeping the extension', function () {
    $name = FileTypes::sanitizeName(str_repeat('я', 300).'.pdf');

    expect(mb_strlen($name))->toBe(200)->and($name)->toEndWith('я.pdf');
});

it('builds an ASCII fallback name', function (string $name, string $expected) {
    expect(FileTypes::asciiName($name))->toBe($expected);
})->with([
    'ascii' => ['report.pdf', 'report.pdf'],
    'transliterated' => ['Звіт.pdf', 'Zvit.pdf'],
    'percent and quotes' => ['50% "off".pdf', '50 off.pdf'],
    'nothing left' => ["\u{1F600}", 'download'],
]);
