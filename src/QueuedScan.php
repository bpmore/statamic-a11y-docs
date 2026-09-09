<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs;

use Illuminate\Bus\Batch;

/**
 * What a queued scan turned out to be.
 *
 * The batch knows how many jobs it holds and nothing about how many documents
 * they cover, and "queued 50 jobs" is not the number anybody wants. Counted
 * during enumeration, which is walking the list anyway.
 */
final readonly class QueuedScan
{
    public function __construct(
        public Batch $batch,
        public int $documents,
        public int $jobs,
    ) {}
}
