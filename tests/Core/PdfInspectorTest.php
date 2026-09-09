<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Pdf\PdfDocument;
use Bpmore\DocumentA11yCore\Pdf\PdfInspector;
use Bpmore\DocumentA11yCore\Pdf\PdfReader;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\DocumentA11yCore\UncheckedRule;

/**
 * The point of Phase 1. Every PDF in the corpus, checked against exactly what
 * the manifest says it should produce — including the rules it says must stay
 * silent, and the exact number of times each one fires.
 */
it('produces exactly what the corpus expects', function (array $fixture) {
    $result = (new PdfInspector)->inspect(corpusPath($fixture['path']), Format::Pdf);

    expect($result->status->value)->toBe($fixture['status']);

    foreach ($fixture['expect'] as $expectation) {
        $found = $result->findingsFor($expectation['rule']);

        expect($found)->toHaveCount(
            $expectation['count'],
            "{$fixture['path']}: expected {$expectation['count']} × {$expectation['rule']}"
        );

        foreach ($found as $finding) {
            expect($finding->severity->value)->toBe($expectation['severity']);
            expect(trim($finding->message))->not->toBe('');
        }
    }

    foreach ($fixture['expect_absent'] as $ruleId) {
        expect($result->findingsFor($ruleId))->toBeEmpty("{$fixture['path']}: $ruleId should not fire");
    }

    // Nothing beyond what the manifest lists, either. Over-firing is the
    // failure mode that survives a test suite that only checks for presence.
    $expected = array_column($fixture['expect'], 'rule');
    $actual = array_unique(array_map(static fn (Finding $f): string => $f->ruleId, $result->findings));
    sort($expected);
    sort($actual);

    expect(array_values($actual))->toBe($expected, "{$fixture['path']}: unexpected rules fired");
})->with(array_map(
    static fn (array $fixture): array => [$fixture],
    corpusFixtures('pdf'),
));

it('reports what it could not check on an encrypted document', function (string $path) {
    // Strings and streams stay encrypted, so the title and the text are
    // unreadable. Saying so is not the same as reporting a document with a
    // perfectly good title as having none.
    $result = (new PdfInspector)->inspect(corpusPath($path), Format::Pdf);

    $unchecked = array_map(static fn (UncheckedRule $r): string => $r->ruleId, $result->unchecked);
    sort($unchecked);

    expect($unchecked)->toBe(['pdf.image_only', 'pdf.no_title'])
        ->and($result->unchecked[0]->reason)->not->toBe('');
})->with(['pdf/encrypted-no-extract.pdf', 'pdf/encrypted-accessible.pdf']);

it('checks everything on a document that is not encrypted', function () {
    expect((new PdfInspector)->inspect(corpusPath('pdf/tagged-good.pdf'), Format::Pdf)->unchecked)
        ->toBeEmpty();
});

it('locates findings so somebody can act on them', function () {
    $result = (new PdfInspector)->inspect(corpusPath('pdf/form-unlabelled-fields.pdf'), Format::Pdf);

    expect(array_map(
        static fn (Finding $f): ?string => $f->location?->describe(),
        $result->findingsFor('pdf.unlabelled_form_fields'),
    ))->toBe(['page 1, field2', 'page 1, field3']);

    $figures = (new PdfInspector)->inspect(corpusPath('pdf/figure-missing-alt.pdf'), Format::Pdf);

    expect($figures->findingsFor('pdf.figure_missing_alt')[0]->location?->describe())
        ->toBe('page 1, Figure');
});

it('records the engine and the page count on every result it produces', function () {
    $result = (new PdfInspector)->inspect(corpusPath('pdf/long-no-bookmarks.pdf'), Format::Pdf);

    expect($result->engine?->name)->toBe('heuristics')
        ->and($result->engine?->version)->toMatch('/^\d+\.\d+\.\d+/')
        ->and($result->engine?->isAuthoritative())->toBeFalse()
        ->and($result->pageCount)->toBe(25);
});

it('returns an error result rather than throwing on a file it cannot read', function (string $path) {
    $result = (new PdfInspector)->inspect(corpusPath($path), Format::Pdf);

    expect($result->status)->toBe(Status::Error)
        ->and($result->error)->toStartWith('This PDF could not be read:')
        ->and($result->findings)->toBeEmpty();
})->with(['pdf/corrupt-truncated.pdf', 'misc/empty.pdf']);

it('checks a PDF whatever it has been renamed to', function () {
    // misc/actually-a-pdf.docx. Format detection reads the bytes; the inspector
    // is then handed the format that was found, not the one on the label.
    $path = corpusPath('misc/actually-a-pdf.docx');

    expect(Format::detect($path))->toBe(Format::Pdf)
        ->and((new PdfInspector)->inspect($path, Format::Pdf)->worstSeverity())
        ->toBe(Severity::Critical);
});

