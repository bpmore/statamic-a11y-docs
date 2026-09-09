<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

/**
 * How bad a finding is.
 *
 * The same four levels A11y Report uses, so the two products describe a site
 * and its documents in one vocabulary. Severity belongs to the rule, not to the
 * occurrence: every "image without alt text" is critical, wherever it is found.
 */
enum Severity: string
{
    /** The document is unusable for someone. An untagged PDF, an undescribed image. */
    case Critical = 'critical';

    /** A real barrier, navigable around. No language, no table header row. */
    case Serious = 'serious';

    /** Makes the document harder than it needs to be. No bookmarks in a long report. */
    case Moderate = 'moderate';

    /** Worth fixing, blocks nobody. Duplicate slide titles. */
    case Minor = 'minor';

    /** Highest first — the order a remediation queue should be worked in. */
    public const ORDER = [self::Critical, self::Serious, self::Moderate, self::Minor];

    /** Higher is worse. Only ever compared with another weight. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::Serious => 3,
            self::Moderate => 2,
            self::Minor => 1,
        };
    }

    /** At or above the given severity — the shape a gate threshold needs. */
    public function isAtLeast(self $threshold): bool
    {
        return $this->weight() >= $threshold->weight();
    }

    public function isWorseThan(self $other): bool
    {
        return $this->weight() > $other->weight();
    }

    /** @param  iterable<self>  $severities */
    public static function worst(iterable $severities): ?self
    {
        $worst = null;
        foreach ($severities as $severity) {
            if ($worst === null || $severity->isWorseThan($worst)) {
                $worst = $severity;
            }
        }

        return $worst;
    }

    /** All four, worst first. */
    public static function ordered(): array
    {
        return self::ORDER;
    }
}
