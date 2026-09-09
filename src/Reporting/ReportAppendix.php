<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Reporting;

use Bpmore\DocumentA11yCore\Severity;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Bpmore\StatamicA11yDocs\RuleLabel;

/**
 * Document findings, shaped for A11y Report's conformance report.
 *
 * **As an appendix, never folded into the conformance table.** Spec §10 is
 * emphatic and correct: document conformance is PDF/UA and Section 508
 * Chapter 5 territory, not WCAG success criteria. Merging a PDF's missing tags
 * into SC 1.3.1 is a category error a knowledgeable auditor catches on sight,
 * and it would put the credibility of the whole report in question over a
 * detail nobody needed to get wrong.
 *
 * So this produces a clearly labelled, self-contained section: what was
 * checked, what was found, which engine found it, and the known issues — and it
 * says in its own words what it is not.
 */
final class ReportAppendix
{
    public const HEADING = 'Appendix: document accessibility';

    /**
     * What this appendix covers, in the words that keep it out of the
     * conformance table.
     */
    public const SCOPE = 'These findings describe documents in the asset library — PDF, Word, '
        .'PowerPoint and Excel files — against PDF/UA and Section 508 Chapter 5. They are not '
        .'WCAG success criteria and are deliberately reported separately from the conformance '
        .'table above.';

    public function __construct(
        private readonly LibrarySummary $summary,
        /** Matches A11y Report's own appendix cap, so the two read alike. */
        private readonly int $limit = 1000,
    ) {}

    /**
     * The appendix, or null when there is nothing to say.
     *
     * The entry point A11y Report calls. Static and dependency-free on purpose:
     * the calling side should need one guarded line and no knowledge of this
     * addon's container bindings.
     *
     * @return array<string, mixed>|null
     */
    public static function forReport(?int $limit = null): ?array
    {
        $appendix = new self(app(LibrarySummary::class), $limit ?? (int) config('a11y-docs.report.appendix_limit', 1000));

        return $appendix->isEmpty() ? null : $appendix->data();
    }

    public function isEmpty(): bool
    {
        return $this->summary->total() === 0;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $issues = $this->knownIssues();

        return [
            'heading' => self::HEADING,
            'scope' => self::SCOPE,
            'generated_at' => now()->toIso8601String(),
            'documents' => [
                'total' => $this->summary->total(),
                'by_format' => $this->summary->byFormat(),
                'by_status' => $this->summary->statuses(),
            ],
            'findings' => [
                'total' => array_sum($this->summary->findingsBySeverity()),
                'by_severity' => $this->summary->findingsBySeverity(),
            ],
            // Spec §5: the report says which engine produced it, or it
            // overstates itself — and this appendix is the part of somebody
            // else's report that would be overstating.
            'engines' => collect($this->summary->engines())
                ->map(fn (int $documents, string $engine): array => [
                    'engine' => $engine,
                    'documents' => $documents,
                    'authoritative' => str_contains($engine, 'verapdf'),
                ])->values()->all(),
            'known_issues' => $issues['issues'],
            'known_issues_omitted' => $issues['omitted'],
        ];
    }

    /**
     * The known-issues list, worst first and capped.
     *
     * Capped the way A11y Report caps its own appendices, and reporting what it
     * left out for the same reason: a list that silently stops is a list that
     * misleads about how much there is.
     *
     * @return array{issues: list<array<string, mixed>>, omitted: int}
     */
    private function knownIssues(): array
    {
        $total = DocumentFinding::query()->count();

        $issues = DocumentFinding::query()
            ->join('document_checks', 'document_checks.id', '=', 'document_findings.check_id')
            ->orderByRaw($this->severityOrder())
            ->orderBy('document_checks.asset_id')
            ->orderBy('document_findings.rule_id')
            ->limit($this->limit)
            ->get([
                'document_findings.rule_id',
                'document_findings.severity',
                'document_findings.message',
                'document_findings.location',
                'document_checks.path',
                'document_checks.container',
                'document_checks.format',
            ])
            ->map(fn (DocumentFinding $finding): array => [
                'document' => $finding->path,
                'container' => $finding->container,
                'format' => $finding->format,
                'rule' => $finding->rule_id,
                'rule_label' => RuleLabel::for((string) $finding->rule_id),
                'severity' => $finding->severity->value,
                'message' => $finding->message,
                'where' => $finding->locatedAt()?->describe(),
            ])
            ->all();

        return ['issues' => $issues, 'omitted' => max(0, $total - count($issues))];
    }

    /** Worst severity first. Stored as words, so the order has to be spelled out. */
    private function severityOrder(): string
    {
        $cases = [];
        foreach (Severity::ordered() as $index => $severity) {
            $cases[] = sprintf("when '%s' then %d", $severity->value, $index);
        }

        return 'case document_findings.severity '.implode(' ', $cases).' else 99 end';
    }

    /** Documents needing attention, for a report that wants the headline only. */
    public function needingAttention(): int
    {
        return DocumentCheck::query()->needingAttention()->count();
    }
}
