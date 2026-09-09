<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

/**
 * Reads one document and reports what is wrong with it.
 *
 * One implementation per family: the PDF heuristics, the three OOXML rule sets,
 * the veraPDF adapter. They are interchangeable by design — spec §5 requires
 * that nothing downstream cares which engine produced a result.
 *
 * Implementations must not throw for a bad document. A malformed file is a
 * result with `Status::Error`, not an exception: one broken file in a batch of
 * 1,240 must not take the run down with it. Exceptions are for programming
 * errors — being handed a format the inspector said it does not support.
 */
interface Inspector
{
    /** Which engine this inspector reports, and at which version. */
    public function engine(): Engine;

    /**
     * Can this inspector actually run here?
     *
     * Almost always yes. veraPDF is the exception: it is an external Java
     * program that may not be installed, and spec §5 requires that its absence
     * is a quiet fall back to the heuristics rather than a failure.
     */
    public function isAvailable(): bool;

    public function supports(Format $format): bool;

    /**
     * @param  string  $path  an absolute path to a readable file
     * @param  Format  $format  as already detected by the caller, so it is
     *                          resolved once per document rather than per inspector
     */
    public function inspect(string $path, Format $format): InspectionResult;
}
