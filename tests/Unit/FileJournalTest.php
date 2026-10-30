<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Files\FileJournal;

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('new.txt', 'n');
    Storage::disk('local')->put('old.txt', 'o');
});

it('discards created files and purges obsolete ones separately', function () {
    $journal = new FileJournal;
    $journal->created('local', 'new.txt');
    $journal->obsolete('local', 'old.txt');

    $journal->discardCreated();

    expect(Storage::disk('local')->exists('new.txt'))->toBeFalse()
        ->and(Storage::disk('local')->exists('old.txt'))->toBeTrue();

    $journal->purgeObsolete();
    $journal->purgeObsolete();

    expect(Storage::disk('local')->exists('old.txt'))->toBeFalse();
});

it('reports storage failures with context instead of throwing', function () {
    Exceptions::fake();

    $journal = (new FileJournal)->withContext(['entity' => 'Docs', 'key' => 7]);
    $journal->obsolete('missing-disk', 'a.txt');
    $journal->purgeObsolete();

    $reported = Exceptions::reported();

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(RuntimeException::class)
        ->and($reported[0]->getMessage())->toContain('[a.txt]')->toContain('[missing-disk]')
        ->and($reported[0]->context())->toBe(['disk' => 'missing-disk', 'path' => 'a.txt', 'entity' => 'Docs', 'key' => 7])
        ->and($reported[0]->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
});

it('reports a delete that returned false', function () {
    Exceptions::fake();

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->with('old.txt')->andReturn(false);
    Storage::set('broken', $disk);

    $journal = new FileJournal;
    $journal->obsolete('broken', 'old.txt');
    $journal->purgeObsolete();

    $reported = Exceptions::reported();

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->getPrevious())->toBeNull()
        ->and($reported[0]->context()['disk'])->toBe('broken');
});
