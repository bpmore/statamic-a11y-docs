<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs;

use RuntimeException;
use Statamic\Contracts\Assets\Asset;

/**
 * A local path for an asset, whatever disk it lives on.
 *
 * Every inspector works on a file path — parsing a PDF means seeking around it,
 * and an OOXML package is a ZIP whose directory sits at the end. On a local
 * disk that is the file itself and nothing is copied. On S3 it is a temporary
 * copy that has to be cleaned up afterwards, which is what `release()` is for.
 */
final class AssetFile
{
    private function __construct(
        private readonly string $path,
        private readonly bool $temporary,
    ) {}

    public static function for(Asset $asset): self
    {
        // Ask the disk where the file is, then check whether that is somewhere
        // a file actually exists. A local adapter gives a real path; S3 gives
        // one that looks plausible and is not there.
        $path = $asset->disk()->filesystem()->path($asset->path());

        if (is_file($path) && is_readable($path)) {
            return new self($path, temporary: false);
        }

        return new self(self::download($asset), temporary: true);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Removes the temporary copy, if there was one. Safe to call either way. */
    public function release(): void
    {
        if ($this->temporary && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /** Streamed rather than read into a string: these files can be hundreds of megabytes. */
    private static function download(Asset $asset): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'a11y-docs');

        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file to check the document in.');
        }

        $source = $asset->stream();
        $destination = fopen($temporary, 'wb');

        if ($source === false || $destination === false) {
            @unlink($temporary);

            throw new RuntimeException("The asset '{$asset->id()}' could not be read.");
        }

        try {
            stream_copy_to_stream($source, $destination);
        } finally {
            fclose($destination);
            if (is_resource($source)) {
                fclose($source);
            }
        }

        return $temporary;
    }
}
