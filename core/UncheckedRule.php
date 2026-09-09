<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A rule that could not be evaluated on this document, and why.
 *
 * Not the same as a rule that passed, and the difference is not academic. The
 * PDF spike showed that an encrypted document reads its title as noise rather
 * than as absent: a title check run against it would report a document with a
 * perfectly good title as having none. The honest answer is that the check
 * could not be made, and the report has to be able to say so.
 *
 * A report that cannot distinguish "no problems found" from "could not look"
 * overstates itself, which is the one thing an accessibility report must not do.
 */
final readonly class UncheckedRule implements JsonSerializable
{
    public function __construct(
        public string $ruleId,
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                "Rule '$ruleId' was recorded as unchecked with no reason given."
            );
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'reason' => $this->reason,
        ];
    }
}
