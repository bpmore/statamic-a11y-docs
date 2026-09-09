<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Commands;

use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Reporting\HtmlReport;
use Bpmore\StatamicA11yDocs\Reporting\LibrarySummary;
use Bpmore\StatamicA11yDocs\Reporting\PdfRenderer;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * `php please docs:report`
 *
 * What the last scan found, without running another one.
 */
class ReportDocuments extends Command
{
    use RunsInPlease;

    protected $signature = 'docs:report
        {--json : Output the figures as JSON instead of tables}
        {--html= : Write an accessible HTML report to this path}
        {--pdf= : Write a tagged PDF report to this path}
        {--limit=10 : How many rules and offenders to list}';

    protected $description = 'Show what the last document scan found';

    public function handle(LibrarySummary $summary): int
    {
        if ($summary->total() === 0) {
            $this->components->warn('No documents have been checked yet. Run <comment>php please docs:check</comment>.');

            return static::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('html') || $this->option('pdf')) {
            return $this->export();
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'headline' => $summary->headline(),
                'total' => $summary->total(),
                'statuses' => $summary->statuses(),
                'formats' => $summary->byFormat(),
                'findings' => $summary->findingsBySeverity(),
                'rules' => $summary->topRules($limit),
                'engines' => $summary->engines(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return static::SUCCESS;
        }

        $this->newLine();
        $this->line('  <options=bold>'.$summary->headline().'</>');
        $this->newLine();

        $this->table(
            ['Format', 'Total', 'Pass', 'Fail', 'Error', 'Skipped', 'Unsupported'],
            collect($summary->byFormat())->map(fn (array $counts, string $format): array => [
                $format,
                number_format($counts['total']),
                number_format($counts['pass'] ?? 0),
                number_format($counts['fail'] ?? 0),
                number_format($counts['error'] ?? 0),
                number_format($counts['skipped'] ?? 0),
                number_format($counts['unsupported'] ?? 0),
            ])->values()->all(),
        );

        $this->table(
            ['Severity', 'Findings'],
            collect($summary->findingsBySeverity())
                ->map(fn (int $count, string $severity): array => [$severity, number_format($count)])
                ->values()->all(),
        );

        $rules = $summary->topRules($limit);

        if ($rules !== []) {
            // By document, not by finding: "890 documents are untagged" is the
            // sentence somebody acts on.
            $this->table(
                ['Rule', 'Documents'],
                collect($rules)->map(fn (int $count, string $rule): array => [$rule, number_format($count)])
                    ->values()->all(),
            );
        }

        $offenders = $summary->worstOffenders($limit);

        if ($offenders->isNotEmpty()) {
            $this->table(
                ['Document', 'Critical', 'All findings', 'Status'],
                $offenders->map(fn (DocumentCheck $check): array => [
                    $check->asset_id,
                    number_format((int) $check->critical_count),
                    number_format((int) $check->findings_count),
                    $check->status->value,
                ])->all(),
            );
        }

        // Spec §5: every report says which engine produced it, or it overstates
        // itself.
        foreach ($summary->engines() as $engine => $count) {
            $this->line(sprintf('  Checked by <comment>%s</comment> — %s document%s.',
                $engine, number_format($count), $count === 1 ? '' : 's'));
        }

        $this->newLine();

        return static::SUCCESS;
    }

    /**
     * Write the report out.
     *
     * HTML always, PDF when a browser is there to print it. A site with no
     * browser gets the HTML and a sentence saying why, rather than a failure.
     */
    private function export(): int
    {
        $html = app(HtmlReport::class)->render();

        if ($path = $this->option('html')) {
            file_put_contents($path, $html);
            $this->components->info("Accessible HTML report written to $path.");
        }

        if (! $path = $this->option('pdf')) {
            return static::SUCCESS;
        }

        $renderer = app(PdfRenderer::class);

        if (! $renderer->isAvailable()) {
            $this->components->warn(
                'No browser was found to render the PDF with. Install Chrome, or export the HTML instead.'
            );

            return static::FAILURE;
        }

        $renderer->render($html, $path);

        $this->components->info("Tagged PDF report written to $path.");

        return static::SUCCESS;
    }
}
