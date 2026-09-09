<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml\Excel;

use Bpmore\DocumentA11yCore\AltText;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlPackage;
use Bpmore\DocumentA11yCore\Ooxml\RuleSet;
use Bpmore\DocumentA11yCore\Ooxml\XmlPart;
use DOMElement;

/** The Excel rule set from spec §6. */
final class ExcelRules implements RuleSet
{
    /**
     * The names Excel gives a sheet nobody has renamed, in the languages it is
     * most often shipped in. A workbook of "Sheet1, Sheet2, Sheet3" tells a
     * screen reader user nothing about where they are in it.
     */
    private const DEFAULT_SHEET_PREFIXES = [
        'sheet', 'chart',      // English
        'blad',                // Dutch
        'hoja',                // Spanish
        'feuil', 'feuille',    // French
        'tabelle',             // German
        'foglio',              // Italian
        'ark',                 // Danish, Norwegian
        'list',                // Czech
        'sayfa',               // Turkish
        'planilha',            // Portuguese
    ];

    public function format(): Format
    {
        return Format::Xlsx;
    }

    /** @return list<Finding> */
    public function check(OoxmlPackage $package): array
    {
        $workbook = $package->requireXml($package->mainPart() ?? 'xl/workbook.xml');
        $findings = [];

        foreach ($this->sheets($package, $workbook) as $name => $part) {
            $sheet = $package->xml($part);

            $findings = [
                ...$findings,
                ...$this->sheetName($name),
                ...($sheet === null ? [] : $this->blankSheet($sheet, $name)),
                ...$this->tables($package, $part, $name),
                ...$this->drawings($package, $part, $name),
                ...($sheet === null ? [] : $this->conditionalFormatting($package, $sheet, $name)),
            ];
        }

        return $findings;
    }

    /**
     * Worksheet parts keyed by the name on the tab.
     *
     * Read through the workbook's relationships rather than by sorting part
     * names: the tab name is what a person sees and what a finding has to say,
     * and it lives nowhere near the part it belongs to.
     *
     * @return array<string, string>
     */
    private function sheets(OoxmlPackage $package, XmlPart $workbook): array
    {
        $main = $package->mainPart() ?? 'xl/workbook.xml';
        $sheets = [];

        foreach ($workbook->elements('/x:workbook/x:sheets/x:sheet') as $sheet) {
            $name = $workbook->attribute($sheet, 'name') ?? '';
            $id = $workbook->attribute($sheet, 'id', 'r');
            $target = $id === null ? null : $package->relationship($id, $main)?->target;

            if ($name !== '' && $target !== null) {
                $sheets[$name] = $target;
            }
        }

        return $sheets;
    }

    /** @return list<Finding> */
    private function sheetName(string $name): array
    {
        if (! $this->isDefaultName($name)) {
            return [];
        }

        return [XlsxRule::DefaultSheetName->finding(
            "The sheet \"$name\" still has the name Excel gave it. Sheet names are how somebody "
            .'moves around a workbook without seeing it, and a numbered one says nothing about '
            .'what is on it.',
            Location::sheet($name),
        )];
    }

