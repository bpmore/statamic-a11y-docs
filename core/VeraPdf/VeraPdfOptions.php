<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\VeraPdf;

use InvalidArgumentException;

/** How to invoke veraPDF, and the limits it runs under. */
final readonly class VeraPdfOptions
{
    public function __construct(
        /** Path to the binary, or a bare name to be found on PATH. */
        public string $binary = 'verapdf',
        /** ua1 is PDF/UA-1, the one that matters here; ua2 and the PDF/A flavours also exist. */
        public string $profile = 'ua1',
        /** A 400 MB PDF must not hold a queue worker open indefinitely. */
        public int $timeoutSeconds = 60,
        /**
         * Above this, validation is skipped and the heuristics stand alone.
         * Not an error: a very large document still gets checked, just not by
         * the engine that would take minutes over it.
         */
        public int $maxBytes = 67_108_864,
    ) {
        if (trim($binary) === '' || trim($profile) === '') {
            throw new InvalidArgumentException('veraPDF needs a binary and a profile.');
        }
        if ($timeoutSeconds < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException('The veraPDF timeout and size cap must both be positive.');
        }
    }
}
