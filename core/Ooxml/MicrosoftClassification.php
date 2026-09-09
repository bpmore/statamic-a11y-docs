<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml;

use Bpmore\DocumentA11yCore\Severity;

/**
 * How Microsoft's own Accessibility Checker grades a rule.
 *
 * Three levels against our four, so the mapping cannot be a bijection and the
 * spare level has to be spent deliberately. Microsoft's definitions are:
 *
 * - **Error** — content that makes the file very difficult or impossible for
 *   someone with a disability to understand.
 * - **Warning** — content that in most, but not all, cases makes the file
 *   difficult to understand.
 * - **Tip** — content that people with disabilities can understand, but that
 *   could be better organised.
 *
 * Recorded because spec §6 grounds the Office rules in this checker: a finding
 * an author can see and fix in Word is worth several they cannot. Where our
 * grading differs from Microsoft's, {@see MicrosoftChecker} says why.
 */
enum MicrosoftClassification: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Tip = 'tip';

    /**
     * What a rule of this class is graded as here, absent a reason to differ.
     *
     * `Serious` is the level with no Microsoft counterpart, which is what makes
     * it available for the rules Microsoft does not check at all — language,
     * document title, link text — and for the handful where their grading and
     * ours genuinely disagree.
     */
    public function defaultSeverity(): Severity
    {
        return match ($this) {
            self::Error => Severity::Critical,
            self::Warning => Severity::Moderate,
            self::Tip => Severity::Minor,
        };
    }
}
