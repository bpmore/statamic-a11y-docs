<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Ooxml\Excel\XlsxRule;
use Bpmore\DocumentA11yCore\Ooxml\PowerPoint\PptxRule;
use Bpmore\DocumentA11yCore\Ooxml\Word\DocxRule;
use Bpmore\DocumentA11yCore\Pdf\PdfRule;
use Bpmore\StatamicA11yDocs\RuleLabel;

it('names the format the author would recognise', function (string $ruleId, string $expected) {
    expect(RuleLabel::for($ruleId))->toBe($expected);
})->with([
    ['pdf.not_tagged', 'PDF · Not tagged'],
    ['docx.no_headings', 'Word · No headings'],
    ['pptx.slide_missing_title', 'PowerPoint · Slide missing title'],
    ['xlsx.blank_sheet', 'Excel · Blank sheet'],
]);

it('writes a PDF/UA clause as a citation', function () {
    // veraPDF ids are clause numbers. Prettifying one gives "Ua 7 21 4 1 1",
    // which is how the dashboard headline used to read.
    expect(RuleLabel::for('pdf.ua_7_21_4_1_1'))->toBe('PDF/UA 7.21.4.1.1')
        ->and(RuleLabel::for('pdf.ua_5_1'))->toBe('PDF/UA 5.1');
});

it('says in English what de-underscoring would leave as jargon', function (string $ruleId, string $expected) {
    expect(RuleLabel::for($ruleId))->toBe($expected);
})->with([
    ['pdf.no_lang', 'PDF · No language set'],
    ['docx.no_lang', 'Word · No language set'],
    ['pdf.no_tounicode', 'PDF · Text cannot be extracted'],
    ['pdf.image_only', 'PDF · Image-only scan'],
]);

it('gives every rule a label with no id left in it', function () {
    $rules = [];
    foreach ([PdfRule::cases(), DocxRule::cases(), PptxRule::cases(), XlsxRule::cases()] as $family) {
        foreach ($family as $rule) {
            $rules[] = $rule->value;
        }
    }
    $rules[] = 'pdf.ua_7_1_8';

    foreach ($rules as $ruleId) {
        $label = RuleLabel::for($ruleId);

        expect($label)->not->toBe($ruleId);
        expect(str_contains($label, '_'))->toBeFalse("$ruleId still carries an underscore: $label");
        expect(preg_match('/\b(pdf|docx|pptx|xlsx)\./i', $label) === 1)
            ->toBeFalse("$ruleId still carries a dotted id: $label");
    }
});
