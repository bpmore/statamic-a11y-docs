<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Pdf;

/** A font resource, as far as the text-extraction rule cares about one. */
final readonly class Font
{
    /**
     * The 14 fonts every PDF reader is required to have built in. They carry no
     * /ToUnicode and never need one, because the reader already knows what
     * their character codes mean. Flagging them would fire on most PDFs ever
     * made, which is the fastest way to make a report worth ignoring.
     */
    public const STANDARD_14 = [
        'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
        'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique',
        'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
        'Symbol', 'ZapfDingbats',
    ];

    public function __construct(
        public ?string $baseFont,
        public ?string $subtype,
        public bool $hasToUnicode,
    ) {}

    public function isStandard14(): bool
    {
        return in_array($this->name(), self::STANDARD_14, true);
    }

    /**
     * A CIDFont is the descendant half of a composite font. Its /ToUnicode lives
     * on the Type0 parent, so asking the descendant for one double-counts every
     * composite font in the document.
     */
    public function isCidDescendant(): bool
    {
        return in_array($this->subtype, ['CIDFontType0', 'CIDFontType2'], true);
    }

    public function needsToUnicode(): bool
    {
        return ! $this->hasToUnicode && ! $this->isStandard14() && ! $this->isCidDescendant();
    }

    /** The base font without its subset prefix: "AAAAAA+SubsetSans" is "SubsetSans". */
    public function name(): string
    {
        $name = ltrim((string) $this->baseFont, '/');

        return preg_match('/^[A-Z]{6}\+(.+)$/', $name, $match) === 1 ? $match[1] : $name;
    }
}
