<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\VeraPdf;

use RuntimeException;

/**
 * Runs the external veraPDF binary.
 *
 * Separated from the parsing and the rule mapping so those can be tested
 * against captured reports on a machine with no Java on it — which is most
 * machines, and is the whole reason this engine is optional.
 */
interface VeraPdfRunner
{
    /** The version string, or null when veraPDF is not installed or not runnable. */
    public function version(): ?string;

    /**
     * The raw JSON report for one file.
     *
     * @throws RuntimeException when the binary could not be run, timed out, or
     *                          produced something that is not a report
     */
    public function validate(string $path): string;
}
