<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml;

/**
 * Which Microsoft Accessibility Checker rule each of ours corresponds to, and
 * where we grade it differently.
 *
 * Spec §6 grounds the Office rule sets in Microsoft's checker so that a finding
 * matches something the author can see and fix in the tool they wrote the
 * document in. This is the other half of that: the correspondence written down,
 * so a customer asking "Word calls this a tip, why do you call it serious?" gets
 * an answer, and so a severity can never quietly drift away from a reason.
 *
 * Rule names and classifications are taken verbatim from Microsoft's published
 * list. Every departure is deliberate and carries its reason; a test asserts
 * that a rule agreeing with Microsoft records no reason, that one disagreeing
 * records one, and that no rule of ours escapes the table altogether.
 */
final class MicrosoftChecker
{
    /**
     * @var array<string, array{rule: string, classification: MicrosoftClassification, departure: string|null}>
     */
    public const RULES = [
        // ---------------------------------------------------------- errors ---
        'docx.image_missing_alt' => [
            'rule' => 'All non-text content has alternative text (alt text)',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'docx.alt_text_is_filename' => [
            'rule' => 'All non-text content has alternative text (alt text)',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'docx.alt_text_not_descriptive' => [
            'rule' => 'All non-text content has alternative text (alt text)',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'pptx.shape_missing_alt' => [
            'rule' => 'All non-text content has alternative text (alt text)',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'pptx.alt_text_is_filename' => [
            'rule' => 'All non-text content has alternative text (alt text)',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'pptx.alt_text_not_descriptive' => [
            'rule' => 'All non-text content has alternative text (alt text)',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'xlsx.alt_text_is_filename' => [
            'rule' => 'All non-text content has alternative text (alt text)',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'xlsx.alt_text_not_descriptive' => [
            'rule' => 'All non-text content has alternative text (alt text)',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'xlsx.drawing_missing_alt' => [
            'rule' => 'All non-text content has alternative text (alt text)',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'pptx.slide_missing_title' => [
            'rule' => 'All slides have titles',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'docx.document_protected' => [
            'rule' => 'Document access is not restricted',
            'classification' => MicrosoftClassification::Error,
            'departure' => null,
        ],
        'docx.table_missing_header_row' => [
            'rule' => 'Tables specify column header information',
            'classification' => MicrosoftClassification::Error,
            'departure' => 'A table without a declared header row can still be read cell by cell. '
                .'What is lost is which column each value belongs to, which is a real barrier and '
                .'not an impassable one. Critical is kept for content that cannot be reached at all.',
        ],
        'xlsx.table_missing_header_row' => [
            'rule' => 'Tables specify column header information',
            'classification' => MicrosoftClassification::Error,
            'departure' => 'As for Word: the values are still readable, the column labels are what '
                .'goes missing.',
        ],
        'docx.content_control_untitled' => [
            'rule' => 'All content control fields have titles',
            'classification' => MicrosoftClassification::Error,
            'departure' => 'An unlabelled field can often still be completed by somebody who can '
                .'infer its purpose from the text around it. A barrier rather than a wall.',
        ],
        'xlsx.red_only_conditional_formatting' => [
            'rule' => "Cells in an Excel worksheet don't use red-only formatting for negative numbers",
            'classification' => MicrosoftClassification::Error,
            'departure' => 'The numbers are still there and still readable; what is lost is the '
                .'emphasis. Colour carrying meaning on its own is a genuine failure, but not one '
                .'that puts the data out of reach.',
        ],
        'pptx.default_section_names' => [
            'rule' => 'All sections have meaningful names / The section names in a deck are unique',
            'classification' => MicrosoftClassification::Error,
            'departure' => 'Microsoft splits this in two and grades the naming half an error. '
                .'Sections are an authoring convenience: PowerPoint exposes them while a deck is '
                .'being edited and not to anybody reading the presented result, so both halves are '
                .'graded as a tip here.',
        ],

        // -------------------------------------------------------- warnings ---
        'docx.complex_table' => [
            'rule' => 'Table has a simple structure',
            'classification' => MicrosoftClassification::Warning,
            'departure' => null,
        ],
        'xlsx.default_sheet_name' => [
            'rule' => 'Sheet tabs have meaningful names',
            'classification' => MicrosoftClassification::Warning,
            'departure' => null,
        ],
        'pptx.media_without_captions' => [
            'rule' => 'Closed captions are included for inserted audio and video',
            'classification' => MicrosoftClassification::Warning,
            'departure' => null,
        ],
        'pptx.reading_order' => [
            'rule' => 'The reading order of the objects on a slide presentation is logical',
            'classification' => MicrosoftClassification::Warning,
            'departure' => 'Graded a tip, deliberately below what Microsoft gives it. PowerPoint '
                .'knows what its own layout means; we are inferring it from shape coordinates, and '
                .'spec §12 warns that produces false positives on decorative layouts. A rule we '
                .'cannot make reliable is graded so it sorts beneath the ones we can.',
        ],

        // ------------------------------------------------------------ tips ---
        'pptx.duplicate_slide_titles' => [
            'rule' => 'Slide titles in a deck are unique',
            'classification' => MicrosoftClassification::Tip,
            'departure' => null,
        ],
        'docx.no_headings' => [
            'rule' => 'Documents use heading styles',
            'classification' => MicrosoftClassification::Tip,
            'departure' => 'Graded serious, three levels above Microsoft, and the one place this '
                .'product deliberately disagrees with Word outright. A thirty-page document with no '
                .'headings cannot be navigated at all by somebody who cannot skim it by eye — they '
                .'are left arrowing through it a line at a time. Microsoft grades it a tip because '
                .'Word makes it easy to fix, which is a fact about the authoring tool and no help '
                .'whatsoever to the person reading the result.',
        ],
    ];

    /**
     * Our rules that Microsoft's checker has no equivalent for.
     *
     * Listed rather than left out, so that adding a rule forces a decision
     * about where it sits — the alternative being a rule that quietly escapes
     * the comparison because nobody remembered it.
     *
     * @var array<string, string>
     */
    public const NO_EQUIVALENT = [
        'docx.link_text_not_meaningful' => 'Not on Microsoft\'s published list. WCAG 2.4.4 covers it '
            .'and it is one of the most common real problems in a document library, so it is checked '
            .'anyway.',
        'docx.no_title' => 'Word does not check whether a document has a title in its properties. It '
            .'is what a screen reader announces in place of the filename, so it is checked here.',
        'docx.no_lang' => 'Word does not check this either; WCAG 3.1.1 does, and a document read '
            .'aloud in the wrong language is unintelligible rather than merely awkward.',
        'xlsx.blank_sheet' => 'Microsoft checks for blank rows and columns inside a table but not for '
            .'an entirely empty sheet, which is one more tab to move past for no reason.',
    ];

    public static function classificationOf(string $ruleId): ?MicrosoftClassification
    {
        return self::RULES[$ruleId]['classification'] ?? null;
    }

    /** The name Word, PowerPoint or Excel gives this rule, for a report to quote. */
    public static function ruleNameOf(string $ruleId): ?string
    {
        return self::RULES[$ruleId]['rule'] ?? null;
    }

    /** Why our grading differs, or null when it does not. */
    public static function departureReason(string $ruleId): ?string
    {
        return self::RULES[$ruleId]['departure'] ?? null;
    }

    /** Is this rule accounted for at all — mapped, or explicitly not mappable? */
    public static function accountsFor(string $ruleId): bool
    {
        return isset(self::RULES[$ruleId]) || isset(self::NO_EQUIVALENT[$ruleId]);
    }
}
