<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfInspector;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfOptions;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfRunner;

/** A runner that returns a captured report, or fails the way the real one does. */
function fakeVeraPdf(?string $json, ?string $version = '1.30.0', ?string $throws = null): VeraPdfRunner
{
    return new class($json, $version, $throws) implements VeraPdfRunner
    {
        public function __construct(
            private readonly ?string $json,
            private readonly ?string $version,
            private readonly ?string $throws,
        ) {}

        public function version(): ?string
        {
            return $this->version;
        }

        public function validate(string $path): string
        {
            if ($this->throws !== null) {
                throw new RuntimeException($this->throws);
            }

            return (string) $this->json;
        }
    };
}

it('reports veraPDF findings under our own rule identifiers where they mean the same thing', function () {
    $result = (new VeraPdfInspector(fakeVeraPdf(veraPdfReport('untagged'))))
        ->inspect('irrelevant.pdf', Format::Pdf);

    // 6.2-1 (no MarkInfo) and 7.1-11 (no structure tree) both mean "not
    // tagged", so both arrive as pdf.not_tagged at our critical severity.
    expect($result->findingsFor('pdf.not_tagged'))->toHaveCount(2);

    foreach ($result->findingsFor('pdf.not_tagged') as $finding) {
        expect($finding->severity)->toBe(Severity::Critical);
    }
});

it('keeps clauses that have no equivalent of ours, traceable to the standard', function () {
    $result = (new VeraPdfInspector(fakeVeraPdf(veraPdfReport('untagged'))))
        ->inspect('irrelevant.pdf', Format::Pdf);

    $ruleIds = array_map(static fn (Finding $f): string => $f->ruleId, $result->findings);
    sort($ruleIds);

    expect($ruleIds)->toBe([
        'pdf.not_tagged',            // 6.2-1
        'pdf.not_tagged',            // 7.1-11
        'pdf.ua_7_1_3',              // partial tagging: deliberately not mapped
        'pdf.ua_7_1_8',              // no XMP metadata stream
        'pdf.ua_7_21_4_1_1',         // fonts not embedded
    ]);
});

it('takes severity from the categories veraPDF tags a rule with', function () {
    $result = (new VeraPdfInspector(fakeVeraPdf(veraPdfReport('untagged'))))
        ->inspect('irrelevant.pdf', Format::Pdf);

    $severity = static fn (string $ruleId): Severity => $result->findingsFor($ruleId)[0]->severity;

    expect($severity('pdf.ua_7_1_3'))->toBe(Severity::Critical)     // tagged "artifact"
        ->and($severity('pdf.ua_7_1_8'))->toBe(Severity::Serious)   // tagged "metadata"
        ->and($severity('pdf.ua_7_21_4_1_1'))->toBe(Severity::Moderate); // tagged "font"
});

it('quotes the clause so a finding can be traced back to the standard', function () {
    $result = (new VeraPdfInspector(fakeVeraPdf(veraPdfReport('form-unlabelled-fields'))))
        ->inspect('irrelevant.pdf', Format::Pdf);

    expect($result->findingsFor('pdf.unlabelled_form_fields')[0]->message)
        ->toStartWith('PDF/UA 7.18.1:');
});

it('recognises blocked extraction from the encryption clause', function () {
    // 7.16-1 fires only on the fixture whose permission bit 10 is cleared,
    // which is how that mapping was established rather than guessed.
    $blocked = (new VeraPdfInspector(fakeVeraPdf(veraPdfReport('encrypted-no-extract'))))
        ->inspect('irrelevant.pdf', Format::Pdf);

    expect($blocked->findingsFor('pdf.extraction_blocked'))->toHaveCount(1)
        ->and($blocked->findingsFor('pdf.extraction_blocked')[0]->severity)->toBe(Severity::Critical);
});

it('says it was skipped rather than passed when veraPDF is not installed', function () {
    // The difference matters: "no problems found" and "we did not look" are not
    // the same sentence.
    $result = (new VeraPdfInspector(fakeVeraPdf(null, version: null)))
        ->inspect('irrelevant.pdf', Format::Pdf);

    expect($result->status)->toBe(Status::Skipped)
        ->and($result->error)->toContain('not installed')
        ->and((new VeraPdfInspector(fakeVeraPdf(null, version: null)))->isAvailable())->toBeFalse();
});

it('skips a document too large to be worth validating', function () {
    $result = (new VeraPdfInspector(
        fakeVeraPdf(veraPdfReport('untagged')),
        new VeraPdfOptions(maxBytes: 100),
    ))->inspect(corpusPath('pdf/untagged.pdf'), Format::Pdf);

    expect($result->status)->toBe(Status::Skipped)
        ->and($result->error)->toContain('cap for PDF/UA validation');
});

it('turns a run that fails into an error result, not an exception', function () {
    $result = (new VeraPdfInspector(fakeVeraPdf(null, throws: 'veraPDF did not finish within 60 seconds.')))
        ->inspect('irrelevant.pdf', Format::Pdf);

    expect($result->status)->toBe(Status::Error)
        ->and($result->error)->toContain('did not finish within 60 seconds');
});

it('reports a file veraPDF could not read as an error', function () {
    $result = (new VeraPdfInspector(fakeVeraPdf(veraPdfReport('corrupt-truncated'))))
        ->inspect('irrelevant.pdf', Format::Pdf);

    expect($result->status)->toBe(Status::Error)
        ->and($result->error)->toContain('could not read this PDF');
});

it('records itself as the authoritative engine', function () {
    $engine = (new VeraPdfInspector(fakeVeraPdf(veraPdfReport('untagged'))))->engine();

    expect($engine->name)->toBe('verapdf')
        ->and($engine->version)->toBe('1.30.0')
        ->and($engine->isAuthoritative())->toBeTrue();
});

it('refuses a format it does not handle', function () {
    expect(fn () => (new VeraPdfInspector(fakeVeraPdf(null)))->inspect('x.docx', Format::Docx))
        ->toThrow(InvalidArgumentException::class);
});
