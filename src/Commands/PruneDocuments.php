<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Commands;

use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentExemption;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Asset;

/**
 * `php please docs:prune`
 *
 * Removes stored results for documents that are no longer in the library.
 * Without it a dashboard slowly fills with files nobody can find.
 */
class PruneDocuments extends Command
{
    use RunsInPlease;

    protected $signature = 'docs:prune
        {--dry-run : List what would be removed without removing it}';

    protected $description = 'Remove stored results for documents that no longer exist';

    public function handle(DocumentScanner $scanner): int
    {
        // Enumerated from the library rather than looked up one at a time: a
        // disk call per stored row would be thousands of them to answer one
        // question.
        $live = $scanner->documents()->flip()->all();

        $gone = DocumentCheck::query()
            ->pluck('asset_id')
            ->reject(fn (string $id): bool => array_key_exists($id, $live))
            // Then confirmed one at a time, which is cheap because the
            // candidates are few. Statamic caches a container's file listing,
            // so an enumeration that has gone stale — or a container whose
            // cache has not been built — could otherwise delete the results for
            // documents that are still perfectly well there.
            ->filter(fn (string $id): bool => ! (Asset::find($id)?->exists() ?? false))
            ->values();

        if ($gone->isEmpty()) {
            $this->components->info('Nothing to prune. Every stored result still has a document.');

            return $this->reportOrphanedExemptions($live);
        }

        foreach ($gone as $id) {
            $this->line("  <fg=gray>{$id}</>");
        }

        if ($this->option('dry-run')) {
            $this->components->info(sprintf(
                '%s result%s would be removed. Run without --dry-run to remove %s.',
                number_format($gone->count()),
                $gone->count() === 1 ? '' : 's',
                $gone->count() === 1 ? 'it' : 'them',
            ));

            return $this->reportOrphanedExemptions($live);
        }

        // Findings go with their check: the foreign key cascades.
        $removed = DocumentCheck::query()->whereIn('asset_id', $gone)->delete();

        $this->components->info(sprintf(
            'Removed %s result%s.',
            number_format($removed),
            $removed === 1 ? '' : 's',
        ));

        return $this->reportOrphanedExemptions($live);
    }

    /**
     * Exemptions are never pruned, only reported.
     *
     * Spec §8 asks for an audit trail, and a trail that deletes itself when the
     * document goes is not one — "who exempted the thing that is no longer
     * there, and why" is exactly the question an auditor asks.
     *
     * @param  array<string, int>  $live
     */
    private function reportOrphanedExemptions(array $live): int
    {
        $orphaned = DocumentExemption::query()
            ->pluck('asset_id')
            ->unique()
            ->reject(fn (string $id): bool => array_key_exists($id, $live))
            ->count();

        if ($orphaned > 0) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%s exemption%s %s a document that no longer exists.',
                number_format($orphaned),
                $orphaned === 1 ? '' : 's',
                $orphaned === 1 ? 'refers to' : 'refer to',
            ));
            $this->line('  Kept on purpose: they are the audit trail.');
        }

        return static::SUCCESS;
    }
}
