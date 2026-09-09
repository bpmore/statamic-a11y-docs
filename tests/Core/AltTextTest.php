<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\AltText;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlInspector;
use Bpmore\DocumentA11yCore\Severity;

it('treats absent, empty and blank alike', function () {
    expect(AltText::isMissing(null))->toBeTrue()
        ->and(AltText::isMissing(''))->toBeTrue()
        ->and(AltText::isMissing("  \n "))->toBeTrue()
        ->and(AltText::isMissing('A photograph of the quad.'))->toBeFalse();
});

it('recognises a filename wherever the author got it from', function (string $alt) {
    expect(AltText::isFilename($alt))->toBeTrue();
})->with([
    'image1.png',
    'DSC_0042.JPG',
    'final-v2-FINAL.jpeg',
    'DSC 0042.JPG',                          // filenames have spaces in them
    'C:\\Users\\comms\\Pictures\\quad.png',  // and sometimes a whole path
    'chart.SVG',
]);

it('does not mistake a description for a filename', function (string $alt) {
    expect(AltText::isFilename($alt))->toBeFalse();
})->with([
    'A bar chart showing enrolment rising from 12,400 to 14,900.',
    'The main quad on an open day.',
    'Dr. Ford',                              // an initial is not an extension
    'Picture 3',
]);

it('recognises the names Office gives an object', function (string $alt) {
    expect(AltText::isPlaceholder($alt))->toBeTrue();
})->with(['Picture 3', 'picture', 'Image 12', 'Chart 1', 'Text Box 4', 'SmartArt 2', 'Untitled']);

it('recognises alt text copied from the object\'s own name', function () {
    // No list of words could catch this one, which is why the name is passed in.
    expect(AltText::isPlaceholder('Diagram of the appeals process', 'Diagram of the appeals process'))->toBeTrue()
        ->and(AltText::isPlaceholder('Diagram of the appeals process', 'Picture 7'))->toBeFalse();
});

it('leaves a short but real description alone', function (string $alt) {
    // Terse is not the same as useless. A rule that flagged short alt text
    // would punish the people writing it properly.
    expect(AltText::isPlaceholder($alt))->toBeFalse();
})->with([
    'University crest',
    'Photograph of the library at dusk',
    'Chart of enrolment by year',
    'Figure 3: the appeals process',
]);

it('applies the same standard to all three Office formats', function (string $path, Format $format, string $prefix) {
    // The point of the task. Alt text that is a filename is a filename whether
    // it is in a report, a deck or a spreadsheet, and a rule set that only
    // caught it in Word would be an inconsistency somebody has to remember.
    $result = (new OoxmlInspector)->inspect(corpusPath($path), $format);

    expect($result->findingsFor("$prefix.alt_text_is_filename"))->toHaveCount(1)
        ->and($result->findingsFor("$prefix.alt_text_not_descriptive"))->toHaveCount(1);

    foreach ([...$result->findingsFor("$prefix.alt_text_is_filename"), ...$result->findingsFor("$prefix.alt_text_not_descriptive")] as $finding) {
        expect($finding->severity)->toBe(Severity::Critical)
            // The message quotes the offending text, because "your alt text is
            // no good" is not something anybody can act on.
            ->and($finding->message)->toContain('"');
    }
})->with([
    ['pptx/alt-text-not-descriptive.pptx', Format::Pptx, 'pptx'],
    ['xlsx/alt-text-not-descriptive.xlsx', Format::Xlsx, 'xlsx'],
]);

it('separates useless alt text from absent alt text', function () {
    // A document can pass the presence check completely and still fail a
    // reader, which is why these are different rules with different messages.
    $useless = (new OoxmlInspector)->inspect(corpusPath('docx/alt-text-is-filename.docx'), Format::Docx);
    $absent = (new OoxmlInspector)->inspect(corpusPath('docx/image-missing-alt.docx'), Format::Docx);

    expect($useless->findingsFor('docx.image_missing_alt'))->toBeEmpty()
        ->and($useless->findingsFor('docx.alt_text_is_filename'))->toHaveCount(3)
        ->and($useless->findingsFor('docx.alt_text_not_descriptive'))->toHaveCount(1)
        ->and($absent->findingsFor('docx.image_missing_alt'))->toHaveCount(2)
        ->and($absent->findingsFor('docx.alt_text_is_filename'))->toBeEmpty()
        ->and($absent->findingsFor('docx.alt_text_not_descriptive'))->toBeEmpty();
});

it('reports each kind of useless alt text once, not twice', function () {
    // A filename is also not descriptive. Reporting both would put two rows in
    // a queue for one thing to fix.
    $result = (new OoxmlInspector)->inspect(corpusPath('docx/alt-text-is-filename.docx'), Format::Docx);

    expect($result->findings)->toHaveCount(4);
});
