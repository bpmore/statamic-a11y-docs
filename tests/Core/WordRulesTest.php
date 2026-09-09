<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlInspector;
use Bpmore\DocumentA11yCore\Ooxml\Word\DocxRule;
use Bpmore\DocumentA11yCore\Status;

/** Same shape as the PDF corpus test: exact counts, and nothing beyond them. */
it('produces exactly what the corpus expects', function (array $fixture) {
    $result = (new OoxmlInspector)->inspect(corpusPath($fixture['path']), Format::Docx);

    expect($result->status->value)->toBe($fixture['status']);

    foreach ($fixture['expect'] as $expectation) {
        $found = $result->findingsFor($expectation['rule']);

        expect($found)->toHaveCount(
            $expectation['count'],
            "{$fixture['path']}: expected {$expectation['count']} × {$expectation['rule']}"
        );

        foreach ($found as $finding) {
            expect($finding->severity->value)->toBe($expectation['severity'])
                ->and(trim($finding->message))->not->toBe('');
        }
    }

    foreach ($fixture['expect_absent'] as $ruleId) {
        expect($result->findingsFor($ruleId))->toBeEmpty("{$fixture['path']}: $ruleId should not fire");
    }

    $expected = array_column($fixture['expect'], 'rule');
    $actual = array_unique(array_map(static fn (Finding $f): string => $f->ruleId, $result->findings));
    sort($expected);
    sort($actual);

    expect(array_values($actual))->toBe($expected, "{$fixture['path']}: unexpected rules fired");
})->with(array_map(
    static fn (array $fixture): array => [$fixture],
    corpusFixtures('docx'),
));

it('covers every Word rule in the spec', function () {
    // All ten must fire somewhere in the corpus, or one of them has never run.
    $fired = [];
    foreach (corpusFixtures('docx') as $fixture) {
        foreach ((new OoxmlInspector)->inspect(corpusPath($fixture['path']), Format::Docx)->findings as $finding) {
            $fired[$finding->ruleId] = true;
        }
    }

    expect(count($fired))->toBe(count(DocxRule::cases()));
});

it('agrees with the corpus about how severe each rule is', function () {
    $declared = [];
    foreach (corpusManifest()['fixtures'] as $fixture) {
        foreach ($fixture['expect'] as $expectation) {
            if (str_starts_with($expectation['rule'], 'docx.')) {
                $declared[$expectation['rule']] = $expectation['severity'];
            }
        }
    }

    foreach ($declared as $ruleId => $severity) {
        expect(DocxRule::from($ruleId)->severity()->value)->toBe($severity, "severity of $ruleId");
    }
});

it('locates findings so somebody can act on them', function () {
    $tables = (new OoxmlInspector)->inspect(corpusPath('docx/table-missing-header.docx'), Format::Docx);
    $images = (new OoxmlInspector)->inspect(corpusPath('docx/image-missing-alt.docx'), Format::Docx);
    $links = (new OoxmlInspector)->inspect(corpusPath('docx/link-text-not-meaningful.docx'), Format::Docx);

    expect($tables->findingsFor('docx.table_missing_header_row')[0]->location?->describe())->toBe('Table 2')
        ->and(array_map(
            static fn (Finding $f): ?string => $f->location?->describe(),
            $images->findingsFor('docx.image_missing_alt'),
        ))->toBe(['Picture 2', 'Picture 3'])
        // A link finding points at where the link goes, which is how you find
        // it in a document full of links.
        ->and($links->findingsFor('docx.link_text_not_meaningful')[0]->location?->describe())
        ->toBe('https://example.edu/prospectus.pdf');
});

it('reads the language from wherever Word put it', function () {
    // Word records it in the style defaults, on individual runs, or both.
    expect((new OoxmlInspector)->inspect(corpusPath('docx/good.docx'), Format::Docx)
        ->findingsFor('docx.no_lang'))->toBeEmpty();
});

it('does not call a short document unstructured', function () {
    // no-title-no-lang.docx is two paragraphs. Flagging every memo for having
    // no headings would make the rule noise.
    $result = (new OoxmlInspector)->inspect(corpusPath('docx/no-title-no-lang.docx'), Format::Docx);

    expect($result->findingsFor('docx.no_headings'))->toBeEmpty();
});

it('tells a missing header row from a merged table', function () {
    // complex-table.docx marks its header row correctly and still merges cells;
    // table-missing-header.docx does the opposite. A rule that conflated them
    // would report both problems on both files.
    $complex = (new OoxmlInspector)->inspect(corpusPath('docx/complex-table.docx'), Format::Docx);
    $missing = (new OoxmlInspector)->inspect(corpusPath('docx/table-missing-header.docx'), Format::Docx);

    expect($complex->findingsFor('docx.complex_table'))->toHaveCount(1)
        ->and($complex->findingsFor('docx.table_missing_header_row'))->toBeEmpty()
        ->and($missing->findingsFor('docx.table_missing_header_row'))->toHaveCount(1)
        ->and($missing->findingsFor('docx.complex_table'))->toBeEmpty();
});

it('tells missing alt text from useless alt text', function () {
    $missing = (new OoxmlInspector)->inspect(corpusPath('docx/image-missing-alt.docx'), Format::Docx);
    $filenames = (new OoxmlInspector)->inspect(corpusPath('docx/alt-text-is-filename.docx'), Format::Docx);

    expect($missing->findingsFor('docx.image_missing_alt'))->toHaveCount(2)
        ->and($missing->findingsFor('docx.alt_text_is_filename'))->toBeEmpty()
        // Present but useless is a different problem from absent, and the
        // author fixes it differently.
        ->and($filenames->findingsFor('docx.alt_text_is_filename'))->toHaveCount(3)
        ->and($filenames->findingsFor('docx.image_missing_alt'))->toBeEmpty();
});

it('returns an error result rather than throwing on a file it cannot read', function () {
    // A .doc is not a ZIP, so this inspector cannot read one. In the product it
    // never sees one — DocumentInspector routes legacy files to
    // LegacyOfficeInspector, which reports them unsupported without opening
    // them. This is about what happens when a package will not open.
    $result = (new OoxmlInspector)->inspect(corpusPath('legacy/handbook.doc'), Format::Docx);

    expect($result->status)->toBe(Status::Error)
        ->and($result->error)->toStartWith('This document could not be read:')
        ->and($result->engine?->name)->toBe('heuristics');
});

it('refuses a format it has no rules for', function () {
    // A PDF is not a package, and this inspector will never claim one.
    $inspector = new OoxmlInspector;

    expect($inspector->supports(Format::Docx))->toBeTrue()
        ->and($inspector->supports(Format::Pdf))->toBeFalse()
        ->and(fn () => $inspector->inspect(corpusPath('pdf/untagged.pdf'), Format::Pdf))
        ->toThrow(InvalidArgumentException::class);
});

it('finds images in headers and footers, not only in the body', function () {
    // Headers are separate parts with their own relationships. A rule that only
    // reads word/document.xml misses every letterhead logo in the library,
    // which is most of them.
    $result = (new OoxmlInspector)->inspect(corpusPath('docx/header-image-missing-alt.docx'), Format::Docx);
    $findings = $result->findingsFor('docx.image_missing_alt');

    expect($findings)->toHaveCount(1)
        // And the location says where to look, since "Logo" alone would send
        // somebody hunting through the body for it.
        ->and($findings[0]->location?->describe())->toBe('Logo (in the header)');
});
