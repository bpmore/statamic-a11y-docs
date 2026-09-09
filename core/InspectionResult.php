<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Everything one inspector learned about one document.
 *
 * The fields line up with the `document_checks` table, so the addon's job is to
 * write this down rather than to interpret it.
 *
 * Status is derived, not passed in: a checked document fails when it has
 * findings and passes when it does not. The three other statuses each have
 * their own constructor, because each needs a reason, and a result that says
 * "skipped" without saying why is a support ticket.
 */
final readonly class InspectionResult implements JsonSerializable
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<UncheckedRule>  $unchecked
     */
    private function __construct(
        public Status $status,
        public ?Engine $engine,
        public array $findings = [],
        public array $unchecked = [],
        public ?int $pageCount = null,
        public ?int $durationMs = null,
        public ?string $error = null,
    ) {}

    /**
     * The document was read. Findings decide the verdict.
     *
     * @param  list<Finding>  $findings
     * @param  list<UncheckedRule>  $unchecked  rules that could not be evaluated
     */
    public static function checked(
        Engine $engine,
        array $findings,
        array $unchecked = [],
        ?int $pageCount = null,
    ): self {
        foreach ($findings as $finding) {
            if (! $finding instanceof Finding) {
                throw new InvalidArgumentException('Findings must all be Finding instances.');
            }
        }
        foreach ($unchecked as $rule) {
            if (! $rule instanceof UncheckedRule) {
                throw new InvalidArgumentException('Unchecked rules must all be UncheckedRule instances.');
            }
        }

        return new self(
            status: $findings === [] ? Status::Pass : Status::Fail,
            engine: $engine,
            findings: array_values($findings),
            unchecked: array_values($unchecked),
            pageCount: $pageCount,
        );
    }

    /** The engine ran and could not read the file. The message goes in front of a person. */
    public static function error(Engine $engine, string $message): self
    {
        return new self(status: Status::Error, engine: $engine, error: self::requireReason($message, 'error'));
    }

    /** Deliberately not attempted — over the size cap, or excluded by configuration. */
    public static function skipped(string $reason): self
    {
        return new self(status: Status::Skipped, engine: null, error: self::requireReason($reason, 'skipped'));
    }

    /** A format this addon does not check. Nothing ran, so there is no engine. */
    public static function unsupported(string $reason): self
    {
        return new self(status: Status::Unsupported, engine: null, error: self::requireReason($reason, 'unsupported'));
    }

    /** Timing is the caller's to measure, so it is attached rather than passed in. */
    public function withDuration(int $milliseconds): self
    {
        return new self(
            $this->status,
            $this->engine,
            $this->findings,
            $this->unchecked,
            $this->pageCount,
            max(0, $milliseconds),
            $this->error,
        );
    }

    public function withPageCount(?int $pageCount): self
    {
        return new self(
            $this->status,
            $this->engine,
            $this->findings,
            $this->unchecked,
            $pageCount,
            $this->durationMs,
            $this->error,
        );
    }

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }

    public function worstSeverity(): ?Severity
    {
        return Severity::worst(array_map(
            static fn (Finding $finding): Severity => $finding->severity,
            $this->findings,
        ));
    }

    /** @return array<string, int> keyed by severity value, worst first, zeroes included */
    public function countsBySeverity(): array
    {
        $counts = [];
        foreach (Severity::ordered() as $severity) {
            $counts[$severity->value] = 0;
        }
        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /** @return list<Finding> */
    public function findingsFor(string $ruleId): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->ruleId === $ruleId,
        ));
    }

    /** @return list<Finding> */
    public function findingsAtLeast(Severity $threshold): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->isAtLeast($threshold),
        ));
    }

    /**
     * Findings worst first, then grouped by rule — the order a queue reads in.
     *
     * @return list<Finding>
     */
    public function sortedFindings(): array
    {
        $findings = $this->findings;
        usort($findings, static fn (Finding $a, Finding $b): int => [$b->severity->weight(), $a->ruleId]
            <=> [$a->severity->weight(), $b->ruleId]);

        return $findings;
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'engine' => $this->engine?->name,
            'engine_version' => $this->engine?->version,
            'page_count' => $this->pageCount,
            'duration_ms' => $this->durationMs,
            'error' => $this->error,
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->jsonSerialize(),
                $this->findings,
            ),
            'unchecked' => array_map(
                static fn (UncheckedRule $rule): array => $rule->jsonSerialize(),
                $this->unchecked,
            ),
        ];
    }

    private static function requireReason(string $reason, string $status): string
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException("A '$status' result has to say why.");
        }

        return $reason;
    }
}
