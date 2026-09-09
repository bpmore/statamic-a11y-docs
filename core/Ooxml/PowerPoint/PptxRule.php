<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml\PowerPoint;

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Severity;

/** The PowerPoint rules from spec §6, and how severe each one is. */
enum PptxRule: string
{
    case SlideMissingTitle = 'pptx.slide_missing_title';
    case ShapeMissingAlt = 'pptx.shape_missing_alt';
    case AltTextIsFilename = 'pptx.alt_text_is_filename';
    case AltTextNotDescriptive = 'pptx.alt_text_not_descriptive';
    case MediaWithoutCaptions = 'pptx.media_without_captions';
    case DuplicateSlideTitles = 'pptx.duplicate_slide_titles';
    case ReadingOrder = 'pptx.reading_order';
    case DefaultSectionNames = 'pptx.default_section_names';

    public function severity(): Severity
    {
        return match ($this) {
            self::SlideMissingTitle,
            self::ShapeMissingAlt,
            self::AltTextIsFilename,
            self::AltTextNotDescriptive => Severity::Critical,

            self::MediaWithoutCaptions => Severity::Moderate,

            // Microsoft calls these tips, and spec §12 asks for reading order
            // to ship as one too: it produces false positives on decorative
            // layouts, and a rule that cries wolf costs more than it finds.
            self::DuplicateSlideTitles,
            self::ReadingOrder,
            self::DefaultSectionNames => Severity::Minor,
        };
    }

    public function finding(string $message, ?Location $location = null): Finding
    {
        return new Finding($this->value, $this->severity(), $message, $location);
    }
}
