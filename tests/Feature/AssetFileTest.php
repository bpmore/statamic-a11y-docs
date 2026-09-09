<?php

declare(strict_types=1);

use Bpmore\StatamicA11yDocs\AssetFile;
use Illuminate\Support\Facades\Storage;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\AssetContainer;

it('uses the file itself when the disk is local', function () {
    // Nothing is copied. These documents run to hundreds of megabytes and a
    // scan touches every one of them.
    Storage::fake('assets');
    Storage::disk('assets')->put('handbook.pdf', (string) file_get_contents(corpusPath('pdf/untagged.pdf')));

    $container = AssetContainer::make('documents')->disk('assets')->save();
    $file = AssetFile::for($container->makeAsset('handbook.pdf'));

    expect($file->path())->toEndWith('handbook.pdf')
        ->and(is_file($file->path()))->toBeTrue()
        ->and(hash_file('sha256', $file->path()))
        ->toBe(hash_file('sha256', corpusPath('pdf/untagged.pdf')));

    $file->release();

    // Releasing must not delete somebody's asset.
    expect(is_file($file->path()))->toBeTrue();
});

it('takes a temporary copy when the disk is somewhere else', function () {
    // The S3 case, which is most real installations. `path()` on a remote disk
    // returns something that looks like a path and is not a file, so the bytes
    // have to be streamed down before anything can seek around them.
    $filesystem = Mockery::mock();
    $filesystem->shouldReceive('path')->andReturn('/not/a/real/place/handbook.pdf');
    $filesystem->shouldReceive('readStream')->andReturnUsing(
        fn () => fopen(corpusPath('pdf/untagged.pdf'), 'rb')
    );

    $disk = Mockery::mock();
    $disk->shouldReceive('filesystem')->andReturn($filesystem);

    $asset = Mockery::mock(Asset::class);
    $asset->shouldReceive('disk')->andReturn($disk);
    $asset->shouldReceive('path')->andReturn('handbook.pdf');
    $asset->shouldReceive('stream')->andReturnUsing(fn () => $filesystem->readStream('handbook.pdf'));

    $file = AssetFile::for($asset);

    expect($file->path())->not->toBe('/not/a/real/place/handbook.pdf')
        ->and(is_file($file->path()))->toBeTrue()
        // Streamed, not read into a string, and byte-identical either way.
        ->and(hash_file('sha256', $file->path()))
        ->toBe(hash_file('sha256', corpusPath('pdf/untagged.pdf')));

    $copy = $file->path();
    $file->release();

    expect(is_file($copy))->toBeFalse('the temporary copy should not be left behind');
});
