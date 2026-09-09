<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\VeraPdf;

/** One failed PDF/UA rule, as veraPDF reports it. */
final readonly class VeraPdfRule
{
    /**
     * @param  list<string>  $tags  veraPDF's own categories: structure, font,
     *                              metadata, alt-text, annotation, and so on
     * @param  list<string>  $contexts  where in the object tree each check failed
     */
    public function __construct(
        public string $clause,
        public int $testNumber,
        public string $description,
        public int $failedChecks = 1,
        public array $tags = [],
        public array $contexts = [],
    ) {}

    /** The clause as veraPDF writes it: "7.21.4.1-1". */
    public function id(): string
    {
        return "{$this->clause}-{$this->testNumber}";
    }

    /**
     * A rule identifier in this project's shape, traceable back to the ISO
     * clause it came from: "7.21.4.1-1" becomes "pdf.ua_7_21_4_1_1".
     */
    public function ruleId(): string
    {
        return 'pdf.ua_'.strtolower((string) preg_replace('/[^0-9a-z]+/i', '_', $this->id()));
    }
}
