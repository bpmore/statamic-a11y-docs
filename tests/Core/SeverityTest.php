<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Severity;

it('orders severities worst first', function () {
    expect(array_map(static fn (Severity $s): string => $s->value, Severity::ordered()))
        ->toBe(['critical', 'serious', 'moderate', 'minor']);
});

it('compares severities', function () {
    expect(Severity::Critical->isWorseThan(Severity::Serious))->toBeTrue()
        ->and(Severity::Minor->isWorseThan(Severity::Moderate))->toBeFalse()
        ->and(Severity::Serious->isWorseThan(Severity::Serious))->toBeFalse();
});

it('answers the question a gate threshold asks', function () {
    // "Gate on critical only" is the shipped default, so the common case is a
    // threshold of Critical letting everything else through.
    expect(Severity::Critical->isAtLeast(Severity::Critical))->toBeTrue()
        ->and(Severity::Serious->isAtLeast(Severity::Critical))->toBeFalse()
        ->and(Severity::Serious->isAtLeast(Severity::Moderate))->toBeTrue();
});

it('finds the worst of a set', function () {
    expect(Severity::worst([Severity::Minor, Severity::Critical, Severity::Moderate]))
        ->toBe(Severity::Critical)
        ->and(Severity::worst([]))->toBeNull();
});

it('uses the vocabulary the fixture corpus declares', function () {
    expect(array_map(static fn (Severity $s): string => $s->value, Severity::ordered()))
        ->toBe(corpusManifest()['severities']);
});
