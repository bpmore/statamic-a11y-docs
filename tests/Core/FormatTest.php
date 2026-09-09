<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Format;

it('identifies every fixture as the format the manifest records', function (array $fixture) {
    expect(Format::detect(corpusPath($fixture['path']))?->value)->toBe($fixture['format']);
    // Wrapped so each dataset is one argument; the key becomes the test name,
    // which is what makes a failure say which file broke.
})->with(array_map(static fn (array $fixture): array => [$fixture], corpusFixtures()));

it('reads the file rather than the extension', function () {
    // A PDF someone renamed. Trusting the extension here would hand it to the
    // OOXML reader, record a ZIP error, and hide a critical finding.
    $path = corpusPath('misc/actually-a-pdf.docx');

    expect(Format::fromExtension($path))->toBe(Format::Docx)
        ->and(Format::fromContent($path))->toBe(Format::Pdf)
        ->and(Format::detect($path))->toBe(Format::Pdf);
});

it('falls back to the extension when the file cannot be identified', function () {
    // Zero bytes: nothing to sniff. It is still a PDF as far as the library is
    // concerned, and belongs in the report as one that failed to parse.
    $path = corpusPath('misc/empty.pdf');

    expect(Format::fromContent($path))->toBeNull()
        ->and(Format::detect($path))->toBe(Format::Pdf);
});

it('identifies legacy binary formats from their compound-file streams', function (string $path, Format $format) {
    expect(Format::fromContent(corpusPath($path)))->toBe($format);
})->with([
    ['legacy/handbook.doc', Format::Doc],
    ['legacy/deck.ppt', Format::Ppt],
    ['legacy/budget.xls', Format::Xls],
]);

it('distinguishes the three OOXML flavours by their marker part', function (string $path, Format $format) {
    expect(Format::fromContent(corpusPath($path)))->toBe($format);
})->with([
    ['docx/good.docx', Format::Docx],
    ['pptx/good.pptx', Format::Pptx],
    ['xlsx/good.xlsx', Format::Xlsx],
]);

it('returns null for a file that is not a document at all', function () {
    expect(Format::detect(__FILE__))->toBeNull()
        ->and(Format::detect('/no/such/file.pdf'))->toBe(Format::Pdf)
        ->and(Format::fromContent('/no/such/file.pdf'))->toBeNull();
});

it('knows which formats it can actually check', function () {
    expect(Format::Pdf->isSupported())->toBeTrue()
        ->and(Format::Docx->isOoxml())->toBeTrue()
        ->and(Format::Pdf->isOoxml())->toBeFalse()
        ->and(Format::Doc->isLegacy())->toBeTrue()
        ->and(Format::Doc->isSupported())->toBeFalse()
        ->and(Format::Doc->modernEquivalent())->toBe(Format::Docx)
        ->and(Format::Pdf->modernEquivalent())->toBe(Format::Pdf);
});
