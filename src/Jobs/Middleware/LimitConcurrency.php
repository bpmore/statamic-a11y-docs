<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Lets at most N of these jobs run at once, across every worker.
 *
 * Laravel ships `WithoutOverlapping`, which allows exactly one. This is the
 * same idea with more than one slot, because checking documents is not
 * something to serialise entirely — but it is not something to let fifty
 * workers do at once either. Each veraPDF call starts a JVM, and fifty of those
 * will take a server down more reliably than any document ever could.
 */
final class LimitConcurrency
{
    public function __construct(
        private readonly int $limit,
        private readonly string $key = 'a11y-docs:scan',
        /** How long to wait before trying for a slot again. */
        private readonly int $releaseAfter = 15,
        /** Held no longer than this, so a worker killed mid-job frees its slot. */
        private readonly int $expiresAfter = 900,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        if ($this->limit < 1) {
            return $next($job);
        }

        for ($slot = 0; $slot < $this->limit; $slot++) {
            $lock = Cache::lock("{$this->key}:{$slot}", $this->expiresAfter);

            if (! $lock->get()) {
                continue;
            }

            try {
                return $next($job);
            } finally {
                $lock->release();
            }
        }

        // Every slot taken. Back on the queue rather than blocking a worker.
        $job->release($this->releaseAfter);

        return null;
    }
}
