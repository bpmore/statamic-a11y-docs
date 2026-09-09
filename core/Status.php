<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

/**
 * The outcome of checking one document.
 *
 * The three non-verdicts matter as much as pass and fail. A library of 1,240
 * documents will contain files that cannot be opened, files too large to
 * process, and files in formats that predate the ones this addon understands.
 * Reporting those as passing would be a lie, and reporting them as failing
 * would bury the real failures.
 */
enum Status: string
{
    /** Checked, nothing found. */
    case Pass = 'pass';

    /** Checked, findings recorded. */
    case Fail = 'fail';

    /** Tried to check it and could not: corrupt, truncated, encrypted beyond reach. */
    case Error = 'error';

    /** Deliberately not checked: over the size cap, or excluded by configuration. */
    case Skipped = 'skipped';

    /** A format this addon does not check, such as legacy binary Office files. */
    case Unsupported = 'unsupported';

    /** Did the document actually get checked? Only pass and fail are verdicts. */
    public function isConclusive(): bool
    {
        return $this === self::Pass || $this === self::Fail;
    }

    /** Should this document appear in a remediation queue? */
    public function needsAttention(): bool
    {
        return $this !== self::Pass;
    }
}
