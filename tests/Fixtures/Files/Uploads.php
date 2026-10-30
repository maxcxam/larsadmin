<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Files;

use Illuminate\Http\UploadedFile;

/**
 * Uploads with real content, so type detection (fileinfo) sees what an attacker would send.
 */
class Uploads
{
    /**
     * A 1x1 transparent PNG.
     */
    public const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public static function png(string $name = 'photo.png'): UploadedFile
    {
        return static::make($name, base64_decode(self::PNG));
    }

    /**
     * A minimal AVIF: the "ftyp" box with the avif brand (enough for content detection) and an empty "mdat".
     */
    public const string AVIF = 'AAAAHGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZgAAAAhtZGF0';

    public static function avif(string $name = 'photo.avif'): UploadedFile
    {
        return static::make($name, base64_decode(self::AVIF));
    }

    public static function pdf(string $name = 'report.pdf'): UploadedFile
    {
        return static::make($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    public static function text(string $name = 'notes.txt', string $content = "Plain notes\n"): UploadedFile
    {
        return static::make($name, $content);
    }

    public static function php(string $name = 'shell.php'): UploadedFile
    {
        return static::make($name, "<?php echo shell_exec(\$_GET['c']); ?>\n");
    }

    public static function svg(string $name = 'logo.svg'): UploadedFile
    {
        return static::make($name, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    }

    public static function html(string $name = 'page.html'): UploadedFile
    {
        return static::make($name, "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>\n");
    }

    public static function make(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'larsadmin');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
