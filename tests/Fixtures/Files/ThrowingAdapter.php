<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Files;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use LogicException;

/**
 * A disk adapter that fails on any IO; only public URLs can be built (getUrl()).
 */
class ThrowingAdapter implements FilesystemAdapter
{
    public function getUrl(string $path): string
    {
        return 'https://cdn.test/'.$path;
    }

    public function fileExists(string $path): bool
    {
        $this->io();
    }

    public function directoryExists(string $path): bool
    {
        $this->io();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->io();
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->io();
    }

    public function read(string $path): string
    {
        $this->io();
    }

    public function readStream(string $path)
    {
        $this->io();
    }

    public function delete(string $path): void
    {
        $this->io();
    }

    public function deleteDirectory(string $path): void
    {
        $this->io();
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->io();
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->io();
    }

    public function visibility(string $path): FileAttributes
    {
        $this->io();
    }

    public function mimeType(string $path): FileAttributes
    {
        $this->io();
    }

    public function lastModified(string $path): FileAttributes
    {
        $this->io();
    }

    public function fileSize(string $path): FileAttributes
    {
        $this->io();
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $this->io();
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->io();
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->io();
    }

    private function io(): never
    {
        throw new LogicException('Unexpected disk IO.');
    }
}
