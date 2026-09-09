<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Pdf\Figure;
use Bpmore\DocumentA11yCore\Pdf\Font;
use Bpmore\DocumentA11yCore\Pdf\FormField;
use Bpmore\DocumentA11yCore\Pdf\PdfDocument;
use Bpmore\DocumentA11yCore\Pdf\PdfRule;

it('needs both halves of tagging to call a document tagged', function () {
    expect((new PdfDocument(1, hasStructTreeRoot: true, isMarked: true))->isTagged())->toBeTrue()
        // A structure tree nothing points at, or a claim of marked content with
        // no tree to point at, is not a usefully tagged document.
        ->and((new PdfDocument(1, hasStructTreeRoot: true))->isTagged())->toBeFalse()
        ->and((new PdfDocument(1, isMarked: true))->isTagged())->toBeFalse();
});

it('accepts a title from either place it can live', function () {
    expect((new PdfDocument(1, infoTitle: 'Annual Report'))->hasTitle())->toBeTrue()
        ->and((new PdfDocument(1, xmpTitle: 'Annual Report'))->hasTitle())->toBeTrue()
        ->and((new PdfDocument(1, infoTitle: '   '))->hasTitle())->toBeFalse()
        ->and((new PdfDocument(1))->hasTitle())->toBeFalse();
});

it('separates "extraction allowed" from "we could not tell"', function () {
    expect((new PdfDocument(1))->allowsAccessibilityExtraction())->toBeTrue()
        ->and((new PdfDocument(1, isEncrypted: true, permissions: -5))->allowsAccessibilityExtraction())->toBeTrue()
        ->and((new PdfDocument(1, isEncrypted: true, permissions: -529))->allowsAccessibilityExtraction())->toBeFalse()
        // Encrypted with unreadable permissions is not the same as permitted.
        ->and((new PdfDocument(1, isEncrypted: true))->allowsAccessibilityExtraction())->toBeNull();
});

it('treats a field name as a name and a tooltip as a label', function () {
    // /T is what the submitted data is called. Accepting it as a description
    // would pass every unlabelled form ever built.
    expect((new FormField('field2', null))->isLabelled())->toBeFalse()
        ->and((new FormField('field2', '  '))->isLabelled())->toBeFalse()
        ->and((new FormField('fullName', 'Your full name'))->isLabelled())->toBeTrue()
        ->and((new FormField(null, null))->describe())->toBe('an unnamed field');
});

it('accepts either kind of text equivalent on a figure', function () {
    expect((new Figure(hasAlt: true, hasActualText: false))->isDescribed())->toBeTrue()
        // /ActualText is the right answer for a picture of a word.
        ->and((new Figure(hasAlt: false, hasActualText: true))->isDescribed())->toBeTrue()
        ->and((new Figure(hasAlt: false, hasActualText: false))->isDescribed())->toBeFalse();
});

it('does not ask the standard 14 fonts for a character map', function () {
    // Every reader has these built in. Flagging them would fire on most PDFs
    // ever made, which is the quickest way to make a report worth ignoring.
    expect((new Font('Helvetica', 'Type1', hasToUnicode: false))->needsToUnicode())->toBeFalse()
        ->and((new Font('Times-Bold', 'Type1', hasToUnicode: false))->needsToUnicode())->toBeFalse()
        ->and((new Font('SubsetSans', 'TrueType', hasToUnicode: false))->needsToUnicode())->toBeTrue()
        ->and((new Font('SubsetSans', 'TrueType', hasToUnicode: true))->needsToUnicode())->toBeFalse();
});

it('does not double-count a composite font', function () {
    // A CIDFont's character map lives on its Type0 parent, so asking the
    // descendant for one reports every composite font twice.
    expect((new Font('SubsetSans', 'CIDFontType2', hasToUnicode: false))->needsToUnicode())->toBeFalse()
        ->and((new Font('SubsetSans', 'CIDFontType0', hasToUnicode: false))->needsToUnicode())->toBeFalse()
        ->and((new Font('SubsetSans', 'Type0', hasToUnicode: false))->needsToUnicode())->toBeTrue();
});

it('strips the subset prefix from a font name', function () {
    expect((new Font('AAAAAA+SubsetSans', 'TrueType', hasToUnicode: true))->name())->toBe('SubsetSans')
        ->and((new Font('/Helvetica', 'Type1', hasToUnicode: false))->name())->toBe('Helvetica')
        // Subset-prefixed standard fonts still count as standard.
        ->and((new Font('ABCDEF+Helvetica', 'Type1', hasToUnicode: false))->isStandard14())->toBeTrue();
});

it('agrees with the fixture corpus about how severe each rule is', function () {
    // The catalogue is now the single source both engines read from, so this is
    // the one place drift could enter: between the code and the manifest.
    $declared = [];
    foreach (corpusManifest()['fixtures'] as $fixture) {
        foreach ($fixture['expect'] as $expectation) {
            if (str_starts_with($expectation['rule'], 'pdf.')) {
                $declared[$expectation['rule']] = $expectation['severity'];
            }
        }
    }

    expect($declared)->not->toBeEmpty();

    foreach ($declared as $ruleId => $severity) {
        expect(PdfRule::from($ruleId)->severity()->value)
            ->toBe($severity, "severity of $ruleId");
    }
});

it('has a rule for every heuristic and no more', function () {
    expect(array_map(
        static fn (PdfRule $r): string => $r->value,
        PdfRule::cases(),
    ))->toHaveCount(10);
});
