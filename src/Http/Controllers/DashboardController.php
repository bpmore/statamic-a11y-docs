<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Http\Controllers;

use Bpmore\StatamicA11yDocs\Gate\PublishGate;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Reporting\LibrarySummary;
use Bpmore\StatamicA11yDocs\RuleLabel;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The screen that describes the past.
 *
 * Spec §8: the gate protects the future, the dashboard describes the past, and
 * on first run this has to say plainly that the backlog is not blocking
 * anything. A site that opens this and sees 890 problems with no context
 * assumes it is now broken.
 */
class DashboardController extends CpController
{
    public function index(LibrarySummary $summary): Response
    {
        $grandfathered = $this->grandfatheredCount();

        return inertia('a11y-docs/Dashboard', [
            'headline' => $summary->headline(),
            'total' => $summary->total(),
            'statuses' => $summary->statuses(),
            'formats' => $summary->byFormat(),
            'severities' => $summary->findingsBySeverity(),
            // Lists, not maps keyed by rule id or engine label. Those contain
            // dots — "pdf.not_tagged", "heuristics+verapdf 0.1.0+1.30.0" — and
            // anything reading this payload with a dot path, which is Inertia,
            // Laravel's Arr::get and every JS data_get, would read them as
            // nesting and find nothing.
            'rules' => collect($summary->topRules(10))
                ->map(fn (int $documents, string $rule): array => [
                    'rule' => $rule,
                    'label' => RuleLabel::for($rule),
                    'documents' => $documents,
                ])
                ->values(),
            'engines' => collect($summary->engines())
                ->map(fn (int $documents, string $engine): array => ['engine' => $engine, 'documents' => $documents])
                ->values(),
            'offenders' => $summary->worstOffenders(10)->map(fn (DocumentCheck $check): array => [
                'asset_id' => $check->asset_id,
                'path' => $check->path,
                'container' => $check->container,
                'format' => $check->format?->value,
                'status' => $check->status->value,
                'critical' => (int) $check->critical_count,
                'findings' => (int) $check->findings_count,
            ])->values(),
            // The sentence spec §8 asks for on first run, with the real number
            // in it: "We found 890 existing issues. Publishing isn't blocked
            // for these — here's your remediation queue."
            'grandfathered' => $grandfathered,
            'gate' => [
                'enabled' => (bool) config('a11y-docs.gate.enabled', true),
                'threshold' => (string) config('a11y-docs.gate.threshold', 'critical'),
                'grandfathering' => (bool) config('a11y-docs.gate.grandfather', true),
            ],
            'queueUrl' => cp_route('a11y-docs.queue'),
        ]);
    }

    /**
     * Documents that are failing but predate the gate.
     *
     * Counted from the checks rather than by asking each asset, because this is
     * a headline number and it has to load in one query, not 1,240.
     */
    private function grandfatheredCount(): int
    {
        if (! config('a11y-docs.gate.grandfather', true)) {
            return 0;
        }

        $since = app(PublishGate::class)->grandfatherDate();

        if ($since === null) {
            return 0;
        }

        return DocumentCheck::query()
            ->needingAttention()
            ->where('created_at', '<=', $since)
            ->count();
    }
}
