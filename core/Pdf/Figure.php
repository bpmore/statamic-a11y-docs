<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Pdf;

/** A /Figure structure element, and whether anything describes it. */
final readonly class Figure
{
    public function __construct(
        public bool $hasAlt,
        public bool $hasActualText,
        public ?int $page = null,
    ) {}

    /**
     * /Alt describes the figure; /ActualText says what it literally reads as,
     * which is the right answer for a picture of a word. Either one counts.
     */
    public function isDescribed(): bool
    {
        return $this->hasAlt || $this->hasActualText;
    }
}
