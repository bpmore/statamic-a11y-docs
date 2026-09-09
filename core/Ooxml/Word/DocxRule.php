<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml\Word;

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Severity;

/**
 * The Word rules from spec §6, and how severe each one is.
 *
 * Grounded in Microsoft's own Accessibility Checker, so a finding here matches
 * something the author can see and fix in the tool they wrote the document in.
 * That correspondence is most of the value: a finding an author cannot act on
 * in Word is a finding they will not act on.
 */
enum DocxRule: string
{
    case ImageMissingAlt = 'docx.image_missing_alt';
    case AltTextIsFilename = 'docx.alt_text_is_filename';
    case AltTextNotDescriptive = 'docx.alt_text_not_descriptive';
    case NoHeadings = 'docx.no_headings';
    case TableMissingHeaderRow = 'docx.table_missing_header_row';
    case ComplexTable = 'docx.complex_table';
    case LinkTextNotMeaningful = 'docx.link_text_not_meaningful';
    case NoTitle = 'docx.no_title';
    case NoLang = 'docx.no_lang';
    case DocumentProtected = 'docx.document_protected';
    case ContentControlUntitled = 'docx.content_control_untitled';

    public function severity(): Severity
    {
        return match ($this) {
            self::ImageMissingAlt,
            self::AltTextIsFilename,
            self::AltTextNotDescriptive,
            self::DocumentProtected => Severity::Critical,

            self::NoHeadings,
            self::TableMissingHeaderRow,
            self::LinkTextNotMeaningful,
            self::NoLang,
            self::ContentControlUntitled => Severity::Serious,

            self::ComplexTable,
            self::NoTitle => Severity::Moderate,
        };
    }

    public function finding(string $message, ?Location $location = null): Finding
    {
        return new Finding($this->value, $this->severity(), $message, $location);
    }
}
