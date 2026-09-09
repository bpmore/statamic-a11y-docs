<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

/**
 * The version reported as `engine_version` on every heuristics result.
 *
 * Deliberately a constant rather than a lookup through Composer: in a monorepo
 * checkout Composer reports the root package, which is a branch name, and
 * "engine_version: dev-build/phase-1" is not something to print in a
 * conformance report.
 *
 * **Bump this when inspection behaviour changes — not when the package is
 * tagged.** The two are not the same thing, and conflating them is expensive:
 * `AssetChecker` counts `engine_version` towards cache validity, so moving it
 * re-inspects every document in the library. On a release that only changes
 * wording, that is a full re-scan of somebody's 1,240 documents to arrive at
 * byte-identical findings.
 *
 * It also has to stay honest. `engine_version` is printed in conformance
 * reports as the thing that produced the numbers, so two releases whose
 * inspection logic is identical should report the same engine, and results
 * from either should be comparable.
 *
 * Rule of thumb: if nothing under `core/` changed, this does not move. 1.0.1
 * changed a gate message and a report label, and left this at 1.0.0.
 */
final class Version
{
    public const CURRENT = '1.0.0';
}