it('refuses a format it does not handle', function () {
    expect(fn () => (new PdfInspector)->inspect(corpusPath('docx/good.docx'), Format::Docx))
        ->toThrow(InvalidArgumentException::class);
});

it('agrees with the corpus about how severe each rule is', function () {
    // The manifest is the rule registry. If the inspector and the manifest ever
    // disagree about a severity, one of them is wrong and this says so.
    $declared = [];
    foreach (corpusManifest()['fixtures'] as $fixture) {
        foreach ($fixture['expect'] as $expectation) {
            $declared[$expectation['rule']] = $expectation['severity'];
        }
    }

    $emitted = [];
    foreach (corpusFixtures('pdf') as $fixture) {
        $result = (new PdfInspector)->inspect(corpusPath($fixture['path']), Format::Pdf);
        foreach ($result->findings as $finding) {
            $emitted[$finding->ruleId] = $finding->severity->value;
        }
    }

    expect($emitted)->not->toBeEmpty();

    foreach ($emitted as $ruleId => $severity) {
        expect($severity)->toBe($declared[$ruleId] ?? null, "severity of $ruleId");
    }
});

it('covers every heuristic in the spec', function () {
    // Ten rules in spec §4. All ten must fire somewhere in the corpus, or one
    // of them is dead code that has never been executed.
    $fired = [];
    foreach (corpusFixtures('pdf') as $fixture) {
        foreach ((new PdfInspector)->inspect(corpusPath($fixture['path']), Format::Pdf)->findings as $finding) {
            $fired[$finding->ruleId] = true;
        }
    }
    ksort($fired);

    expect(array_keys($fired))->toBe([
        'pdf.extraction_blocked',
        'pdf.figure_missing_alt',
        'pdf.image_only',
        'pdf.no_bookmarks',
        'pdf.no_lang',
        'pdf.no_title',
        'pdf.no_tounicode',
        'pdf.not_tagged',
        'pdf.title_not_displayed',
        'pdf.unlabelled_form_fields',
    ]);
});

/** A reader that hands back a document we made up, for shapes no fixture has. */
function inspectorFor(PdfDocument $document): PdfInspector
{
    return new PdfInspector(new class($document) implements PdfReader
    {
        public function __construct(private readonly PdfDocument $document) {}

        public function read(string $path): PdfDocument
        {
            return $this->document;
        }
    });
}

it('says it could not check the permissions rather than guessing', function () {
    // Encrypted, but /P was unreadable. "We could not tell" is the only honest
    // answer; "extraction is allowed" would be a guess in the document's favour.
    $result = inspectorFor(new PdfDocument(
        pageCount: 1,
        isEncrypted: true,
        permissions: null,
        hasStructTreeRoot: true,
        isMarked: true,
        hasLang: true,
        hasDisplayDocTitle: true,
    ))->inspect('irrelevant', Format::Pdf);

    expect($result->findingsFor('pdf.extraction_blocked'))->toBeEmpty()
        ->and(array_map(static fn (UncheckedRule $r): string => $r->ruleId, $result->unchecked))
        ->toContain('pdf.extraction_blocked');
});

it('treats an empty language value as no language at all', function () {
    // /Lang () is present but says nothing. Only an encrypted document gets the
    // benefit of the doubt, because there the value genuinely cannot be read.
    $result = inspectorFor(new PdfDocument(
        pageCount: 1,
        hasStructTreeRoot: true,
        isMarked: true,
        hasLang: true,
        lang: '   ',
        hasDisplayDocTitle: true,
        infoTitle: 'A Title',
    ))->inspect('irrelevant', Format::Pdf);

    expect($result->findingsFor('pdf.no_lang'))->toHaveCount(1);
});

it('does not call a sparse but genuine document a scan', function () {
    $document = static fn (int $textLength, int $images): PdfDocument => new PdfDocument(
        pageCount: 10,
        hasStructTreeRoot: true,
        isMarked: true,
        hasLang: true,
        lang: 'en-US',
        hasDisplayDocTitle: true,
        infoTitle: 'A Title',
        textLength: $textLength,
        imageCount: $images,
    );

    // Ten pages, so the threshold is 80 non-whitespace characters.
    expect(inspectorFor($document(79, 1))->inspect('x', Format::Pdf)->findingsFor('pdf.image_only'))->toHaveCount(1)
        ->and(inspectorFor($document(80, 1))->inspect('x', Format::Pdf)->findingsFor('pdf.image_only'))->toBeEmpty()
        // No images at all is a document with no text, not a scan of one.
        ->and(inspectorFor($document(0, 0))->inspect('x', Format::Pdf)->findingsFor('pdf.image_only'))->toBeEmpty();
});
