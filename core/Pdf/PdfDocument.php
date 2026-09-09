<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Pdf;

/**
 * Everything the PDF rules need from a document, in this project's vocabulary
 * rather than a library's.
 *
 * This is the seam. `PdfInspector` reads only this, so replacing the underlying
 * library — or adding one that can decrypt — means writing a new `PdfReader`
 * and changing nothing else.
 */
final readonly class PdfDocument
{
    /**
     * @param  list<FormField>  $formFields
     * @param  list<Figure>  $figures
     * @param  list<Font>  $fonts
     */
    public function __construct(
        public int $pageCount,
        public bool $isEncrypted = false,
        /** The /P permission bitfield, or null when it could not be read. */
        public ?int $permissions = null,
        public bool $hasStructTreeRoot = false,
        public bool $isMarked = false,
        public bool $hasLang = false,
        public ?string $lang = null,
        public bool $hasDisplayDocTitle = false,
        public ?string $infoTitle = null,
        public ?string $xmpTitle = null,
        public bool $hasOutlines = false,
        /** Non-whitespace characters recovered from the page content. */
        public int $textLength = 0,
        public int $imageCount = 0,
        public array $formFields = [],
        public array $figures = [],
        public array $fonts = [],
    ) {}

    /**
     * Tagged means both halves: a structure tree to describe the content, and
     * /MarkInfo saying the content stream is marked up to match it. A file with
     * only one of them is not usefully tagged.
     */
    public function isTagged(): bool
    {
        return $this->hasStructTreeRoot && $this->isMarked;
    }

    public function hasTitle(): bool
    {
        return trim((string) $this->infoTitle) !== '' || trim((string) $this->xmpTitle) !== '';
    }

    /**
     * Permission bit 10 (value 512) is "extract text and graphics for use by
     * assistive technology". Cleared, it tells a conforming reader to refuse a
     * screen reader access to the content.
     *
     * Null when the document is encrypted but /P could not be read — which is
     * not the same as the bit being set.
     */
    public function allowsAccessibilityExtraction(): ?bool
    {
        if (! $this->isEncrypted) {
            return true;
        }
        if ($this->permissions === null) {
            return null;
        }

        return ($this->permissions & 512) !== 0;
    }

    /** @return list<FormField> */
    public function unlabelledFormFields(): array
    {
        return array_values(array_filter(
            $this->formFields,
            static fn (FormField $field): bool => ! $field->isLabelled(),
        ));
    }

    /** @return list<Figure> */
    public function undescribedFigures(): array
    {
        return array_values(array_filter(
            $this->figures,
            static fn (Figure $figure): bool => ! $figure->isDescribed(),
        ));
    }

    /** @return list<Font> */
    public function fontsWithoutToUnicode(): array
    {
        return array_values(array_filter(
            $this->fonts,
            static fn (Font $font): bool => $font->needsToUnicode(),
        ));
    }
}
