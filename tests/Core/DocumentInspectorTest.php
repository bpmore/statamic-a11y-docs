<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\LegacyOfficeInspector;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlInspector;
use Bpmore\DocumentA11yCore\Pdf\AugmentedPdfInspector;
use Bpmore\DocumentA11yCore\Pdf\PdfInspector;
use Bpmore\DocumentA11yCore\Status;

/**
 * Heuristics only. veraPDF is stricter than the corpus expectations describe —
 * it fails `tagged-good.pdf` on font embedding and PDF/UA identification, quite
 * correctly — so the manifest documents what the PHP rules say, and running the
 * external validator here would be testing a different question slowly.
 */
function dispatcher(): DocumentInspector
{
    return new DocumentInspector([
        new PdfInspector,
        new OoxmlInspector,
        new LegacyOfficeInspector,
    ]);
}

it('reaches the right verdict on every document in the corpus', function (array $fixture) {
    // One entry point, 45 files, no format passed in. The strongest statement
    // this suite makes: hand the product any document and it says the right
    // thing about it.
    $result = dispatcher()->inspect(corpusPath($fixture['path']));

    expect($result->status->value)->toBe($fixture['status'], $fixture['path']);
})->with(array_map(
    static fn (array $fixture): array => [$fixture],
    corpusFixtures(),
));

it('never opens a legacy Office file', function (string $path) {
    // Not "does not parse it" — does not touch it. The path here does not
    // exist, and the answer is the same, which is only possible if nothing
    // ever went looking.
    $inspector = new LegacyOfficeInspector;

    $real = $inspector->inspect(corpusPath($path), Format::detect(corpusPath($path)));
    $imaginary = $inspector->inspect('/no/such/file.doc', Format::Doc);

    expect($real->status)->toBe(Status::Unsupported)
        ->and($imaginary->status)->toBe(Status::Unsupported)
        ->and($real->findings)->toBeEmpty();
})->with(['legacy/handbook.doc', 'legacy/deck.ppt', 'legacy/budget.xls']);

it('says what to do about a legacy file rather than only that it failed', function () {
    // "Unsupported" on its own is a dead end. The message has to name the
    // format and the way out of it.
    $doc = dispatcher()->inspect(corpusPath('legacy/handbook.doc'));
    $ppt = dispatcher()->inspect(corpusPath('legacy/deck.ppt'));
    $xls = dispatcher()->inspect(corpusPath('legacy/budget.xls'));

    expect($doc->error)->toContain('Word 97–2003 (.doc)')
        ->and($doc->error)->toContain('save it as .docx')
        ->and($ppt->error)->toContain('save it as .pptx')
        ->and($xls->error)->toContain('save it as .xlsx');
});

it('records no engine on a document nothing ran on', function () {
    // Naming the engine that would have run is exactly the overstatement
    // spec §5 is written against.
    $result = dispatcher()->inspect(corpusPath('legacy/handbook.doc'));

    expect($result->engine)->toBeNull()
        ->and($result->jsonSerialize()['engine'])->toBeNull()
        // The inspector still declares an engine, because the control panel has
        // to list what is installed.
        ->and((new LegacyOfficeInspector)->engine()->name)->toBe('heuristics');
});

it('identifies the document by reading it, not by its name', function () {
    // A PDF somebody renamed .docx. Trusting the extension would send it to the
    // OOXML reader, record a ZIP error, and hide a critical finding.
    $result = dispatcher()->inspect(corpusPath('misc/actually-a-pdf.docx'));

    expect($result->status)->toBe(Status::Fail)
        ->and($result->findingsFor('pdf.not_tagged'))->toHaveCount(1);
});

it('times every check it runs', function () {
    // duration_ms is a column in document_checks and nothing else fills it.
    $result = dispatcher()->inspect(corpusPath('pdf/long-no-bookmarks.pdf'));

    expect($result->durationMs)->toBeGreaterThanOrEqual(0)
        ->and($result->jsonSerialize()['duration_ms'])->toBe($result->durationMs);
});

it('reports a file that is not a document at all as unsupported', function () {
    $result = dispatcher()->inspect(corpusPath('README.md'));

    expect($result->status)->toBe(Status::Unsupported)
        ->and($result->error)->toContain('not a document this addon knows how to check');
});

it('knows which formats it can actually check', function () {
    expect(array_map(
        static fn (Format $f): string => $f->value,
        dispatcher()->formatsChecked(),
    ))->toBe(['pdf', 'docx', 'pptx', 'xlsx'])
        // Legacy formats are handled, but "handled" means reported, not checked.
        ->and(dispatcher()->supports(Format::Doc))->toBeTrue()
        ->and(Format::Doc->isSupported())->toBeFalse();
});

it('uses PDF/UA validation by default when it is installed', function () {
    // The default wiring is the product's, not the test's.
    expect((new DocumentInspector)->inspectorFor(Format::Pdf))
        ->toBeInstanceOf(AugmentedPdfInspector::class);
});
