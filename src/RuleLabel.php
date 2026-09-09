<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs;

/**
 * How a rule id is written where a person reads it.
 *
 * Rule ids are stable identifiers — they key the database, the filters and the
 * `--rule` flag — but `pdf.ua_7_21_4_1_1` is not something to put in front of
 * somebody triaging a library. Every screen that shows a rule to a human goes
 * through here, so the dashboard and the queue cannot drift apart.
 *
 * Not to be confused with LibrarySummary's headline phrases, which are written
 * to follow a number ("3 untagged"). These stand on their own.
 */
final class RuleLabel
{
    /** A rule id as a standalone label. */
    public static function for(string $ruleId): string
    {
        [$family, $name] = array_pad(explode('.', $ruleId, 2), 2, '');

        // veraPDF's ids carry a PDF/UA clause number. That is a citation, and
        // spelling it as one is more use than either the raw id or a
        // prettified "ua 7 21 4 1 1".
        if (str_starts_with($name, 'ua_')) {
            return 'PDF/UA '.str_replace('_', '.', substr($name, 3));
        }

        // Where mechanical de-underscoring produces jargon rather than English.
        // Everything not listed reads well enough from the id itself, and a
        // full table would only be another thing to keep in step.
        $plain = match ($ruleId) {
            'pdf.no_lang', 'docx.no_lang' => 'No language set',
            'pdf.no_tounicode' => 'Text cannot be extracted',
            'pdf.image_only' => 'Image-only scan',
            'pdf.no_bookmarks' => 'Long, with no bookmarks',
            'pdf.extraction_blocked' => 'Blocks assistive technology',
            'pdf.figure_missing_alt' => 'Figure with no alt text',
            'docx.content_control_untitled' => 'Untitled form control',
            'docx.document_protected' => 'Locked against assistive technology',
            'xlsx.red_only_conditional_formatting' => 'Colour used on its own',
            default => null,
        };

        $prefix = match ($family) {
            'pdf' => 'PDF',
            'docx' => 'Word',
            'pptx' => 'PowerPoint',
            'xlsx' => 'Excel',
            default => ucfirst($family),
        };

        return $prefix.' · '.($plain ?? ucfirst(str_replace('_', ' ', $name)));
    }
}
