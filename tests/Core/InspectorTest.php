<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\Inspector;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;

/**
 * A stand-in that exercises the contract without pre-empting a real rule set.
 * It reports one finding for any file it can read and an error for one it
 * cannot, which is the whole shape an inspector has to fit.
 */
function stubInspector(): Inspector
{
    return new class implements Inspector
    {
        public function engine(): Engine
        {
            return Engine::heuristics('0.0.1-test');
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function supports(Format $format): bool
        {
            return $format === Format::Pdf;
        }

        public function inspect(string $path, Format $format): InspectionResult
        {
            if (! $this->supports($format)) {
                throw new InvalidArgumentException("This inspector does not handle {$format->value}.");
            }

            $contents = @file_get_contents($path);

            if ($contents === false || $contents === '') {
                return InspectionResult::error($this->engine(), 'The file is empty or could not be read.');
            }

            return InspectionResult::checked($this->engine(), [
                new Finding('pdf.not_tagged', Severity::Critical, 'This PDF is not tagged.'),
            ]);
        }
    };
}

it('declares which formats it handles', function () {
    expect(stubInspector()->supports(Format::Pdf))->toBeTrue()
        ->and(stubInspector()->supports(Format::Docx))->toBeFalse();
});

it('returns a result rather than throwing when a document is unreadable', function () {
    // One malformed file in a batch of 1,240 must not take the run down.
    $result = stubInspector()->inspect(corpusPath('misc/empty.pdf'), Format::Pdf);

    expect($result->status)->toBe(Status::Error)
        ->and($result->error)->not->toBeEmpty()
        ->and($result->engine?->name)->toBe('heuristics');
});

it('throws only when handed a format it said it does not support', function () {
    expect(fn () => stubInspector()->inspect(corpusPath('docx/good.docx'), Format::Docx))
        ->toThrow(InvalidArgumentException::class);
});

it('produces a result the rest of the system can store without interpreting it', function () {
    $result = stubInspector()->inspect(corpusPath('pdf/untagged.pdf'), Format::Pdf);

    expect($result->status)->toBe(Status::Fail)
        ->and($result->worstSeverity())->toBe(Severity::Critical)
        ->and(array_keys($result->jsonSerialize()))
        ->toBe(['status', 'engine', 'engine_version', 'page_count', 'duration_ms', 'error', 'findings', 'unchecked']);
});
