<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\VeraPdf;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The only class in this package that starts an external process.
 *
 * Everything about running veraPDF that is surprising is commented here,
 * because all of it was found by running the real binary rather than by
 * reading its documentation.
 */
final class ProcessVeraPdfRunner implements VeraPdfRunner
{
    /** Resolved once: starting a JVM to ask its version costs about a second. */
    private ?string $version = null;

    private bool $versionResolved = false;

    public function __construct(
        private readonly VeraPdfOptions $options = new VeraPdfOptions,
    ) {}

    public function version(): ?string
    {
        if ($this->versionResolved) {
            return $this->version;
        }

        $this->versionResolved = true;

        try {
            $process = new Process([$this->options->binary, '--version']);
            $process->setTimeout(30);
            $process->run();
        } catch (\Throwable) {
            return $this->version = null;
        }

        // The JVM prints reflection warnings to stderr on modern Java, so the
        // version has to come out of stdout and stderr has to be ignored
        // entirely. Treating stderr as failure would report every working
        // install as broken.
        if (preg_match('/veraPDF\s+([0-9][0-9.]*)/i', $process->getOutput(), $match) !== 1) {
            return $this->version = null;
        }

        return $this->version = $match[1];
    }

    public function validate(string $path): string
    {
        $process = new Process([
            $this->options->binary,
            '-f', $this->options->profile,
            '--format', 'json',
            $path,
        ]);
        $process->setTimeout($this->options->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException(
                "veraPDF did not finish within {$this->options->timeoutSeconds} seconds."
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException('veraPDF could not be run: '.$exception->getMessage());
        }

        $output = trim($process->getOutput());

        // Exit status is a verdict, not an error: 0 is compliant, 1 is
        // non-compliant, and 4 and 7 mean it could not read the file — all of
        // which come with a usable JSON report. Only an empty or non-JSON
        // stdout means something actually went wrong.
        if ($output === '' || ! str_starts_with($output, '{')) {
            throw new RuntimeException(
                'veraPDF produced no report (exit status '.$process->getExitCode().').'
            );
        }

        return $output;
    }
}
