<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml\Excel;

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Severity;

/** The Excel rules from spec §6, and how severe each one is. */
enum XlsxRule: string
{
    case TableMissingHeaderRow = 'xlsx.table_missing_header_row';
    case DrawingMissingAlt = 'xlsx.drawing_missing_alt';
    case AltTextIsFilename = 'xlsx.alt_text_is_filename';
    case AltTextNotDescriptive = 'xlsx.alt_text_not_descriptive';
    case DefaultSheetName = 'xlsx.default_sheet_name';
    case BlankSheet = 'xlsx.blank_sheet';
    case RedOnlyConditionalFormatting = 'xlsx.red_only_conditional_formatting';

    public function severity(): Severity
    {
        return match ($this) {
            self::TableMissingHeaderRow => Severity::Serious,

            // Alt text that says nothing leaves a reader exactly where no alt
            // text would, while looking fixed to anybody counting — so all
            // three grade together, and with the Word and PowerPoint versions
            // of the same rule.
            self::DrawingMissingAlt,
            self::AltTextIsFilename,
            self::AltTextNotDescriptive => Severity::Critical,

            // Microsoft calls these warnings.
            self::DefaultSheetName,
            self::BlankSheet,
            self::RedOnlyConditionalFormatting => Severity::Moderate,
        };
    }

    public function finding(string $message, ?Location $location = null): Finding
    {
        return new Finding($this->value, $this->severity(), $message, $location);
    }
}
