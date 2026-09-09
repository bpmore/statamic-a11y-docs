<?php

declare(strict_types=1);

namespace Bpmore\FixtureTools;

const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function xlsx_fixtures(): array
{
    return [
        xlsx_good(),
        xlsx_default_sheet_names(),
        xlsx_table_missing_header(),
        xlsx_drawing_missing_alt(),
        xlsx_red_only_formatting(),
        xlsx_alt_text_not_descriptive(),
    ];
}

function xlsx_cell_reference(int $column, int $row): string
{
    return chr(65 + $column).$row;
}

/**
 * @param list<array{
 *   name: string, rows: list<list<string>>,
 *   table?: array{name: string, header: bool},
 *   drawings?: list<array{name: string, descr: ?string}>,
 *   red_only?: bool
 * }> $sheets
 */
function xlsx_build(array $sheets, ?string $title): string
{
    /** @var array<string, string> part name => bytes */
    $parts = [];
    $needsMedia = false;

    $workbookRels = [];
    $sheetElements = '';
    $overrides = [
        '/xl/workbook.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        '/xl/styles.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml',
    ];

    foreach ($sheets as $index => $sheet) {
        $number = $index + 1;
        $relId = 'rId'.$number;
        $workbookRels[] = ['id' => $relId, 'type' => NS_REL.'/worksheet', 'target' => "worksheets/sheet$number.xml"];
        $sheetElements .= '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1 | ENT_QUOTES).'"'
            .' sheetId="'.$number.'" r:id="'.$relId.'"/>';
        $overrides["/xl/worksheets/sheet$number.xml"] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';

        $sheetRels = [];
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="'.XLSX_NS.'" xmlns:r="'.NS_REL.'"><sheetData>';

        foreach ($sheet['rows'] as $rowIndex => $cells) {
            $rowNumber = $rowIndex + 1;
            $sheetXml .= '<row r="'.$rowNumber.'">';
            foreach ($cells as $columnIndex => $value) {
                $reference = xlsx_cell_reference($columnIndex, $rowNumber);
                $sheetXml .= is_numeric($value)
                    ? '<c r="'.$reference.'"><v>'.$value.'</v></c>'
                    : '<c r="'.$reference.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
            }
            $sheetXml .= '</row>';
        }
        $sheetXml .= '</sheetData>';

        if ($sheet['red_only'] ?? false) {
            $lastRow = count($sheet['rows']);
            $sheetXml .= '<conditionalFormatting sqref="B2:B'.$lastRow.'">'
                .'<cfRule type="cellIs" dxfId="0" priority="1" operator="lessThan"><formula>0</formula></cfRule>'
                .'</conditionalFormatting>';
        }

        if (! empty($sheet['drawings'])) {
            $needsMedia = true;
            $drawingRelId = 'rId'.(count($sheetRels) + 1);
            $sheetRels[] = ['id' => $drawingRelId, 'type' => NS_REL.'/drawing', 'target' => "../drawings/drawing$number.xml"];
            $sheetXml .= '<drawing r:id="'.$drawingRelId.'"/>';

            $anchors = '';
            foreach ($sheet['drawings'] as $position => $drawing) {
                $descrAttribute = $drawing['descr'] === null
                    ? ''
                    : ' descr="'.htmlspecialchars($drawing['descr'], ENT_XML1 | ENT_QUOTES).'"';
                $row = 1 + ($position * 12);
                $anchors .= '<xdr:twoCellAnchor editAs="oneCell">'
                    .'<xdr:from><xdr:col>4</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$row.'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
                    .'<xdr:to><xdr:col>8</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.($row + 10).'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
                    .'<xdr:pic>'
                    .'<xdr:nvPicPr><xdr:cNvPr id="'.($position + 2).'" name="'.htmlspecialchars($drawing['name'], ENT_XML1 | ENT_QUOTES).'"'.$descrAttribute.'/>'
                    .'<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                    .'<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                    .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="2286000" cy="2286000"/></a:xfrm>'
                    .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
                    .'</xdr:pic><xdr:clientData/></xdr:twoCellAnchor>';
            }

            $parts["xl/drawings/drawing$number.xml"] = OOXML_DECL
                .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
                .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
                .' xmlns:r="'.NS_REL.'">'.$anchors.'</xdr:wsDr>';
            $parts["xl/drawings/_rels/drawing$number.xml.rels"] = ooxml_rels([
                ['id' => 'rId1', 'type' => NS_REL.'/image', 'target' => '../media/image1.png'],
            ]);
            $overrides["/xl/drawings/drawing$number.xml"] = 'application/vnd.openxmlformats-officedocument.drawing+xml';
        }

        if (isset($sheet['table'])) {
            $tableRelId = 'rId'.(count($sheetRels) + 1);
            $sheetRels[] = ['id' => $tableRelId, 'type' => NS_REL.'/table', 'target' => "../tables/table$number.xml"];
            $sheetXml .= '<tableParts count="1"><tablePart r:id="'.$tableRelId.'"/></tableParts>';

            $columns = $sheet['rows'][0];
            $reference = 'A1:'.xlsx_cell_reference(count($columns) - 1, count($sheet['rows']));
            $tableColumns = '';
            foreach ($columns as $position => $columnName) {
                $tableColumns .= '<tableColumn id="'.($position + 1).'" name="'
                    .htmlspecialchars((string) $columnName, ENT_XML1 | ENT_QUOTES).'"/>';
            }

            $parts["xl/tables/table$number.xml"] = OOXML_DECL
                .'<table xmlns="'.XLSX_NS.'" id="'.$number.'"'
                .' name="'.$sheet['table']['name'].'" displayName="'.$sheet['table']['name'].'"'
                .' ref="'.$reference.'" totalsRowShown="0"'
                .' headerRowCount="'.($sheet['table']['header'] ? 1 : 0).'">'
                .'<tableColumns count="'.count($columns).'">'.$tableColumns.'</tableColumns>'
                .'<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0"'
                .' showRowStripes="1" showColumnStripes="0"/>'
                .'</table>';
            $overrides["/xl/tables/table$number.xml"] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml';
        }

        $sheetXml .= '</worksheet>';
        $parts["xl/worksheets/sheet$number.xml"] = $sheetXml;
        if ($sheetRels !== []) {
            $parts["xl/worksheets/_rels/sheet$number.xml.rels"] = ooxml_rels($sheetRels);
        }
    }

    $workbookRels[] = ['id' => 'rId'.(count($sheets) + 1), 'type' => NS_REL.'/styles', 'target' => 'styles.xml'];

    $styles = OOXML_DECL
        .'<styleSheet xmlns="'.XLSX_NS.'">'
        .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
        .'<fill><patternFill patternType="gray125"/></fill></fills>'
        .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        // A single dxf that changes nothing but the font colour: red as the only signal.
        .'<dxfs count="1"><dxf><font><color rgb="FF9C0006"/></font></dxf></dxfs>'
        .'</styleSheet>';

    $defaults = [
        'rels' => 'application/vnd.openxmlformats-package.relationships+xml',
        'xml' => 'application/xml',
    ];
    if ($needsMedia) {
        $defaults['png'] = 'image/png';
        $parts['xl/media/image1.png'] = ooxml_png();
    }

    $overrides['/docProps/core.xml'] = 'application/vnd.openxmlformats-package.core-properties+xml';
    $overrides['/docProps/app.xml'] = 'application/vnd.openxmlformats-officedocument.extended-properties+xml';

    $package = new Zip;
    $package->add('[Content_Types].xml', ooxml_content_types($defaults, $overrides));
    $package->add('_rels/.rels', ooxml_rels([
        ['id' => 'rId1', 'type' => NS_REL.'/officeDocument', 'target' => 'xl/workbook.xml'],
        ['id' => 'rId2', 'type' => NS_PKG_REL.'/metadata/core-properties', 'target' => 'docProps/core.xml'],
        ['id' => 'rId3', 'type' => NS_REL.'/extended-properties', 'target' => 'docProps/app.xml'],
    ]));
    $package->add('xl/workbook.xml', OOXML_DECL
        .'<workbook xmlns="'.XLSX_NS.'" xmlns:r="'.NS_REL.'">'
        .'<sheets>'.$sheetElements.'</sheets></workbook>');
    $package->add('xl/_rels/workbook.xml.rels', ooxml_rels($workbookRels));
    $package->add('xl/styles.xml', $styles);

    // The per-sheet parts collected above, then the document properties.
    foreach ($parts as $name => $bytes) {
        $package->add($name, $bytes);
    }

    $package->add('docProps/core.xml', ooxml_core_properties($title));
    $package->add('docProps/app.xml', ooxml_app_properties('Microsoft Excel'));

    return $package->bytes();
}

