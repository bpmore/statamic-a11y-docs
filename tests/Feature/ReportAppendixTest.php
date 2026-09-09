<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Reporting\LibrarySummary;
use Bpmore\StatamicA11yDocs\Reporting\ReportAppendix;
use Bpmore\StatamicA11yDocs\RuleLabel;
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

    $this->scan = function (array $files) {
        foreach ($files as $path => $fixture) {
            Storage::disk('assets')->put($path, (string) file_get_contents(corpusPath($fixture)));
        }
        $this->artisan('docs:check --sync');
    };
});

it('says nothing when nothing has been checked', function () {
    // A report should not carry an empty appendix explaining that it is empty.
    expect(ReportAppendix::forReport())->toBeNull();
});

it('reports exactly what spec §10 asks an appendix to carry', function () {
    ($this->scan)(['handbook.pdf' => 'pdf/untagged.pdf', 'policy.docx' => 'docx/good.docx']);

    $appendix = ReportAppendix::forReport();

    // Counts by format, by severity, engine used, and the known-issues list.
    expect($appendix['documents']['total'])->toBe(2)
        ->and($appendix['documents']['by_format'])->toHaveKeys(['pdf', 'docx'])
        ->and($appendix['findings']['by_severity'])->toHaveKeys(['critical', 'serious', 'moderate', 'minor'])
        ->and($appendix['engines'][0]['engine'])->toStartWith('heuristics ')
        ->and($appendix['known_issues'])->not->toBeEmpty();
});

it('says in its own words that it is not the conformance table', function () {
    // The whole point of the task. Folding a PDF's missing tags into SC 1.3.1
    // is a category error a knowledgeable auditor catches on sight, and it
    // would put the credibility of the whole report in question.
    ($this->scan)(['handbook.pdf' => 'pdf/untagged.pdf']);

    $appendix = ReportAppendix::forReport();

    expect($appendix['heading'])->toBe('Appendix: document accessibility')
        ->and($appendix['scope'])->toContain('PDF/UA and Section 508 Chapter 5')
        ->and($appendix['scope'])->toContain('not WCAG success criteria')
        ->and($appendix['scope'])->toContain('reported separately from the conformance table');
});

it('says whether the checks were authoritative or heuristic', function () {
    ($this->scan)(['handbook.pdf' => 'pdf/untagged.pdf']);

    expect(ReportAppendix::forReport()['engines'][0]['authoritative'])->toBeFalse();
});

it('lists known issues worst first, with where to find each one', function () {
    ($this->scan)(['form.pdf' => 'pdf/form-unlabelled-fields.pdf', 'scan.pdf' => 'pdf/image-only-scan.pdf']);

    $issues = ReportAppendix::forReport()['known_issues'];

    expect($issues[0]['severity'])->toBe('critical')
        ->and(array_column($issues, 'severity'))->toBe(
            collect($issues)->pluck('severity')->sortBy(fn ($s) => ['critical' => 0, 'serious' => 1, 'moderate' => 2, 'minor' => 3][$s])->values()->all()
        )
        ->and(collect($issues)->firstWhere('rule', 'pdf.unlabelled_form_fields')['where'])->toBe('page 1, field2');
});

it('caps the list and says how much it left out', function () {
    // A list that silently stops is a list that misleads about how much there
    // is. A11y Report caps its own appendices the same way.
    ($this->scan)(['scan.pdf' => 'pdf/image-only-scan.pdf']);

    $appendix = (new ReportAppendix(app(LibrarySummary::class), limit: 2))->data();

    expect($appendix['known_issues'])->toHaveCount(2)
        ->and($appendix['known_issues_omitted'])->toBe(3);
});

it('renders as a section that reads like part of the report', function () {
    ($this->scan)(['handbook.pdf' => 'pdf/untagged.pdf']);

    $html = view('a11y-docs::appendix', ['appendix' => ReportAppendix::forReport()])->render();

    expect($html)->toContain('<h2 id="documents-appendix-heading">')
        ->and($html)->toContain('aria-labelledby="documents-appendix-heading"')
        ->and($html)->toContain('scope="col"')
        ->and($html)->toContain('scope="row"')
        ->and($html)->toContain('<caption>')
        ->and($html)->toContain('not WCAG success criteria')
        // No <strong>: the same PDF/UA structure-type problem the exported
        // report hit, and this fragment is printed into somebody else's PDF.
        ->and($html)->not->toContain('<strong>');
});

it('gives A11y Report the rule in words as well as the id', function () {
    ($this->scan)(['handbook.pdf' => 'pdf/untagged.pdf']);

    $issue = ReportAppendix::forReport()['known_issues'][0];

    expect($issue)->toHaveKeys(['rule', 'rule_label']);
    expect($issue['rule_label'])->toBe(RuleLabel::for($issue['rule']));
    expect($issue['rule_label'])->not->toBe($issue['rule']);
});