    private function isDefaultName(string $name): bool
    {
        $trimmed = mb_strtolower(trim($name));

        foreach (self::DEFAULT_SHEET_PREFIXES as $prefix) {
            if (preg_match('/^'.preg_quote($prefix, '/').'\s*\d*$/u', $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Finding> */
    private function blankSheet(XmlPart $sheet, string $name): array
    {
        // A cell with a style but no content is still an empty cell. What makes
        // a sheet worth keeping is a value, an inline string or a formula.
        if ($sheet->exists('//x:sheetData/x:row/x:c[x:v or x:is or x:f]')) {
            return [];
        }

        return [XlsxRule::BlankSheet->finding(
            "The sheet \"$name\" is empty. An empty sheet in the tab list is one more thing to "
            .'move past, and it usually means it was left behind rather than meant.',
            Location::sheet($name),
        )];
    }

    /** @return list<Finding> */
    private function tables(OoxmlPackage $package, string $part, string $name): array
    {
        $findings = [];

        foreach ($package->relationshipsOfType('table', $part) as $relationship) {
            $table = $package->xml($relationship->target);
            $element = $table?->first('/x:table');

            if ($table === null || $element === null) {
                continue;
            }

            $headerRows = (int) ($table->attribute($element, 'headerRowCount') ?? 1);
            if ($headerRows > 0) {
                continue;
            }

            // Row 1 usually holds the labels either way. What differs is
            // whether the table says so, and only the declaration is what a
            // screen reader reads back with each cell.
            $tableName = $table->attribute($element, 'displayName')
                ?? $table->attribute($element, 'name')
                ?? 'a table';

            $findings[] = XlsxRule::TableMissingHeaderRow->finding(
                "The table \"$tableName\" has no header row, so a screen reader reads every cell "
                .'without saying which column it belongs to.',
                Location::sheet($name, $tableName),
            );
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function drawings(OoxmlPackage $package, string $part, string $name): array
    {
        $findings = [];

        foreach ($package->relationshipsOfType('drawing', $part) as $relationship) {
            $drawing = $package->xml($relationship->target);
            if ($drawing === null) {
                continue;
            }

            // Pictures, shapes and charts all record alt text the same way, on
            // the non-visual properties of whatever holds them.
            foreach ($drawing->elements('//xdr:cNvPr') as $properties) {
                $alt = $drawing->attribute($properties, 'descr');
                $label = $drawing->attribute($properties, 'name') ?? '';
                $location = Location::sheet($name, $label === '' ? null : $label);

                if (AltText::isMissing($alt)) {
                    $findings[] = XlsxRule::DrawingMissingAlt->finding(
                        'This has no alternative text. A chart is the one thing on a sheet that '
                        .'cannot be read cell by cell, so without a description it is simply missing.',
                        $location,
                    );

                    continue;
                }

                if (AltText::isFilename($alt)) {
                    $findings[] = XlsxRule::AltTextIsFilename->finding(
                        'The alternative text here is "'.trim((string) $alt).'", which is a filename '
                        .'rather than a description of what the chart shows.',
                        $location,
                    );

                    continue;
                }

                if (AltText::isPlaceholder($alt, $label === '' ? null : $label)) {
                    $findings[] = XlsxRule::AltTextNotDescriptive->finding(
                        'The alternative text here is "'.trim((string) $alt).'", which is the name '
                        .'Excel gave the object rather than a description of it.',
                        $location,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Conditional formatting whose only signal is a colour.
     *
     * The rule identifier says "red" because that is the case spec §6 names —
     * negative numbers in red — but colour alone is the problem whichever
     * colour it is, so the check is for a format that changes nothing else.
     *
     * @return list<Finding>
     */
    private function conditionalFormatting(OoxmlPackage $package, XmlPart $sheet, string $name): array
    {
        $styles = $package->xml('xl/styles.xml');
        if ($styles === null) {
            return [];
        }

        $formats = $styles->elements('/x:styleSheet/x:dxfs/x:dxf');
        $findings = [];
        $reported = [];

        foreach ($sheet->elements('//x:conditionalFormatting') as $block) {
            $range = $sheet->attribute($block, 'sqref') ?? 'a range of cells';

            foreach ($sheet->elements('x:cfRule', $block) as $rule) {
                $index = $sheet->attribute($rule, 'dxfId');
                if ($index === null || ! isset($formats[(int) $index])) {
                    continue;
                }
                if (! $this->isColourOnly($styles, $formats[(int) $index])) {
                    continue;
                }
                if (isset($reported[$range])) {
                    continue;
                }
                $reported[$range] = true;

                $findings[] = XlsxRule::RedOnlyConditionalFormatting->finding(
                    "The conditional formatting on $range changes only the colour of the text. "
                    .'Anybody who cannot distinguish those colours sees no difference at all, so '
                    .'the meaning needs to be carried by something else as well — a symbol, a '
                    .'word, or the number\'s own sign.',
                    Location::sheet($name, $range),
                );
            }
        }

        return $findings;
    }

    /** True when the format changes colour and nothing a reader could otherwise notice. */
    private function isColourOnly(XmlPart $styles, DOMElement $format): bool
    {
        $hasColour = $styles->exists('x:font/x:color', $format)
            || $styles->exists('x:fill//x:bgColor', $format)
            || $styles->exists('x:fill//x:fgColor', $format);

        if (! $hasColour) {
            return false;
        }

        // Bold, italic, underline, a border or a number format all survive
        // being unable to tell the colours apart.
        return ! $styles->exists('x:font/x:b', $format)
            && ! $styles->exists('x:font/x:i', $format)
            && ! $styles->exists('x:font/x:u', $format)
            && ! $styles->exists('x:font/x:strike', $format)
            && ! $styles->exists('x:numFmt', $format)
            && ! $styles->exists('x:border', $format);
    }
}