function xlsx_good(): array
{
    return [
        'path' => 'xlsx/good.xlsx',
        'format' => 'xlsx',
        'status' => 'pass',
        'summary' => 'Named sheets, a table with a header row, and a described chart image.',
        'expect' => [],
        'expect_absent' => [
            'xlsx.default_sheet_name', 'xlsx.blank_sheet', 'xlsx.table_missing_header_row',
            'xlsx.drawing_missing_alt', 'xlsx.red_only_conditional_formatting',
        ],
        'notes' => 'The primary negative control for Excel.',
        'bytes' => xlsx_build([
            [
                'name' => 'Enrolment',
                'rows' => [['Course', 'Places'], ['History', 60], ['Physics', 45], ['Law', 80]],
                'table' => ['name' => 'Enrolment', 'header' => true],
                'drawings' => [['name' => 'Chart 1', 'descr' => 'Column chart: Law has the largest intake at 80 places.']],
            ],
            [
                'name' => 'Estates',
                'rows' => [['Building', 'Opened'], ['Library', 1974], ['Science Block', 2011]],
            ],
        ], 'Enrolment and Estates'),
    ];
}

function xlsx_default_sheet_names(): array
{
    return [
        'path' => 'xlsx/default-sheet-names.xlsx',
        'format' => 'xlsx',
        'status' => 'fail',
        'summary' => 'Three sheets: "Sheet1" with data, "Sheet2" with nothing in it, and a properly named third sheet.',
        'expect' => [
            ['rule' => 'xlsx.default_sheet_name', 'severity' => 'moderate', 'count' => 2],
            ['rule' => 'xlsx.blank_sheet', 'severity' => 'moderate', 'count' => 1],
        ],
        'expect_absent' => ['xlsx.table_missing_header_row', 'xlsx.drawing_missing_alt'],
        'notes' => 'Sheet2 is both default-named and blank, so it produces one finding under each rule. The named third sheet must produce neither.',
        'bytes' => xlsx_build([
            ['name' => 'Sheet1', 'rows' => [['Course', 'Places'], ['History', 60]]],
            ['name' => 'Sheet2', 'rows' => []],
            ['name' => 'Estates', 'rows' => [['Building', 'Opened'], ['Library', 1974]]],
        ], 'Workbook With Default Sheet Names'),
    ];
}

