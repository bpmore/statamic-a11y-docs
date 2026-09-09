<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\DocumentA11yCore\UncheckedRule;

function engine(): Engine
{
    return Engine::heuristics('1.0.0');
}

function finding(string $ruleId, Severity $severity = Severity::Critical): Finding
{
    return new Finding($ruleId, $severity, "Something is wrong: $ruleId");
}

it('derives pass and fail from the findings rather than being told', function () {
    expect(InspectionResult::checked(engine(), [])->status)->toBe(Status::Pass)
        ->and(InspectionResult::checked(engine(), [finding('pdf.not_tagged')])->status)->toBe(Status::Fail);
});

it('keeps unchecked rules separate from findings', function () {
    // The encrypted-PDF case: the title rule could not run, and saying so is
    // not the same as saying the document has a title.
    $result = InspectionResult::checked(
        engine(),
        [],
        [new UncheckedRule('pdf.no_title', 'The document is encrypted, so its title cannot be read.')],
    );

    expect($result->status)->toBe(Status::Pass)
        ->and($result->hasFindings())->toBeFalse()
        ->and($result->unchecked)->toHaveCount(1)
        ->and($result->unchecked[0]->ruleId)->toBe('pdf.no_title');
});

it('reports the worst severity present', function () {
    $result = InspectionResult::checked(engine(), [
        finding('pdf.no_bookmarks', Severity::Moderate),
        finding('pdf.not_tagged', Severity::Critical),
        finding('pdf.no_lang', Severity::Serious),
    ]);

    expect($result->worstSeverity())->toBe(Severity::Critical)
        ->and(InspectionResult::checked(engine(), [])->worstSeverity())->toBeNull();
});

it('counts by severity with the empty levels included', function () {
    // A dashboard column that disappears when the count is zero is a bug.
    $result = InspectionResult::checked(engine(), [
        finding('pdf.not_tagged', Severity::Critical),
        finding('pdf.image_only', Severity::Critical),
        finding('pdf.no_lang', Severity::Serious),
    ]);

    expect($result->countsBySeverity())
        ->toBe(['critical' => 2, 'serious' => 1, 'moderate' => 0, 'minor' => 0]);
});

it('filters findings by rule and by threshold', function () {
    $result = InspectionResult::checked(engine(), [
        finding('pdf.unlabelled_form_fields', Severity::Serious),
        finding('pdf.unlabelled_form_fields', Severity::Serious),
        finding('pdf.no_bookmarks', Severity::Moderate),
    ]);

    expect($result->findingsFor('pdf.unlabelled_form_fields'))->toHaveCount(2)
        ->and($result->findingsFor('pdf.not_tagged'))->toBeEmpty()
        ->and($result->findingsAtLeast(Severity::Serious))->toHaveCount(2)
        ->and($result->findingsAtLeast(Severity::Critical))->toBeEmpty();
});

it('sorts findings worst first, then by rule', function () {
    $result = InspectionResult::checked(engine(), [
        finding('pdf.no_bookmarks', Severity::Moderate),
        finding('pdf.no_lang', Severity::Serious),
        finding('pdf.not_tagged', Severity::Critical),
        finding('pdf.image_only', Severity::Critical),
    ]);

    expect(array_map(static fn (Finding $f): string => $f->ruleId, $result->sortedFindings()))
        ->toBe(['pdf.image_only', 'pdf.not_tagged', 'pdf.no_lang', 'pdf.no_bookmarks']);
});

it('records an engine on every result that had one', function () {
    expect(InspectionResult::checked(engine(), [])->engine?->describe())->toBe('heuristics 1.0.0')
        ->and(InspectionResult::error(engine(), 'Unable to find startxref.')->engine?->describe())->toBe('heuristics 1.0.0')
        // Nothing ran on these two, so claiming an engine would be a fiction.
        ->and(InspectionResult::skipped('Larger than the 100 MB cap.')->engine)->toBeNull()
        ->and(InspectionResult::unsupported('Legacy Word documents are not checked.')->engine)->toBeNull();
});

it('insists that every non-verdict says why', function () {
    expect(fn () => InspectionResult::skipped('  '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InspectionResult::unsupported(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InspectionResult::error(engine(), ''))->toThrow(InvalidArgumentException::class);
});

it('takes its duration from the caller that measured it', function () {
    $result = InspectionResult::checked(engine(), [], [], 25)->withDuration(1_432);

    expect($result->durationMs)->toBe(1_432)
        ->and($result->pageCount)->toBe(25)
        ->and($result->status)->toBe(Status::Pass)
        ->and($result->withDuration(-5)->durationMs)->toBe(0);
});

it('serialises into the shape the checks table stores', function () {
    $result = InspectionResult::checked(
        engine(),
        [new Finding('pdf.not_tagged', Severity::Critical, 'This PDF is not tagged.', Location::page(1))],
        [new UncheckedRule('pdf.no_title', 'The document is encrypted.')],
        pageCount: 3,
    )->withDuration(48);

    expect($result->jsonSerialize())->toBe([
        'status' => 'fail',
        'engine' => 'heuristics',
        'engine_version' => '1.0.0',
        'page_count' => 3,
        'duration_ms' => 48,
        'error' => null,
        'findings' => [[
            'rule_id' => 'pdf.not_tagged',
            'severity' => 'critical',
            'message' => 'This PDF is not tagged.',
            'location' => ['page' => 1],
            'help_url' => null,
        ]],
        'unchecked' => [[
            'rule_id' => 'pdf.no_title',
            'reason' => 'The document is encrypted.',
        ]],
    ]);
});

it('rejects anything that is not a finding', function () {
    expect(fn () => InspectionResult::checked(engine(), ['pdf.not_tagged']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => InspectionResult::checked(engine(), [], ['nope']))
        ->toThrow(InvalidArgumentException::class);
});
