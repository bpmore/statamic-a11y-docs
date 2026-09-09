<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Commands;

use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * `php please docs:check`
 *
 * Queues a scan by default. `--sync` runs it here, which is what a CI job wants
 * — along with `--fail-on`, which is the only thing that makes this command
 * exit non-zero.
 */
class CheckDocuments extends Command
{
    use RunsInPlease;

    protected $signature = 'docs:check
        {--sync : Check the documents now instead of queueing them}
        {--force : Re-check every document, even ones that have not changed}
        {--container=* : Limit the scan to these asset containers}
        {--fail-on= : Exit non-zero if anything at or above this severity is found (critical, serious, moderate, minor)}';

    protected $description = 'Check the documents in the asset library for accessibility problems';

    public function handle(DocumentScanner $scanner): int
    {
        $threshold = $this->threshold();

        if ($threshold === false) {
            return static::INVALID;
        }

        $containers = $this->option('container') ?: null;
        $force = (bool) $this->option('force');

        if (! $this->option('sync')) {
            $scan = $scanner->queue($containers, $force);

            if ($scan->documents === 0) {
                $this->components->warn('No documents found in the asset library.');

                return static::SUCCESS;
            }

            $this->components->info(sprintf(
                'Queued %s document%s in %s job%s.',
                number_format($scan->documents),
                $scan->documents === 1 ? '' : 's',
                number_format($scan->jobs),
                $scan->jobs === 1 ? '' : 's',
            ));
            $this->line('  Watch it with <comment>php please queue:work</comment>.');

            return static::SUCCESS;
        }

        return $this->runNow($scanner, $containers, $force, $threshold);
    }

    /** @param  list<string>|null  $containers */
    private function runNow(DocumentScanner $scanner, ?array $containers, bool $force, ?Severity $threshold): int
    {
        $counts = array_fill_keys(array_map(fn (Status $s): string => $s->value, Status::cases()), 0);
        $reusedCache = 0;
        $started = microtime(true);

        $checked = $scanner->run($containers, $force, function (DocumentCheck $check, string $id) use (&$counts, &$reusedCache): void {
            $counts[$check->status->value]++;

            if (! $check->wasChanged() && ! $check->wasRecentlyCreated) {
                $reusedCache++;
            }

            $this->line(sprintf(
                '  <fg=%s>%-11s</> %s',
                self::colour($check->status),
                $check->status->value,
                $id,
            ));
        });

        if ($checked === 0) {
            $this->components->warn('No documents found in the asset library.');

            return static::SUCCESS;
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Checked %s document%s in %.1fs.%s',
            number_format($checked),
            $checked === 1 ? '' : 's',
            microtime(true) - $started,
            // Spec §7: a 1,240-PDF library must not be reprocessed nightly, so
            // say how much of it was not.
            $reusedCache > 0 ? " {$reusedCache} unchanged since the last run." : '',
        ));

        foreach ($counts as $status => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %-12s %s', $status, number_format($count)));
            }
        }

        return $this->exitCode($threshold);
    }

    /** The CI exit code. Opt-in, because a library with 890 existing problems is the normal case. */
    private function exitCode(?Severity $threshold): int
    {
        if ($threshold === null) {
            return static::SUCCESS;
        }

        $failing = DocumentCheck::query()
            ->whereHas('findings', fn ($query) => $query->whereIn('severity', array_map(
                static fn (Severity $severity): string => $severity->value,
                array_filter(Severity::ordered(), static fn (Severity $s): bool => $s->isAtLeast($threshold)),
            )))
            ->count();

        if ($failing === 0) {
            $this->components->info("Nothing at or above {$threshold->value}.");

            return static::SUCCESS;
        }

        $this->newLine();
        $this->components->error(sprintf(
            '%s document%s %s findings at or above %s.',
            number_format($failing),
            $failing === 1 ? '' : 's',
            $failing === 1 ? 'has' : 'have',
            $threshold->value,
        ));

        return static::FAILURE;
    }

    /** @return Severity|null|false false when the option was given and is nonsense */
    private function threshold(): Severity|null|false
    {
        $value = $this->option('fail-on');

        if ($value === null || $value === '') {
            return null;
        }

        $severity = Severity::tryFrom(strtolower((string) $value));

        if ($severity === null) {
            $this->components->error(sprintf(
                'Unknown severity "%s". Expected one of: %s.',
                $value,
                implode(', ', array_map(static fn (Severity $s): string => $s->value, Severity::ordered())),
            ));

            return false;
        }

        return $severity;
    }

    private static function colour(Status $status): string
    {
        return match ($status) {
            Status::Pass => 'green',
            Status::Fail => 'red',
            Status::Error => 'yellow',
            Status::Skipped, Status::Unsupported => 'gray',
        };
    }
}