function xlsx_table_missing_header(): array
{
    return [
        'path' => 'xlsx/table-missing-header.xlsx',
        'format' => 'xlsx',
        'status' => 'fail',
        'summary' => 'Two tables. The first declares headerRowCount="1"; the second declares headerRowCount="0".',
        'expect' => [['rule' => 'xlsx.table_missing_header_row', 'severity' => 'serious', 'count' => 1]],
        'expect_absent' => ['xlsx.default_sheet_name', 'xlsx.blank_sheet', 'xlsx.drawing_missing_alt'],
        'notes' => 'The header row exists visually in both — row 1 holds the labels either way. Only the declaration differs, which is the whole point: the rule has to read the table part, not the cell values.',
        'bytes' => xlsx_build([
            [
                'name' => 'Enrolment',
                'rows' => [['Course', 'Places'], ['History', 60], ['Physics', 45]],
                'table' => ['name' => 'Enrolment', 'header' => true],
            ],
            [
                'name' => 'Estates',
                'rows' => [['Building', 'Opened'], ['Library', 1974], ['Science Block', 2011]],
                'table' => ['name' => 'Estates', 'header' => false],
            ],
        ], 'Tables'),
    ];
}

function xlsx_drawing_missing_alt(): array
{
    return [
        'path' => 'xlsx/drawing-missing-alt.xlsx',
        'format' => 'xlsx',
        'status' => 'fail',
        'summary' => 'One sheet with three drawings: described, descr="", and no descr attribute.',
        'expect' => [['rule' => 'xlsx.drawing_missing_alt', 'severity' => 'critical', 'count' => 2]],
        'expect_absent' => ['xlsx.default_sheet_name', 'xlsx.blank_sheet', 'xlsx.table_missing_header_row'],
        'notes' => 'Excel keeps drawings in a separate part reached through the worksheet relationships, so this fixture only passes once part resolution works. Critical, matching the Word and PowerPoint versions of the same rule and Microsoft, which covers all three applications with one rule — spec §6 lists this one as serious, which reads as an oversight beside its own grading of the other two.',
        'bytes' => xlsx_build([
            [
                'name' => 'Enrolment',
                'rows' => [['Course', 'Places'], ['History', 60], ['Physics', 45]],
                'drawings' => [
                    ['name' => 'Chart 1', 'descr' => 'Column chart: History 60 places, Physics 45 places.'],
                    ['name' => 'Chart 2', 'descr' => ''],
                    ['name' => 'Picture 3', 'descr' => null],
                ],
            ],
        ], 'Charts'),
    ];
}

