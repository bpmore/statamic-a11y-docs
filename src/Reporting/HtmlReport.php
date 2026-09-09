<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Reporting;

use Bpmore\StatamicA11yDocs\Gate\PublishGate;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\RuleLabel;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Statamic\Facades\Site;

/**
 * The exported report, as accessible HTML.
 *
 * HTML first and PDF second, because a tagged PDF is made by printing a
 * well-structured page — headings in order, real table headers with scopes, a
 * language, a title, and no meaning carried by colour alone. Get the HTML right
 * and the PDF follows; get it wrong and no amount of post-processing saves it.
 */
final class HtmlReport
{
    private const SEVERITY_MEANINGS = [
        'critical' => 'Somebody cannot read the document at all.',
        'serious' => 'A real barrier, though it can be worked around.',
        'moderate' => 'Makes the document harder to use than it needs to be.',
        'minor' => 'Worth fixing; blocks nobody.',
    ];

    public function __construct(
        private readonly LibrarySummary $summary,
        private readonly ViewFactory $views,
    ) {}

    public function render(): string
    {
        return $this->views->make('a11y-docs::report', $this->data())->render();
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return [
            'title' => __('Document Accessibility Report'),
            'site' => Site::current()->name(),
            'language' => str_replace('_', '-', Site::current()->lang() ?: 'en'),
            'generatedAt' => now()->toDayDateTimeString(),
            'headline' => $this->summary->headline(),
            'formats' => $this->summary->byFormat(),
            'severities' => $this->summary->findingsBySeverity(),
            'severityMeanings' => self::SEVERITY_MEANINGS,
            'rules' => collect($this->summary->topRules(15))
                ->map(fn (int $documents, string $rule): array => [
                    'rule' => $rule,
                    'label' => RuleLabel::for($rule),
                    'documents' => $documents,
                ])
                ->values(),
            'engines' => collect($this->summary->engines())
                ->map(fn (int $documents, string $engine): array => ['engine' => $engine, 'documents' => $documents])
                ->values(),
            'offenders' => $this->summary->worstOffenders(25)->map(fn (DocumentCheck $check): array => [
                'path' => $check->path,
                'container' => $check->container,
                'critical' => (int) $check->critical_count,
                'findings' => (int) $check->findings_count,
            ])->values(),
            'grandfathered' => $this->grandfathered(),
        ];
    }

    private function grandfathered(): int
    {
        if (! config('a11y-docs.gate.grandfather', true)) {
            return 0;
        }

        $since = app(PublishGate::class)->grandfatherDate();

        return $since === null ? 0 : DocumentCheck::query()
            ->needingAttention()
            ->where('created_at', '<=', $since)
            ->count();
    }
}
