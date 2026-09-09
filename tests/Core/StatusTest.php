<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Status;

it('treats only pass and fail as verdicts', function () {
    expect(Status::Pass->isConclusive())->toBeTrue()
        ->and(Status::Fail->isConclusive())->toBeTrue()
        ->and(Status::Error->isConclusive())->toBeFalse()
        ->and(Status::Skipped->isConclusive())->toBeFalse()
        ->and(Status::Unsupported->isConclusive())->toBeFalse();
});

it('puts everything that is not a pass in front of someone', function () {
    // A file that could not be read is not a file that is fine.
    expect(Status::Pass->needsAttention())->toBeFalse()
        ->and(Status::Error->needsAttention())->toBeTrue()
        ->and(Status::Unsupported->needsAttention())->toBeTrue();
});

it('uses the vocabulary the fixture corpus declares', function () {
    expect(array_map(static fn (Status $s): string => $s->value, Status::cases()))
        ->toBe(corpusManifest()['statuses']);
});
