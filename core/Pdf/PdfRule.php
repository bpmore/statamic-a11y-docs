<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Pdf;

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Severity;

/**
 * The ten PDF rules from spec §4, and how severe each one is.
 *
 * This exists because there are now two things that produce them — the PHP
 * heuristics and the veraPDF adapter, which maps seven PDF/UA clauses onto
 * these same identifiers. Two producers hardcoding the same severities would
 * drift, and a document whose severity changed depending on whether Java
 * happened to be installed would be indefensible.
 */
enum PdfRule: string
{
    case NotTagged = 'pdf.not_tagged';
    case NoTitle = 'pdf.no_title';
    case TitleNotDisplayed = 'pdf.title_not_displayed';
    case NoLang = 'pdf.no_lang';
    case ImageOnly = 'pdf.image_only';
    case ExtractionBlocked = 'pdf.extraction_blocked';
    case NoBookmarks = 'pdf.no_bookmarks';
    case UnlabelledFormFields = 'pdf.unlabelled_form_fields';
    case FigureMissingAlt = 'pdf.figure_missing_alt';
    case NoToUnicode = 'pdf.no_tounicode';

    public function severity(): Severity
    {
        return match ($this) {
            self::NotTagged,
            self::ImageOnly,
            self::ExtractionBlocked => Severity::Critical,

            self::NoTitle,
            self::TitleNotDisplayed,
            self::NoLang,
            self::UnlabelledFormFields,
            self::FigureMissingAlt => Severity::Serious,

            self::NoBookmarks,
            self::NoToUnicode => Severity::Moderate,
        };
    }

    public function finding(string $message, ?Location $location = null): Finding
    {
        return new Finding($this->value, $this->severity(), $message, $location);
    }
}
