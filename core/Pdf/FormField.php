<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Pdf;

/** An interactive form field, as its widget annotation presents it. */
final readonly class FormField
{
    public function __construct(
        public ?string $name,
        public ?string $label,
        public ?string $type = null,
        public ?int $page = null,
    ) {}

    /**
     * /TU is the field's description — what a screen reader announces. /T is a
     * name for the submitted data ("field2"), which is not a label, and
     * treating it as one would pass every unlabelled form ever built.
     */
    public function isLabelled(): bool
    {
        return $this->label !== null && trim($this->label) !== '';
    }

    /** Something to call the field in a finding, even when it has no name. */
    public function describe(): string
    {
        $name = trim((string) $this->name);

        return $name === '' ? 'an unnamed field' : "the field \"$name\"";
    }
}
