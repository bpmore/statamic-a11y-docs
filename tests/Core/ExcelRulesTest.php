<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Ooxml\Excel\ExcelRules;
use Bpmore\DocumentA11yCore\Ooxml\Excel\XlsxRule;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlInspector;
use Bpmore\DocumentA11yCore\Ooxml\XmlPart;

it('produces exactly what the corpus expects', function (array $fixture) {
    $result = (new OoxmlInspector)->inspect(corpusPath($fixture['path']), Format::Xlsx);

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
    corpusFixtures('xlsx'),
));

it('covers every Excel rule in the spec', function () {
    $fired = [];
    foreach (corpusFixtures('xlsx') as $fixture) {
        foreach ((new OoxmlInspector)->inspect(corpusPath($fixture['path']), Format::Xlsx)->findings as $finding) {
            $fired[$finding->ruleId] = true;
        }
    }

    expect(count($fired))->toBe(count(XlsxRule::cases()));
});

it('agrees with the corpus about how severe each rule is', function () {
    $declared = [];
    foreach (corpusManifest()['fixtures'] as $fixture) {
        foreach ($fixture['expect'] as $expectation) {
            if (str_starts_with($expectation['rule'], 'xlsx.')) {
                $declared[$expectation['rule']] = $expectation['severity'];
            }
        }
    }

    foreach ($declared as $ruleId => $severity) {
        expect(XlsxRule::from($ruleId)->severity()->value)->toBe($severity, "severity of $ruleId");
    }
});

it('names the sheet a finding is on', function () {
    // The tab name is what somebody sees, and it lives in the workbook part,
    // nowhere near the worksheet it belongs to.
    $result = (new OoxmlInspector)->inspect(corpusPath('xlsx/default-sheet-names.xlsx'), Format::Xlsx);

    expect(array_map(
        static fn (Finding $f): ?string => $f->location?->describe(),
        $result->findingsFor('xlsx.default_sheet_name'),
    ))->toBe(['sheet Sheet1', 'sheet Sheet2'])
        ->and($result->findingsFor('xlsx.blank_sheet')[0]->location?->describe())->toBe('sheet Sheet2');
});

it('reads the table part rather than the cell values', function () {
    // Row 1 holds the labels in both tables. Only the declaration differs, and
    // only the declaration is what a screen reader reads back with each cell.
    $result = (new OoxmlInspector)->inspect(corpusPath('xlsx/table-missing-header.xlsx'), Format::Xlsx);
    $findings = $result->findingsFor('xlsx.table_missing_header_row');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location?->describe())->toBe('sheet Estates, Estates');
});

it('follows the worksheet through to its drawing part', function () {
    // Drawings live in their own part, reached through the worksheet's
    // relationships, so this only works once part resolution does.
    $result = (new OoxmlInspector)->inspect(corpusPath('xlsx/drawing-missing-alt.xlsx'), Format::Xlsx);

    expect(array_map(
        static fn (Finding $f): ?string => $f->location?->describe(),
        $result->findingsFor('xlsx.drawing_missing_alt'),
    ))->toBe(['sheet Enrolment, Chart 2', 'sheet Enrolment, Picture 3']);
});

it('only reports conditional formatting that is actually in use', function () {
    // Every workbook this project generates carries a red dxf in its styles.
    // A rule that keyed off the style rather than off a rule using it would
    // fire on all five Excel fixtures.
    $good = (new OoxmlInspector)->inspect(corpusPath('xlsx/good.xlsx'), Format::Xlsx);
    $red = (new OoxmlInspector)->inspect(corpusPath('xlsx/red-only-formatting.xlsx'), Format::Xlsx);

    expect($good->findings)->toBeEmpty()
        ->and($red->findingsFor('xlsx.red_only_conditional_formatting'))->toHaveCount(1)
        ->and($red->findingsFor('xlsx.red_only_conditional_formatting')[0]->location?->describe())
        ->toBe('sheet Budget, B2:B4');
});

it('does not flag formatting that carries its meaning some other way', function () {
    // Bold, a border or a number format all survive being unable to tell the
    // colours apart, so colour is no longer the only signal.
    $rules = new ExcelRules;
    $styles = new ReflectionMethod($rules, 'isColourOnly');

    // No tap(): that is a Laravel helper, and this package does not have one.
    $document = new DOMDocument;
    $document->loadXML(
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dxfs>'
        .'<dxf><font><color rgb="FF9C0006"/></font></dxf>'
        .'<dxf><font><color rgb="FF9C0006"/><b/></font></dxf>'
        .'<dxf><font><color rgb="FF9C0006"/></font><border><left/></border></dxf>'
        .'<dxf><numFmt numFmtId="164" formatCode="0.00;[Red]-0.00"/></dxf>'
        .'</dxfs></styleSheet>'
    );

    $part = new XmlPart('styles', $document);

    $formats = $part->elements('//x:dxf');

    expect($styles->invoke($rules, $part, $formats[0]))->toBeTrue()
        ->and($styles->invoke($rules, $part, $formats[1]))->toBeFalse()
        ->and($styles->invoke($rules, $part, $formats[2]))->toBeFalse()
        ->and($styles->invoke($rules, $part, $formats[3]))->toBeFalse();
});

it('recognises the default sheet names Excel ships in other languages', function () {
    $rules = new ExcelRules;
    $isDefault = new ReflectionMethod($rules, 'isDefaultName');

    foreach (['Sheet1', 'sheet 2', 'Feuil1', 'Tabelle3', 'Hoja1', 'Blad1', 'Chart1'] as $name) {
        expect($isDefault->invoke($rules, $name))->toBeTrue("'$name' is a default name");
    }

    foreach (['Enrolment', 'Estates', 'Budget', 'Sheet music', '2026 Sheet1'] as $name) {
        expect($isDefault->invoke($rules, $name))->toBeFalse("'$name' is not a default name");
    }
});

it('now handles all three OOXML formats', function () {
    // The claim spec §3 makes, finished: one reader, three rule sets.
    $inspector = new OoxmlInspector;

    expect($inspector->supports(Format::Docx))->toBeTrue()
        ->and($inspector->supports(Format::Pptx))->toBeTrue()
        ->and($inspector->supports(Format::Xlsx))->toBeTrue()
        ->and($inspector->supports(Format::Pdf))->toBeFalse();
});
