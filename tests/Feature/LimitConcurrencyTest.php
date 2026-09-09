<?php

declare(strict_types=1);

use Bpmore\StatamicA11yDocs\Jobs\Middleware\LimitConcurrency;

/** A stand-in job that records whether it ran and whether it was released. */
function fakeJob(): object
{
    return new class
    {
        public bool $ran = false;

        public ?int $releasedAfter = null;

        public function release(int $delay = 0): void
        {
            $this->releasedAfter = $delay;
        }
    };
}

it('lets a job through when a slot is free', function () {
    $job = fakeJob();

    (new LimitConcurrency(2, key: 'test:free'))->handle($job, function ($job) {
        $job->ran = true;
    });

    expect($job->ran)->toBeTrue()
        ->and($job->releasedAfter)->toBeNull();
});

it('gives the slot back afterwards', function () {
    // A slot held forever is a scan that stops after N chunks.
    $middleware = new LimitConcurrency(1, key: 'test:reuse');

    foreach (range(1, 3) as $attempt) {
        $job = fakeJob();
        $middleware->handle($job, fn ($job) => $job->ran = true);

        expect($job->ran)->toBeTrue("attempt $attempt should have found a free slot");
    }
});

it('gives the slot back even when the job throws', function () {
    $middleware = new LimitConcurrency(1, key: 'test:throws');

    expect(fn () => $middleware->handle(fakeJob(), function () {
        throw new RuntimeException('the document exploded');
    }))->toThrow(RuntimeException::class);

    // If the failure had leaked the slot, this would be released instead of run.
    $next = fakeJob();
    $middleware->handle($next, fn ($job) => $job->ran = true);

    expect($next->ran)->toBeTrue();
});

it('puts a job back on the queue when every slot is taken', function () {
    // Rather than blocking a worker on a lock. Each veraPDF call starts a JVM,
    // and fifty at once will take a server down more reliably than any document.
    $middleware = new LimitConcurrency(2, key: 'test:full', releaseAfter: 15);

    $held = fakeJob();
    $blocked = fakeJob();

    // Two slots, both occupied by the time the third job asks.
    $middleware->handle($held, function () use ($middleware, $blocked) {
        $middleware->handle(fakeJob(), function () use ($middleware, $blocked) {
            $middleware->handle($blocked, fn ($job) => $job->ran = true);
        });
    });

    expect($blocked->ran)->toBeFalse()
        ->and($blocked->releasedAfter)->toBe(15);
});

it('does not limit anything when the limit is off', function () {
    $middleware = new LimitConcurrency(0, key: 'test:off');
    $job = fakeJob();

    $middleware->handle($job, function () use ($middleware, $job) {
        // Re-entrant while the outer call is still running.
        $middleware->handle(fakeJob(), fn () => null);
        $job->ran = true;
    });

    expect($job->ran)->toBeTrue()
        ->and($job->releasedAfter)->toBeNull();
});
