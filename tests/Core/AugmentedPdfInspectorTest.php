<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Pdf\AugmentedPdfInspector;
use Bpmore\DocumentA11yCore\Pdf\PdfInspector;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\DocumentA11yCore\UncheckedRule;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfInspector;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfOptions;

function augmented(?string $report, ?string $version = '1.30.0'): AugmentedPdfInspector
{
    return new AugmentedPdfInspector(
        new PdfInspector,
        new VeraPdfInspector(fakeVeraPdf($report, version: $version)),
    );
}

it('is exactly the heuristics when veraPDF is not installed', function () {
    // Spec §5: an absent binary is a quiet fall back, not a failure.
    $path = corpusPath('pdf/untagged.pdf');
    $plain = (new PdfInspector)->inspect($path, Format::Pdf);
    $result = augmented(null, version: null)->inspect($path, Format::Pdf);

    expect($result->jsonSerialize())->toBe($plain->jsonSerialize())
        ->and($result->engine?->name)->toBe('heuristics')
        ->and($result->engine?->isAuthoritative())->toBeFalse();
});

it('names both engines when both ran', function () {
    // Claiming only one of them would misdescribe the report in one direction
    // or the other.
    $result = augmented(veraPdfReport('untagged'))->inspect(corpusPath('pdf/untagged.pdf'), Format::Pdf);

    expect($result->engine?->name)->toBe('heuristics+verapdf')
        ->and($result->engine?->version)->toEndWith('+1.30.0')
        ->and($result->engine?->isAuthoritative())->toBeTrue();
});

it('does not report the same rule twice when both engines see it', function () {
    $result = augmented(veraPdfReport('untagged'))->inspect(corpusPath('pdf/untagged.pdf'), Format::Pdf);

    // Both engines find the tagging problem. The heuristic wording survives,
    // because it says what the problem means rather than quoting a clause.
    expect($result->findingsFor('pdf.not_tagged'))->toHaveCount(1)
        ->and($result->findingsFor('pdf.not_tagged')[0]->message)->toStartWith('This PDF has no tags');
});

it('adds what only veraPDF can see', function () {
    $result = augmented(veraPdfReport('untagged'))->inspect(corpusPath('pdf/untagged.pdf'), Format::Pdf);

    $ruleIds = array_map(static fn (Finding $f): string => $f->ruleId, $result->findings);

    expect($ruleIds)->toContain('pdf.ua_7_1_3')       // content not tagged as real content
        ->and($ruleIds)->toContain('pdf.ua_7_21_4_1_1'); // fonts not embedded
});

it('keeps the findings PDF/UA has no rule for', function () {
    // The reason both engines run. PDF/UA says nothing about a scan with no
    // text layer or about bookmarks in a long document, so replacing the
    // heuristics with veraPDF would delete "210 image-only scans" from the
    // dashboard the moment somebody installed Java.
    $scan = augmented(veraPdfReport('image-only-scan'))
        ->inspect(corpusPath('pdf/image-only-scan.pdf'), Format::Pdf);
    $long = augmented(veraPdfReport('long-no-bookmarks'))
        ->inspect(corpusPath('pdf/long-no-bookmarks.pdf'), Format::Pdf);

    expect($scan->findingsFor('pdf.image_only'))->toHaveCount(1)
        ->and($long->findingsFor('pdf.no_bookmarks'))->toHaveCount(1);
});

it('says so when validation was expected and did not happen', function () {
    // Over the size cap here. Silently dropping the authoritative check would
    // let a site believe it had validation it never got.
    $inspector = new AugmentedPdfInspector(
        new PdfInspector,
        new VeraPdfInspector(fakeVeraPdf(veraPdfReport('untagged')), new VeraPdfOptions(maxBytes: 100)),
    );

    $result = $inspector->inspect(corpusPath('pdf/untagged.pdf'), Format::Pdf);

    expect(array_map(static fn (UncheckedRule $r): string => $r->ruleId, $result->unchecked))
        ->toBe(['pdf.ua_validation'])
        ->and($result->unchecked[0]->reason)->toContain('cap for PDF/UA validation')
        // The report must not claim validation it did not perform.
        ->and($result->engine?->isAuthoritative())->toBeFalse();
});

it('does not hand a second engine a file the first could not read', function () {
    $result = augmented(veraPdfReport('untagged'))->inspect(corpusPath('pdf/corrupt-truncated.pdf'), Format::Pdf);

    expect($result->status)->toBe(Status::Error)
        ->and($result->engine?->name)->toBe('heuristics');
});

it('runs against the real binary', function () {
    // The captured reports keep the parser honest; this keeps the captures
    // honest. Skipped wherever veraPDF is not installed, which is most places.
    $result = (new AugmentedPdfInspector)->inspect(corpusPath('pdf/untagged.pdf'), Format::Pdf);

    expect($result->status)->toBe(Status::Fail)
        ->and($result->engine?->name)->toBe('heuristics+verapdf')
        ->and($result->engine?->isAuthoritative())->toBeTrue()
        ->and($result->findingsFor('pdf.not_tagged'))->toHaveCount(1)
        ->and(array_map(static fn (Finding $f): string => $f->ruleId, $result->findings))
        ->toContain('pdf.ua_7_21_4_1_1');
})->skip(fn (): bool => ! veraPdfInstalled(), 'veraPDF is not installed on this machine.');
