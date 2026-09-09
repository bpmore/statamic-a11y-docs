<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\Inspector;
use Bpmore\DocumentA11yCore\Pdf\AugmentedPdfInspector;
use Bpmore\DocumentA11yCore\Pdf\PdfInspector;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfInspector;
use Bpmore\DocumentA11yCore\Version;

/**
 * Spec §5: the engine and its version go on every result, and every export says
 * which one ran. A report that does not distinguish real PDF/UA validation from
 * a set of heuristics overstates itself.
 *
 * These are the invariants that keep that true as more engines arrive.
 */
it('records an engine on every result that had one', function (array $fixture) {
    foreach ([
        'heuristics only' => new PdfInspector,
        'with validation' => new AugmentedPdfInspector(
            new PdfInspector,
            new VeraPdfInspector(fakeVeraPdf(veraPdfReport('untagged'))),
        ),
    ] as $label => $inspector) {
        $result = $inspector->inspect(corpusPath($fixture['path']), Format::Pdf);
        $serialised = $result->jsonSerialize();

        // pass, fail and error all mean an engine ran, and it has to be named.
        expect($result->engine)->not->toBeNull("$label: {$fixture['path']}")
            ->and($serialised['engine'])->toBe($result->engine->name)
            ->and($serialised['engine_version'])->toBe($result->engine->version);
    }
})->with(array_map(
    static fn (array $fixture): array => [$fixture],
    corpusFixtures('pdf'),
));

it('does not name an engine on a result where none ran', function () {
    // The other half of the invariant. Naming the engine that would have run is
    // exactly the overstatement spec §5 is written against.
    expect(InspectionResult::skipped('Over the size cap.')->engine)->toBeNull()
        ->and(InspectionResult::unsupported('Legacy Word files are not checked.')->engine)->toBeNull()
        ->and(InspectionResult::skipped('Over the size cap.')->jsonSerialize()['engine'])->toBeNull()
        ->and(InspectionResult::skipped('Over the size cap.')->jsonSerialize()['engine_version'])->toBeNull();
});

it('says which engines ran, separately enough to display them', function () {
    $engine = Engine::heuristicsWithVeraPdf('0.1.0', '1.30.0');

    expect(array_map(
        static fn (Engine $e): string => $e->describe(),
        $engine->components(),
    ))->toBe(['heuristics 0.1.0', 'verapdf 1.30.0']);

    // A single engine is one component, not a special case for callers.
    expect(Engine::heuristics('0.1.0')->components())->toHaveCount(1);
});

it('reports the version of the inspector it actually holds', function () {
    // Not a constant. An injected heuristics inspector reporting a different
    // version must not be misdescribed by the engine that wraps it.
    $ancient = new class implements Inspector
    {
        public function engine(): Engine
        {
            return Engine::heuristics('0.0.9-old');
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function supports(Format $format): bool
        {
            return true;
        }

        public function inspect(string $path, Format $format): InspectionResult
        {
            return InspectionResult::checked($this->engine(), []);
        }
    };

    $inspector = new AugmentedPdfInspector(
        $ancient,
        new VeraPdfInspector(fakeVeraPdf(veraPdfReport('untagged'))),
    );

    expect($inspector->engine()->version)->toBe('0.0.9-old+1.30.0');
});

it('refuses an engine that would not fit the column it is stored in', function () {
    // MySQL's strict mode rejects the whole row rather than truncating, so an
    // overlong engine name would lose an entire check result.
    expect(fn () => new Engine(str_repeat('e', Engine::MAX_NAME_LENGTH + 1), '1.0.0'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Engine('heuristics', str_repeat('9', Engine::MAX_VERSION_LENGTH + 1)))
        ->toThrow(InvalidArgumentException::class)
        // The names actually in use are nowhere near the limits.
        ->and(strlen(Engine::heuristicsWithVeraPdf(Version::CURRENT, '1.30.0')->name))
        ->toBeLessThan(Engine::MAX_NAME_LENGTH);
});

it('reports a version somebody could put in a conformance report', function () {
    expect(Version::CURRENT)->toMatch('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/');
});

it('flags authoritative validation wherever it contributed', function () {
    expect(Engine::heuristics('0.1.0')->isAuthoritative())->toBeFalse()
        ->and(Engine::veraPdf('1.30.0')->isAuthoritative())->toBeTrue()
        ->and(Engine::heuristicsWithVeraPdf('0.1.0', '1.30.0')->isAuthoritative())->toBeTrue();
});

it('records the engine even when the document could not be read', function () {
    // The engine ran and failed, which is different from no engine running.
    $result = (new PdfInspector)->inspect(corpusPath('pdf/corrupt-truncated.pdf'), Format::Pdf);

    expect($result->status)->toBe(Status::Error)
        ->and($result->engine?->name)->toBe('heuristics');
});
