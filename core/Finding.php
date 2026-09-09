<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

use InvalidArgumentException;
use JsonSerializable;

/**
 * One accessibility problem in one document.
 *
 * This is the normalised shape spec §5 asks for: PHP heuristics and veraPDF
 * both produce these, so nothing downstream — the dashboard, the queue, the
 * gate, the export, A11y Report's appendix — needs to know which engine ran.
 *
 * The fields line up with the `document_findings` table.
 */
final readonly class Finding implements JsonSerializable
{
    /**
     * Rule identifiers are `<format>.<rule_name>`: `pdf.not_tagged`,
     * `docx.image_missing_alt`. The prefix is what lets findings be grouped by
     * format without a lookup table, and the convention is enforced here rather
     * than trusted, because these strings end up in a database, a CSV export
     * and a published report.
     */
    private const RULE_ID = '/^[a-z][a-z0-9]*\.[a-z][a-z0-9_]*$/';

    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public string $message,
        public ?Location $location = null,
        public ?string $helpUrl = null,
    ) {
        if (preg_match(self::RULE_ID, $ruleId) !== 1) {
            throw new InvalidArgumentException(
                "Rule identifiers look like 'pdf.not_tagged'; got '$ruleId'."
            );
        }
        if (trim($message) === '') {
            throw new InvalidArgumentException(
                "The finding for '$ruleId' has no message. A finding nobody can act on is not a finding."
            );
        }
    }

    /** The format prefix of the rule identifier: `pdf`, `docx`, `pptx`, `xlsx`. */
    public function ruleFormat(): string
    {
        return strstr($this->ruleId, '.', true) ?: $this->ruleId;
    }

    public function isAtLeast(Severity $threshold): bool
    {
        return $this->severity->isAtLeast($threshold);
    }

    /** The finding plus where it is, as one line for a report row. */
    public function describe(): string
    {
        $location = $this->location?->describe();

        return $location === null || $location === ''
            ? $this->message
            : $this->message." ({$location})";
    }

    public function jsonSerialize(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'location' => $this->location?->jsonSerialize(),
            'help_url' => $this->helpUrl,
        ];
    }
}
