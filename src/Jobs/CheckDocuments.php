<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Jobs;

use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\Jobs\Middleware\LimitConcurrency;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Statamic\Facades\Asset;

/**
 * Checks one chunk of documents.
 *
 * Chunked rather than one job per document because a library of 1,240 would
 * otherwise be 1,240 rows of queue overhead for work that takes a second each.
 * Chunked rather than one job for everything because a chunk is the unit that
 * can be retried, and because a scan you cannot watch progress through is a
 * scan people assume has hung.
 */
class CheckDocuments implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, SerializesModels;

    /** @param  list<string>  $assetIds */
    public function __construct(
        public readonly array $assetIds,
        public readonly bool $force = false,
        public readonly int $concurrency = 3,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new SkipIfBatchCancelled,
            new LimitConcurrency($this->concurrency),
        ];
    }

    public function handle(AssetChecker $checker): void
    {
        foreach ($this->assetIds as $id) {
            if ($this->batch()?->cancelled()) {
                return;
            }

            $asset = Asset::find($id);

            // Deleted between being enumerated and being checked. Not an error:
            // a scan of a live library takes minutes and people keep working.
            if ($asset === null) {
                continue;
            }

            // Never throws. Per-file isolation lives in the checker, so one bad
            // document cannot fail the chunk, let alone the run.
            $checker->check($asset, $this->force);
        }
    }
}
