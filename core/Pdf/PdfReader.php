<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Pdf;

/**
 * Turns a PDF file into a {@see PdfDocument}.
 *
 * The one place in this package that knows a PDF library exists. Everything
 * downstream reads `PdfDocument`, so swapping the library, adding decryption,
 * or standing in a fake for a test is a matter of one implementation.
 *
 * Implementations throw on a file they cannot read. Turning that into a
 * `Status::Error` result is the inspector's job, because it is the inspector
 * that knows one bad file must not stop a batch.
 */
interface PdfReader
{
    public function read(string $path): PdfDocument;
}
