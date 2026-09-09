<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Severity;

it('serialises into the shape the findings table stores', function () {
    $finding = new Finding(
        ruleId: 'pdf.figure_missing_alt',
        severity: Severity::Serious,
        message: 'A figure has no alternative text.',
        location: Location::page(14, 'Figure'),
        helpUrl: 'https://example.edu/help/pdf-alt-text',
    );

    expect($finding->jsonSerialize())->toBe([
        'rule_id' => 'pdf.figure_missing_alt',
        'severity' => 'serious',
        'message' => 'A figure has no alternative text.',
        'location' => ['page' => 14, 'element' => 'Figure'],
        'help_url' => 'https://example.edu/help/pdf-alt-text',
    ]);
});

it('is happy without a location or a help url', function () {
    $finding = new Finding('pdf.not_tagged', Severity::Critical, 'This PDF is not tagged.');

    expect($finding->jsonSerialize()['location'])->toBeNull()
        ->and($finding->jsonSerialize()['help_url'])->toBeNull()
        ->and($finding->describe())->toBe('This PDF is not tagged.');
});

it('reads its format from the rule identifier', function () {
    expect((new Finding('docx.image_missing_alt', Severity::Critical, 'x'))->ruleFormat())->toBe('docx')
        ->and((new Finding('pdf.no_lang', Severity::Serious, 'x'))->ruleFormat())->toBe('pdf');
});

it('appends the location when describing itself', function () {
    $finding = new Finding('pptx.slide_missing_title', Severity::Critical, 'This slide has no title.', Location::slide(3));

    expect($finding->describe())->toBe('This slide has no title. (slide 3)');
});

it('rejects rule identifiers that break the convention', function (string $ruleId) {
    expect(fn () => new Finding($ruleId, Severity::Critical, 'x'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'no format prefix' => ['not_tagged'],
    'capitals' => ['PDF.not_tagged'],
    'hyphens' => ['pdf.not-tagged'],
    'trailing dot' => ['pdf.'],
    'empty' => [''],
    'spaces' => ['pdf.not tagged'],
]);

it('refuses a finding nobody can act on', function () {
    expect(fn () => new Finding('pdf.not_tagged', Severity::Critical, '   '))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts every rule identifier the fixture corpus declares', function () {
    // The manifest is the closest thing to a rule registry this project has.
    // If a rule id there cannot become a Finding, one of the two is wrong.
    $ruleIds = corpusRuleIds();

    expect($ruleIds)->not->toBeEmpty();

    foreach ($ruleIds as $ruleId) {
        $finding = new Finding($ruleId, Severity::Critical, 'x');

        expect($finding->ruleId)->toBe($ruleId)
            ->and($finding->ruleFormat())->toBeIn(['pdf', 'docx', 'pptx', 'xlsx']);
    }
});
