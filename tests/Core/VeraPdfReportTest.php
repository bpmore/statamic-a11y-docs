<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfReport;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfRule;

// Every report in tests/fixtures/verapdf was produced by veraPDF 1.30 against a
// fixture in the corpus, then had its absolute path rewritten. They are
// captured, not invented, which is the only reason the parser can be trusted.

it('reads the failed rules out of a real report', function () {
    $report = VeraPdfReport::fromJson(veraPdfReport('untagged'));

    expect($report->compliant)->toBeFalse()
        ->and($report->profileName)->toBe('PDF/UA-1 validation profile')
        ->and(array_map(static fn (VeraPdfRule $r): string => $r->id(), $report->rules))
        ->toBe(['7.1-8', '7.21.4.1-1', '6.2-1', '7.1-3', '7.1-11']);
});

it('keeps the detail a finding needs', function () {
    $rules = VeraPdfReport::fromJson(veraPdfReport('untagged'))->rules;
    $tagging = array_values(array_filter($rules, static fn (VeraPdfRule $r): bool => $r->id() === '7.1-11'))[0];

    expect($tagging->tags)->toBe(['structure'])
        ->and($tagging->description)->toStartWith('The logical structure of the conforming file')
        ->and($tagging->failedChecks)->toBe(1)
        ->and($tagging->contexts)->not->toBeEmpty();
});

it('turns an ISO clause into a rule identifier that survives validation', function () {
    expect((new VeraPdfRule('7.21.4.1', 1, 'x'))->ruleId())->toBe('pdf.ua_7_21_4_1_1')
        ->and((new VeraPdfRule('5', 1, 'x'))->ruleId())->toBe('pdf.ua_5_1')
        ->and((new VeraPdfRule('7.18.1', 3, 'x'))->ruleId())->toBe('pdf.ua_7_18_1_3');
});

it('recognises a file veraPDF could not read', function () {
    // A truncated PDF comes back with a taskException and no validationResult
    // at all — a shape with nothing in it that looks like a rule.
    $report = VeraPdfReport::fromJson(veraPdfReport('corrupt-truncated'));

    expect($report->parseFailure)->toContain('parse')
        ->and($report->rules)->toBeEmpty()
        ->and($report->compliant)->toBeFalse();
});

it('reads the version out of the report rather than assuming one', function () {
    expect(VeraPdfReport::versionFromJson(veraPdfReport('untagged')))->toBe('1.30.0');
});

it('refuses to read a report it does not understand', function (string $json) {
    // Silence here would turn a non-compliant document into a passing one,
    // which is the worst thing this code could do.
    expect(fn () => VeraPdfReport::fromJson($json))->toThrow(RuntimeException::class);
})->with([
    'not json' => ['<html>Service Unavailable</html>'],
    'no report' => ['{"something": "else"}'],
    'no jobs' => ['{"report": {"jobs": []}}'],
    'no validation result' => ['{"report": {"jobs": [{"itemDetails": {}}]}}'],
]);

it('accepts the older shape where the result is not wrapped in an array', function () {
    // veraPDF 1.30 emits validationResult as a one-element array; earlier
    // releases emit the object directly.
    $json = json_encode(['report' => ['jobs' => [['validationResult' => [
        'compliant' => false,
        'details' => ['ruleSummaries' => [[
            'status' => 'failed', 'clause' => '7.1', 'testNumber' => 3,
            'description' => 'Content shall be tagged', 'failedChecks' => 2, 'tags' => ['artifact'],
        ]]],
    ]]]]]);

    $report = VeraPdfReport::fromJson((string) $json);

    expect($report->rules)->toHaveCount(1)
        ->and($report->rules[0]->id())->toBe('7.1-3');
});
