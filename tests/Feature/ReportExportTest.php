<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Pdf\AugmentedPdfInspector;
use Bpmore\DocumentA11yCore\Pdf\PdfInspector;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Reporting\ChromePdfRenderer;
use Bpmore\StatamicA11yDocs\Reporting\HtmlReport;
use Bpmore\StatamicA11yDocs\Reporting\PdfRenderer;
use Bpmore\StatamicA11yDocs\Reporting\PdfUaMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    foreach ([DocumentInspector::class, AssetChecker::class, DocumentScanner::class] as $binding) {
        app()->forgetInstance($binding);
    }

    Storage::fake('assets');
    AssetContainer::make('documents')->disk('assets')->save();

    foreach (['handbook.pdf' => 'pdf/untagged.pdf', 'scan.pdf' => 'pdf/image-only-scan.pdf', 'policy.docx' => 'docx/good.docx'] as $path => $fixture) {
        Storage::disk('assets')->put($path, (string) file_get_contents(corpusPath($fixture)));
    }
    $this->artisan('docs:check --sync');

    $this->tempFile = function (string $extension): string {
        return tempnam(sys_get_temp_dir(), 'a11y-report').'.'.$extension;
    };
});

it('renders a report that would pass its own standards as a web page', function () {
    // The report about document accessibility has to be accessible itself.
    $html = app(HtmlReport::class)->render();

    expect($html)->toContain('<!DOCTYPE html>')
        ->and($html)->toMatch('/<html lang="[a-z]{2}/')
        ->and($html)->toContain('<title>Document Accessibility Report</title>')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<main>')
        // Real table semantics, not a grid of divs.
        ->and($html)->toContain('<caption>')
        ->and($html)->toContain('scope="col"')
        ->and($html)->toContain('scope="row"');
});

it('spells out what each severity means, so colour carries nothing on its own', function () {
    $html = app(HtmlReport::class)->render();

    expect($html)->toContain('Somebody cannot read the document at all.')
        ->and($html)->toContain('Critical');
});

it('says which engine produced the numbers', function () {
    // Spec §5. A report that does not say whether it used real PDF/UA
    // validation or heuristics is a report that overstates itself.
    $html = app(HtmlReport::class)->render();

    expect($html)->toContain('How these documents were checked')
        ->and($html)->toContain('heuristic checks, not formal PDF/UA validation');
});

it('says what it does not cover', function () {
    // Spec §10: document conformance is PDF/UA and Section 508 Chapter 5
    // territory, not WCAG success criteria, and a report that blurs the two
    // would be caught by any auditor who knows the difference.
    $html = app(HtmlReport::class)->render();

    expect($html)->toContain('not WCAG success criteria')
        ->and($html)->toContain('say nothing about the accessibility of the website itself');
});

it('writes the HTML out', function () {
    $path = ($this->tempFile)('html');

    $this->artisan("docs:report --html={$path}")
        ->expectsOutputToContain('Accessible HTML report written')
        ->assertExitCode(0);

    expect(file_get_contents($path))->toContain('Document Accessibility Report');

    @unlink($path);
});

it('says so rather than failing when there is no browser to print with', function () {
    app()->bind(PdfRenderer::class, fn (): PdfRenderer => new ChromePdfRenderer('/no/such/chrome'));

    $this->artisan('docs:report --pdf='.($this->tempFile)('pdf'))
        ->expectsOutputToContain('No browser was found')
        ->assertExitCode(1);
});

it('produces a PDF that passes its own product\'s checks', function () {
    // The point of the whole exercise, spec §12: if a document accessibility
    // report is itself an untagged PDF, that is the screenshot that ends up on
    // social media. So the report is checked by the thing it is a report from.
    $path = ($this->tempFile)('pdf');

    $this->artisan("docs:report --pdf={$path}")->assertExitCode(0);

    $result = (new PdfInspector)->inspect($path, Format::Pdf);

    expect($result->status)->toBe(Status::Pass)
        ->and($result->findings)->toBeEmpty();

    @unlink($path);
})->skip(fn (): bool => ! (new ChromePdfRenderer)->isAvailable(), 'No browser installed to render a PDF with.');

it('produces a PDF that passes PDF/UA validation', function () {
    // And the authoritative answer, from the same validator this addon ships
    // an adapter for. Chrome writes no XMP packet, so without PdfUaMetadata
    // this fails clause 7.1-8 — a tagged PDF that is still not conformant.
    $path = ($this->tempFile)('pdf');

    $this->artisan("docs:report --pdf={$path}")->assertExitCode(0);

    $result = (new AugmentedPdfInspector)->inspect($path, Format::Pdf);

    expect($result->engine?->isAuthoritative())->toBeTrue()
        ->and($result->findings)->toBeEmpty()
        ->and($result->status)->toBe(Status::Pass);

    @unlink($path);
})->skip(
    fn (): bool => ! (new ChromePdfRenderer)->isAvailable() || ! veraPdfInstalled(),
    'Needs both a browser and veraPDF.',
);

it('leaves a PDF alone when it cannot safely add metadata', function () {
    // A renderer that writes cross-reference streams needs a different
    // incremental update, and producing an invalid one would be worse than
    // leaving the file as it is.
    $path = ($this->tempFile)('pdf');
    file_put_contents($path, "%PDF-1.7\nnot really a pdf\n");

    (new PdfUaMetadata)->addTo($path, 'Report');

    expect(file_get_contents($path))->toBe("%PDF-1.7\nnot really a pdf\n");

    @unlink($path);
});

it('does not add metadata twice', function () {
    $path = ($this->tempFile)('pdf');
    copy(corpusPath('pdf/tagged-good.pdf'), $path);

    $before = file_get_contents($path);
    (new PdfUaMetadata)->addTo($path, 'Report');

    expect(file_get_contents($path))->toBe($before);

    @unlink($path);
});

it('names each rule in words and cites its id', function () {
    // The report goes to an auditor. The label says what the problem is; the id
    // is what they cite and trace back to a rule, so the report carries both
    // rather than choosing.
    $html = app(HtmlReport::class)->render();

    expect($html)->toContain('PDF · Not tagged')
        ->and($html)->toContain('pdf.not_tagged');

    // The id is secondary, not hidden: no aria-hidden, no display:none. An
    // auditor using a screen reader has to be able to reach it.
    expect(preg_match('/aria-hidden[^>]*>\s*<code/i', $html) === 1)->toBeFalse();
    expect($html)->toContain('class="rule-id"');
});
