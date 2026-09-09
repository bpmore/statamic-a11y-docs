<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Http\Controllers;

use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Bpmore\StatamicA11yDocs\RuleLabel;
use Illuminate\Http\Request;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The list of things to fix, worst first.
 *
 * Findings rather than documents, because the unit of work is "this figure has
 * no alternative text", not "this file has eleven problems" — and because
 * filtering by rule is how somebody fixes fifty documents in an afternoon
 * rather than one at a time.
 */
class QueueController extends CpController
{
    public function index(Request $request): Response
    {
        $filters = [
            'severity' => $request->string('severity')->toString() ?: null,
            'rule' => $request->string('rule')->toString() ?: null,
            'format' => $request->string('format')->toString() ?: null,
            'container' => $request->string('container')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ];

        $findings = DocumentFinding::query()
            ->join('document_checks', 'document_checks.id', '=', 'document_findings.check_id')
            ->when($filters['severity'], fn ($query, $severity) => $query->where('document_findings.severity', $severity))
            ->when($filters['rule'], fn ($query, $rule) => $query->where('document_findings.rule_id', $rule))
            ->when($filters['format'], fn ($query, $format) => $query->where('document_checks.format', $format))
            ->when($filters['container'], fn ($query, $container) => $query->where('document_checks.container', $container))
            ->when($filters['status'], fn ($query, $status) => $query->where('document_checks.status', $status))
            // Worst first, then grouped by rule so somebody fixing one kind of
            // problem sees them together.
            ->orderByRaw($this->severityOrder())
            ->orderBy('document_findings.rule_id')
            ->orderBy('document_checks.asset_id')
            ->select([
                'document_findings.id',
                'document_findings.rule_id',
                'document_findings.severity',
                'document_findings.message',
                'document_findings.location',
                'document_checks.asset_id',
                'document_checks.path',
                'document_checks.container',
                'document_checks.format',
                'document_checks.status',
            ])
            ->paginate(50)
            ->withQueryString()
            // The table shows the rule in words, the same words the dashboard
            // and the filter use. The id stays the filter value and the URL
            // parameter, so nothing that addresses a rule has to change.
            ->through(fn (DocumentFinding $finding): array => [
                ...$finding->toArray(),
                'rule_label' => RuleLabel::for((string) $finding->rule_id),
            ]);

        return inertia('a11y-docs/Queue', [
            'findings' => $findings,
            'filters' => $filters,
            // Statamic's Select reads `label` and `value` off each option object.
            // Handed bare strings it renders a listbox of blank rows — the
            // filters looked like an empty white panel over the table.
            'options' => [
                'severities' => self::options(
                    array_map(fn (Severity $s): string => $s->value, Severity::ordered()),
                    static fn (string $value): string => ucfirst($value),
                ),
                'statuses' => self::options(
                    array_map(fn (Status $s): string => $s->value, Status::cases()),
                    static fn (string $value): string => ucfirst(str_replace('_', ' ', $value)),
                ),
                'rules' => self::options(
                    DocumentFinding::query()->distinct()->orderBy('rule_id')->pluck('rule_id')->all(),
                    RuleLabel::for(...),
                ),
                'formats' => self::options(
                    DocumentCheck::query()->distinct()->orderBy('format')->pluck('format')->all(),
                    static fn (string $value): string => strtoupper($value),
                ),
                'containers' => self::options(
                    DocumentCheck::query()->distinct()->orderBy('container')->pluck('container')->all(),
                    static fn (string $value): string => $value,
                ),
            ],
            'dashboardUrl' => cp_route('a11y-docs.dashboard'),
        ]);
    }

    /**
     * Filter options in the shape Statamic's Select expects.
     *
     * Values arrive as strings from some columns and as backed enums from the
     * ones the model casts, so they are flattened here rather than at each call.
     *
     * @param  list<string|\BackedEnum>  $values
     * @param  callable(string): string  $label
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $values, callable $label): array
    {
        return array_values(array_map(
            static function (string|\BackedEnum $value) use ($label): array {
                $value = $value instanceof \BackedEnum ? (string) $value->value : $value;

                return ['value' => $value, 'label' => $label($value)];
            },
            $values,
        ));
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
}
