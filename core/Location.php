<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Where in a document a finding is.
 *
 * "890 untagged PDFs" gets someone's attention; "page 14, Figure" is what lets
 * them fix it. Every format locates things differently — PDF by page,
 * PowerPoint by slide, Excel by sheet, Word by nothing much at all — so this
 * carries whichever of those applies plus a free-text element name.
 *
 * Page and slide numbers are 1-based, as a reader counts them.
 */
final readonly class Location implements JsonSerializable
{
    public ?string $sheet;

    public ?string $element;

    private function __construct(
        public ?int $page = null,
        public ?int $slide = null,
        ?string $sheet = null,
        ?string $element = null,
    ) {
        // An empty name is not a name. Normalising here means callers can pass
        // whatever the document gave them without guarding first.
        $this->sheet = self::clean($sheet);
        $this->element = self::clean($element);

        if ($page !== null && $page < 1) {
            throw new InvalidArgumentException("Page numbers are 1-based; got $page.");
        }
        if ($slide !== null && $slide < 1) {
            throw new InvalidArgumentException("Slide numbers are 1-based; got $slide.");
        }
        if ($page === null && $slide === null && $this->sheet === null && $this->element === null) {
            throw new InvalidArgumentException('A location has to locate something.');
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    public static function page(int $page, ?string $element = null): self
    {
        return new self(page: $page, element: $element);
    }

    public static function slide(int $slide, ?string $element = null): self
    {
        return new self(slide: $slide, element: $element);
    }

    public static function sheet(string $sheet, ?string $element = null): self
    {
        return new self(sheet: $sheet, element: $element);
    }

    /** For findings that belong to the document as a whole rather than a place in it. */
    public static function element(string $element): self
    {
        return new self(element: $element);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): ?self
    {
        $page = isset($data['page']) ? (int) $data['page'] : null;
        $slide = isset($data['slide']) ? (int) $data['slide'] : null;
        $sheet = isset($data['sheet']) ? (string) $data['sheet'] : null;
        $element = isset($data['element']) ? (string) $data['element'] : null;

        if ($page === null && $slide === null && self::clean($sheet) === null && self::clean($element) === null) {
            return null;
        }

        return new self($page, $slide, $sheet, $element);
    }

    /** A short human phrase: "page 14, Figure", "slide 3", "sheet Estates". */
    public function describe(): string
    {
        $where = match (true) {
            $this->page !== null => "page {$this->page}",
            $this->slide !== null => "slide {$this->slide}",
            $this->sheet !== null => "sheet {$this->sheet}",
            default => null,
        };

        return implode(', ', array_filter([$where, $this->element]));
    }

    /** Only the keys that carry a value, so the stored JSON stays readable. */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'page' => $this->page,
                'slide' => $this->slide,
                'sheet' => $this->sheet,
                'element' => $this->element,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
