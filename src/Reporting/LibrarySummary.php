<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Reporting;

use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Illuminate\Support\Collection;

/**
 * The numbers that describe a document library.
 *
 * Written once and used twice: `docs:report` prints them and the control panel
 * dashboard will draw them. They are the point of the product — spec §2 is
 * blunt that "you have 890 untagged PDFs and here they are" is the thing worth
 * paying for.
 */
final class LibrarySummary
{
    /** How many formats the headline names before folding the rest into a count. */
    private const HEADLINE_FORMATS = 3;

    /** How many findings phrases the headline carries. */
    private const HEADLINE_RULES = 2;

    /** How far down the rule list to look for that many describable rules. */
    private const HEADLINE_RULE_CANDIDATES = 12;

    /** @return array<string, int> */
    public function statuses(): array
    {
        $counts = [];
        foreach (Status::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach (DocumentCheck::query()->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            $counts[$row->status->value] = (int) $row->total;
        }

        return $counts;
    }

    public function total(): int
    {
        return DocumentCheck::count();
    }

    /** @return array<string, array<string, int>> format => status => count */
    public function byFormat(): array
    {
        $formats = [];

        foreach (DocumentCheck::query()->selectRaw('format, status, count(*) as total')->groupBy('format', 'status')->get() as $row) {
            $format = $row->format?->value ?? 'unknown';
            $formats[$format]['total'] = ($formats[$format]['total'] ?? 0) + (int) $row->total;
            $formats[$format][$row->status->value] = (int) $row->total;
        }

        ksort($formats);

        return $formats;
    }

    /** @return array<string, int> worst severity first, zeroes included */
    public function findingsBySeverity(): array
    {
        $counts = [];
        foreach (Severity::ordered() as $severity) {
            $counts[$severity->value] = 0;
        }

        foreach (DocumentFinding::query()->selectRaw('severity, count(*) as total')->groupBy('severity')->get() as $row) {
            $counts[$row->severity->value] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * The rules breaking the most documents.
     *
     * Counted by document rather than by finding, because "890 documents are
     * untagged" is the sentence somebody acts on and "4,200 findings" is not.
     *
     * @return array<string, int> rule id => documents affected
     */
    public function topRules(int $limit = 10): array
    {
        return DocumentFinding::query()
            ->selectRaw('rule_id, count(distinct check_id) as documents')
            ->groupBy('rule_id')
            ->orderByDesc('documents')
            ->orderBy('rule_id')
            ->limit($limit)
            ->pluck('documents', 'rule_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /** The documents with the most to fix, worst first. */
    public function worstOffenders(int $limit = 10): Collection
    {
        return DocumentCheck::query()
            ->withCount([
                'findings as critical_count' => fn ($query) => $query->where('severity', Severity::Critical->value),
                'findings as findings_count',
            ])
            ->needingAttention()
            ->orderByDesc('critical_count')
            ->orderByDesc('findings_count')
            ->orderBy('asset_id')
            ->limit($limit)
            ->get();
    }

    /**
     * Which engines produced the stored results.
     *
     * Shown wherever the numbers are, because a report that does not say
     * whether it used real PDF/UA validation or a set of heuristics is a report
     * that overstates itself.
     *
     * @return array<string, int>
     */
    public function engines(): array
    {
        // The label is built here rather than in SQL, because string
        // concatenation is spelled differently in every database and this is
        // not worth a driver conditional.
        $engines = [];

        foreach (
            DocumentCheck::query()
                ->whereNotNull('engine')
                ->selectRaw('engine, engine_version, count(*) as total')
                ->groupBy('engine', 'engine_version')
                ->get() as $row
        ) {
            $engines[trim($row->engine.' '.$row->engine_version)] = (int) $row->total;
        }

        arsort($engines);

        return $engines;
    }

    /**
     * The one line somebody reads first.
     *
     * Spec §9's example — "1,240 PDFs · 890 untagged · 210 image-only scans" —
     * built from whatever the library actually contains.
     */
    public function headline(): string
    {
        $total = $this->total();

        if ($total === 0) {
            return 'No documents have been checked yet.';
        }

        // Formats biggest first, so the largest liability leads the line the way
        // "1,240 PDFs" does. A long tail of one-off legacy files gets folded up
        // rather than crowding the findings out of the sentence.
        $formats = $this->byFormat();
        uasort($formats, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $parts = [];
        foreach (array_slice($formats, 0, self::HEADLINE_FORMATS, true) as $format => $counts) {
            $parts[] = number_format($counts['total']).' '.strtoupper($format).($counts['total'] === 1 ? '' : 's');
        }

        if (($rest = count($formats) - self::HEADLINE_FORMATS) > 0) {
            $parts[] = $rest.' other '.($rest === 1 ? 'format' : 'formats');
        }

        // Then what is actually wrong, in words somebody can act on. A rule with
        // no plain phrase is skipped rather than printed as its id — veraPDF's
        // clause numbers turn into 'with "ua 7 21 4 1 1"', which says nothing to
        // the person reading the dashboard.
        $named = 0;
        foreach ($this->topRules(self::HEADLINE_RULE_CANDIDATES) as $ruleId => $documents) {
            if ($named >= self::HEADLINE_RULES) {
                break;
            }
            if (($phrase = self::describeRule($ruleId)) === null) {
                continue;
            }
            $parts[] = number_format($documents).' '.$phrase;
            $named++;
        }

        return implode(' · ', $parts);
    }

    /**
     * A rule id as a phrase that reads after a number, or null when there is no
     * plain-words phrase for it.
     *
     * Null rather than a generated fallback on purpose: the id is not English,
     * and a headline is the one place it must not appear. veraPDF's clause rules
     * (`pdf.ua_7_1_8` and friends) are deliberately absent — a PDF/UA clause
     * number is a citation, not a description, and it belongs in the findings
     * list where the full clause text sits beside it.
     */
    private static function describeRule(string $ruleId): ?string
    {
        return match ($ruleId) {
            'pdf.not_tagged' => 'untagged',
            'pdf.image_only' => 'image-only scans',
            'pdf.no_title', 'docx.no_title' => 'without a title',
            'pdf.no_lang', 'docx.no_lang' => 'without a language',
            'pdf.title_not_displayed' => 'not showing their title',
            'pdf.extraction_blocked' => 'blocking assistive technology',
            'pdf.no_bookmarks' => 'long, without bookmarks',
            'pdf.unlabelled_form_fields' => 'with unlabelled form fields',
            'pdf.figure_missing_alt' => 'with figures missing alt text',
            'pdf.no_tounicode' => 'whose text cannot be extracted',

            'docx.image_missing_alt',
            'pptx.shape_missing_alt',
            'xlsx.drawing_missing_alt' => 'with images missing alt text',

            'docx.alt_text_is_filename',
            'pptx.alt_text_is_filename',
            'xlsx.alt_text_is_filename' => 'whose alt text is just a filename',

            'docx.alt_text_not_descriptive',
            'pptx.alt_text_not_descriptive',
            'xlsx.alt_text_not_descriptive' => 'with unhelpful alt text',

            'docx.table_missing_header_row',
            'xlsx.table_missing_header_row' => 'with tables missing a header row',

            'docx.no_headings' => 'without headings',
            'docx.complex_table' => 'with merged or split table cells',
            'docx.link_text_not_meaningful' => 'with unclear link text',
            'docx.document_protected' => 'locked against assistive technology',
            'docx.content_control_untitled' => 'with untitled form controls',

            'pptx.slide_missing_title' => 'with untitled slides',
            'pptx.media_without_captions' => 'with uncaptioned media',
            'pptx.duplicate_slide_titles' => 'with duplicate slide titles',
            'pptx.reading_order' => 'with a questionable reading order',
            'pptx.default_section_names' => 'with default section names',

            'xlsx.default_sheet_name' => 'with default sheet names',
            'xlsx.blank_sheet' => 'with blank sheets',
            'xlsx.red_only_conditional_formatting' => 'using colour alone to mark values',

            default => null,
        };
    }

    /** @return list<string> the formats this library actually contains */
    public function formats(): array
    {
        return array_values(array_filter(
            array_map(static fn (Format $format): string => $format->value, Format::cases()),
            fn (string $format): bool => array_key_exists($format, $this->byFormat()),
        ));
    }
}
