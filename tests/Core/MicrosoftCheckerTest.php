<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Ooxml\Excel\XlsxRule;
use Bpmore\DocumentA11yCore\Ooxml\MicrosoftChecker;
use Bpmore\DocumentA11yCore\Ooxml\MicrosoftClassification;
use Bpmore\DocumentA11yCore\Ooxml\PowerPoint\PptxRule;
use Bpmore\DocumentA11yCore\Ooxml\Word\DocxRule;
use Bpmore\DocumentA11yCore\Severity;

/** Every Office rule this addon has, with the severity it assigns. */
function officeRules(): array
{
    $rules = [];

    foreach ([DocxRule::cases(), PptxRule::cases(), XlsxRule::cases()] as $cases) {
        foreach ($cases as $rule) {
            $rules[$rule->value] = $rule->severity();
        }
    }

    return $rules;
}

it('accounts for every Office rule, one way or the other', function () {
    // The property that makes the rest of this worth anything: a rule cannot be
    // added without deciding where it sits against Microsoft's checker. Silence
    // is not an option the table allows.
    foreach (officeRules() as $ruleId => $severity) {
        expect(MicrosoftChecker::accountsFor($ruleId))->toBeTrue(
            "$ruleId is neither mapped to a Microsoft rule nor listed as having no equivalent"
        );
    }
});

it('does not map rules that do not exist', function () {
    // The table drifting ahead of the code would be as bad as it drifting
    // behind: a mapping for a rule nobody emits describes nothing.
    $ours = array_keys(officeRules());

    foreach (array_keys(MicrosoftChecker::RULES) as $ruleId) {
        expect($ruleId)->toBeIn($ours);
    }
    foreach (array_keys(MicrosoftChecker::NO_EQUIVALENT) as $ruleId) {
        expect($ruleId)->toBeIn($ours);
    }
});

it('says why whenever it grades a rule differently from Word', function () {
    // Not "the severities match Microsoft's" — they deliberately do not, in
    // seven places. The property is that every difference is on purpose and
    // carries its reason, so no severity can drift away from one.
    $departures = 0;

    foreach (officeRules() as $ruleId => $severity) {
        $classification = MicrosoftChecker::classificationOf($ruleId);
        if ($classification === null) {
            continue;
        }

        $reason = MicrosoftChecker::departureReason($ruleId);

        if ($severity === $classification->defaultSeverity()) {
            expect($reason)->toBeNull("$ruleId agrees with Microsoft but records a reason to differ");

            continue;
        }

        $departures++;
        expect($reason)->not->toBeNull("$ruleId is graded differently from Microsoft without saying why")
            ->and(strlen((string) $reason))->toBeGreaterThan(60, "$ruleId gives a reason too short to be one");
    }

    // A tripwire, not a target: if this number moves, a severity moved with it
    // and somebody should be able to say which and why.
    expect($departures)->toBe(7);
});

it('maps the three Microsoft levels onto four of ours, leaving one spare', function () {
    // Serious is the level with no Microsoft counterpart, which is what makes
    // it available for the rules Microsoft does not check at all.
    expect(MicrosoftClassification::Error->defaultSeverity())->toBe(Severity::Critical)
        ->and(MicrosoftClassification::Warning->defaultSeverity())->toBe(Severity::Moderate)
        ->and(MicrosoftClassification::Tip->defaultSeverity())->toBe(Severity::Minor);

    $mapped = array_map(
        static fn (MicrosoftClassification $c): Severity => $c->defaultSeverity(),
        MicrosoftClassification::cases(),
    );

    expect($mapped)->not->toContain(Severity::Serious);
});

it('can name the rule Word or PowerPoint would call this', function () {
    // So a report can say "Word calls this ..." rather than leaving somebody to
    // work out which checkbox in their own tool it corresponds to.
    expect(MicrosoftChecker::ruleNameOf('pptx.slide_missing_title'))->toBe('All slides have titles')
        ->and(MicrosoftChecker::ruleNameOf('docx.image_missing_alt'))
        ->toBe('All non-text content has alternative text (alt text)')
        ->and(MicrosoftChecker::ruleNameOf('docx.no_lang'))->toBeNull();
});

it('grades the one rule it disagrees with Word about more harshly, not less', function () {
    // A thirty-page document with no headings cannot be navigated at all by
    // somebody who cannot skim it by eye. Microsoft calls it a tip because Word
    // makes it easy to fix, which is a fact about the authoring tool.
    expect(MicrosoftChecker::classificationOf('docx.no_headings'))->toBe(MicrosoftClassification::Tip)
        ->and(DocxRule::NoHeadings->severity())->toBe(Severity::Serious)
        ->and(MicrosoftChecker::departureReason('docx.no_headings'))->toContain('navigated');
});

it('grades the rule it is least sure of below what Word gives it', function () {
    // Reading order: PowerPoint knows what its own layout means, we are
    // inferring it from coordinates.
    expect(MicrosoftChecker::classificationOf('pptx.reading_order'))->toBe(MicrosoftClassification::Warning)
        ->and(PptxRule::ReadingOrder->severity())->toBe(Severity::Minor)
        ->and(MicrosoftChecker::departureReason('pptx.reading_order'))->toContain('false positives');
});
