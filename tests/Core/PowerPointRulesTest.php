<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlInspector;
use Bpmore\DocumentA11yCore\Ooxml\PowerPoint\PptxRule;

it('produces exactly what the corpus expects', function (array $fixture) {
    $result = (new OoxmlInspector)->inspect(corpusPath($fixture['path']), Format::Pptx);

    expect($result->status->value)->toBe($fixture['status']);

    foreach ($fixture['expect'] as $expectation) {
        $found = $result->findingsFor($expectation['rule']);

        expect($found)->toHaveCount(
            $expectation['count'],
            "{$fixture['path']}: expected {$expectation['count']} × {$expectation['rule']}"
        );

        foreach ($found as $finding) {
            expect($finding->severity->value)->toBe($expectation['severity'])
                ->and(trim($finding->message))->not->toBe('');
        }
    }

    foreach ($fixture['expect_absent'] as $ruleId) {
        expect($result->findingsFor($ruleId))->toBeEmpty("{$fixture['path']}: $ruleId should not fire");
    }

    $expected = array_column($fixture['expect'], 'rule');
    $actual = array_unique(array_map(static fn (Finding $f): string => $f->ruleId, $result->findings));
    sort($expected);
    sort($actual);

    expect(array_values($actual))->toBe($expected, "{$fixture['path']}: unexpected rules fired");
})->with(array_map(
    static fn (array $fixture): array => [$fixture],
    corpusFixtures('pptx'),
));

it('covers every PowerPoint rule in the spec', function () {
    $fired = [];
    foreach (corpusFixtures('pptx') as $fixture) {
        foreach ((new OoxmlInspector)->inspect(corpusPath($fixture['path']), Format::Pptx)->findings as $finding) {
            $fired[$finding->ruleId] = true;
        }
    }

    expect(count($fired))->toBe(count(PptxRule::cases()));
});

it('agrees with the corpus about how severe each rule is', function () {
    $declared = [];
    foreach (corpusManifest()['fixtures'] as $fixture) {
        foreach ($fixture['expect'] as $expectation) {
            if (str_starts_with($expectation['rule'], 'pptx.')) {
                $declared[$expectation['rule']] = $expectation['severity'];
            }
        }
    }

    foreach ($declared as $ruleId => $severity) {
        expect(PptxRule::from($ruleId)->severity()->value)->toBe($severity, "severity of $ruleId");
    }
});

it('numbers slides the way the deck does, not the way filenames sort', function () {
    // slide10.xml sorts before slide2.xml. The order is p:sldIdLst's to declare.
    $result = (new OoxmlInspector)->inspect(corpusPath('pptx/slide-missing-title.pptx'), Format::Pptx);

    expect(array_map(
        static fn (Finding $f): ?string => $f->location?->describe(),
        $result->findingsFor('pptx.slide_missing_title'),
    ))->toBe(['slide 2', 'slide 3']);
});

it('treats an empty title placeholder as no title', function () {
    // Slide 3 has a title placeholder with nothing in it, which is the more
    // common mistake than leaving the placeholder out altogether.
    $result = (new OoxmlInspector)->inspect(corpusPath('pptx/slide-missing-title.pptx'), Format::Pptx);

    expect($result->findingsFor('pptx.slide_missing_title'))->toHaveCount(2);
});

it('reports duplicate titles once for the group', function () {
    $result = (new OoxmlInspector)->inspect(corpusPath('pptx/duplicate-slide-titles.pptx'), Format::Pptx);
    $finding = $result->findingsFor('pptx.duplicate_slide_titles')[0];

    expect($result->findingsFor('pptx.duplicate_slide_titles'))->toHaveCount(1)
        ->and($finding->message)->toContain('Slides 1, 2, 3')
        ->and($finding->message)->toContain('Results');
});

it('does not ask a text placeholder to describe itself', function () {
    // A title or body placeholder is read aloud as written. Flagging those
    // would put two findings on every slide in every deck ever made.
    $result = (new OoxmlInspector)->inspect(corpusPath('pptx/good.pptx'), Format::Pptx);

    expect($result->findings)->toBeEmpty();
});

it('checks pictures and drawn shapes alike', function () {
    // Alt text lives on p:cNvPr for both, and a rule that only walked pictures
    // would miss half of a real deck.
    $result = (new OoxmlInspector)->inspect(corpusPath('pptx/shape-missing-alt.pptx'), Format::Pptx);

    expect(array_map(
        static fn (Finding $f): ?string => $f->location?->describe(),
        $result->findingsFor('pptx.shape_missing_alt'),
    ))->toBe(['slide 2, Picture 2', 'slide 2, Rounded Rectangle 3']);
});

it('only reports a reading order it is sure about', function () {
    // Slide 1 has the same two shapes in the right order and must stay silent;
    // slide 2 puts the body before the title while the title sits above it.
    $result = (new OoxmlInspector)->inspect(corpusPath('pptx/reading-order.pptx'), Format::Pptx);

    expect($result->findingsFor('pptx.reading_order'))->toHaveCount(1)
        ->and($result->findingsFor('pptx.reading_order')[0]->location?->describe())->toBe('slide 2');
});

it('does not accuse a captioned video of being uncaptioned', function () {
    $result = (new OoxmlInspector)->inspect(corpusPath('pptx/media-without-captions.pptx'), Format::Pptx);
    $findings = $result->findingsFor('pptx.media_without_captions');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location?->describe())->toBe('slide 2')
        // Worded as what can actually be said: captions were not found.
        ->and($findings[0]->message)->toContain('no captions could be found');
});

it('reports default and repeated section names', function () {
    $result = (new OoxmlInspector)->inspect(corpusPath('pptx/default-section-names.pptx'), Format::Pptx);
    $findings = $result->findingsFor('pptx.default_section_names');

    expect($findings)->toHaveCount(3)
        ->and($findings[0]->message)->toContain('default name')
        ->and($findings[2]->message)->toContain('repeats the name')
        // The slide titles are all distinct, so a rule that confused sections
        // with slides would be visible here.
        ->and($result->findingsFor('pptx.duplicate_slide_titles'))->toBeEmpty();
});