function xlsx_red_only_formatting(): array
{
    return [
        'path' => 'xlsx/red-only-formatting.xlsx',
        'format' => 'xlsx',
        'status' => 'fail',
        'summary' => 'A cellIs/lessThan conditional format whose dxf changes only the font colour to red.',
        'expect' => [['rule' => 'xlsx.red_only_conditional_formatting', 'severity' => 'moderate', 'count' => 1]],
        'expect_absent' => ['xlsx.default_sheet_name', 'xlsx.blank_sheet', 'xlsx.drawing_missing_alt'],
        'notes' => 'Colour carrying meaning on its own. The dxf sets a font colour and nothing else — no fill, no bold, no number format — which is what makes it a failure rather than a decoration.',
        'bytes' => xlsx_build([
            [
                'name' => 'Budget',
                'rows' => [['Department', 'Variance'], ['Estates', -1200], ['Library', 800], ['IT', -450]],
                'red_only' => true,
            ],
        ], 'Budget Variance'),
    ];
}

function xlsx_alt_text_not_descriptive(): array
{
    return [
        'path' => 'xlsx/alt-text-not-descriptive.xlsx',
        'format' => 'xlsx',
        'status' => 'fail',
        'summary' => 'Three drawings, all with alt text: one describes the chart, one is a filename, one is the object\'s own name.',
        'expect' => [
            ['rule' => 'xlsx.alt_text_is_filename', 'severity' => 'critical', 'count' => 1],
            ['rule' => 'xlsx.alt_text_not_descriptive', 'severity' => 'critical', 'count' => 1],
        ],
        'expect_absent' => ['xlsx.drawing_missing_alt', 'xlsx.default_sheet_name', 'xlsx.blank_sheet'],
        'notes' => 'The pair to xlsx/drawing-missing-alt.xlsx: there every drawing is missing a description, here every drawing has one and two of them are worthless. A workbook can pass the presence check and fail a reader completely.',
        'bytes' => xlsx_build([
            [
                'name' => 'Enrolment',
                'rows' => [['Course', 'Places'], ['History', 60], ['Physics', 45]],
                'drawings' => [
                    ['name' => 'Chart 1', 'descr' => 'Column chart: History 60 places, Physics 45 places.'],
                    ['name' => 'Chart 2', 'descr' => 'chart2.png'],
                    ['name' => 'Picture 3', 'descr' => 'Picture 3'],
                ],
            ],
        ], 'Charts With Useless Descriptions'),
    ];
}
