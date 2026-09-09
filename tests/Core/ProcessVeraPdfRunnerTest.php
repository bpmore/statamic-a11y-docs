<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\VeraPdf\ProcessVeraPdfRunner;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfOptions;

// These use ordinary system binaries as stand-ins, so the process handling is
// exercised on machines with no Java. The end-to-end test against the real
// veraPDF lives in AugmentedPdfInspectorTest and skips when it is absent.

it('reports no version when the binary is not there', function () {
    $runner = new ProcessVeraPdfRunner(new VeraPdfOptions(binary: '/no/such/verapdf'));

    expect($runner->version())->toBeNull();
});

it('reports no version when the binary is not veraPDF', function () {
    // /bin/echo --version prints "--version", which is not a version string.
    // Accepting anything that runs would report a working install where there
    // is none, and then fail confusingly on the first document.
    expect((new ProcessVeraPdfRunner(new VeraPdfOptions(binary: '/bin/echo')))->version())->toBeNull();
});

it('rejects output that is not a report', function () {
    $runner = new ProcessVeraPdfRunner(new VeraPdfOptions(binary: '/bin/echo'));

    expect(fn () => $runner->validate('some.pdf'))
        ->toThrow(RuntimeException::class, 'produced no report');
});

it('gives up on a run that will not finish', function () {
    // A 400 MB PDF must not hold a queue worker open, and the only way to know
    // the timeout works is to let one happen. A stand-in that ignores its
    // arguments and blocks is the smallest thing that does that — /bin/sleep
    // will not, because it rejects the flags and exits before the clock starts.
    $script = tempnam(sys_get_temp_dir(), 'verapdf').'.sh';
    file_put_contents($script, "#!/bin/sh\nsleep 30\n");
    chmod($script, 0755);

    $runner = new ProcessVeraPdfRunner(new VeraPdfOptions(binary: $script, timeoutSeconds: 1));
    $started = microtime(true);

    try {
        expect(fn () => $runner->validate('some.pdf'))
            ->toThrow(RuntimeException::class, 'did not finish within 1 seconds')
            ->and(microtime(true) - $started)->toBeLessThan(10.0);
    } finally {
        @unlink($script);
    }
});

it('insists on sane limits', function () {
    expect(fn () => new VeraPdfOptions(timeoutSeconds: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new VeraPdfOptions(maxBytes: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new VeraPdfOptions(binary: '  '))->toThrow(InvalidArgumentException::class);
});
