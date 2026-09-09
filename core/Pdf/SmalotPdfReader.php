<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Pdf;

use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document as SmalotDocument;
use Smalot\PdfParser\Header;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;
use SplObjectStorage;

/**
 * The only class in this package that knows smalot/pdfparser exists.
 *
 * Everything here is downstream of the Phase 1 spike; the awkward parts are
 * awkward because the library is, and each one is commented where it bites.
 */
final class SmalotPdfReader implements PdfReader
{
    public function read(string $path): PdfDocument
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The file could not be opened.');
        }
        if (filesize($path) === 0) {
            throw new RuntimeException('The file is empty.');
        }

        $document = $this->parser()->parseFile($path);
        $catalog = $this->catalog($document);
        $pageNumbers = $this->pageNumbers($document);

        $encrypt = $this->encryptDictionary($document);
        $details = $this->details($document);

        return new PdfDocument(
            pageCount: count($document->getPages()),
            isEncrypted: $encrypt !== null,
            permissions: $encrypt === null ? null : $this->permissions($encrypt),
            hasStructTreeRoot: $catalog?->getHeader()->has('StructTreeRoot') ?? false,
            isMarked: $this->nested($catalog, 'MarkInfo', 'Marked') === true,
            hasLang: $catalog?->getHeader()->has('Lang') ?? false,
            lang: $this->text($catalog?->getHeader()->has('Lang') === true ? $catalog->getHeader()->get('Lang') : null),
            hasDisplayDocTitle: $this->nested($catalog, 'ViewerPreferences', 'DisplayDocTitle') === true,
            infoTitle: $details['Title'] ?? null,
            xmpTitle: $details['dc:title'] ?? null,
            hasOutlines: $catalog?->getHeader()->has('Outlines') ?? false,
            textLength: $this->textLength($document),
            imageCount: $this->imageCount($document),
            formFields: $this->formFields($document, $pageNumbers),
            figures: $this->figures($document, $pageNumbers),
            fonts: $this->fonts($document),
        );
    }

    private function parser(): Parser
    {
        $config = new Config;

        // Without this the library refuses an encrypted file outright, and /P —
        // the only place the accessibility permission lives — is unreachable.
        // Dictionaries then parse normally; strings and streams stay encrypted,
        // which is why the inspector treats title and text as unreadable on
        // these documents rather than as absent.
        $config->setIgnoreEncryption(true);

        return new Parser([], $config);
    }

    private function catalog(SmalotDocument $document): ?PDFObject
    {
        foreach ($document->getObjectsByType('Catalog') as $catalog) {
            return $catalog;
        }

        return null;
    }

    private function encryptDictionary(SmalotDocument $document): ?PDFObject
    {
        $trailer = $document->getTrailer();

        if (! $trailer->has('Encrypt')) {
            return null;
        }

        $encrypt = $trailer->get('Encrypt');

        return $encrypt instanceof PDFObject ? $encrypt : null;
    }

    private function permissions(PDFObject $encrypt): ?int
    {
        if (! $encrypt->getHeader()->has('P')) {
            return null;
        }

        // /P arrives as a float. Casting before the bitwise test is not
        // optional: `-529.0 & 512` is not a thing PHP will do quietly forever.
        $value = $encrypt->getHeader()->get('P')->getContent();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Title and language from /Info merged with the XMP packet.
     *
     * Values are not reliably scalar — a real-world PDF returned an array under
     * Title and produced a silent "Array to string conversion" — so everything
     * is flattened before it is trusted.
     *
     * @return array<string, string>
     */
    private function details(SmalotDocument $document): array
    {
        try {
            $details = $document->getDetails();
        } catch (\Throwable) {
            return [];
        }

        $flattened = [];
        foreach (['Title', 'dc:title'] as $key) {
            $value = $details[$key] ?? null;
            $value = is_array($value)
                ? implode(' ', array_filter($value, 'is_scalar'))
                : (is_scalar($value) ? (string) $value : '');
            if (trim($value) !== '') {
                $flattened[$key] = trim($value);
            }
        }

        return $flattened;
    }

    /** Non-whitespace characters only: "≈ 0 characters" should not count blank lines. */
    private function textLength(SmalotDocument $document): int
    {
        try {
            $text = $document->getText();
        } catch (\Throwable) {
            return 0;
        }

        return strlen((string) preg_replace('/\s+/u', '', $text));
    }

    private function imageCount(SmalotDocument $document): int
    {
        $count = 0;
        foreach ($document->getObjects() as $object) {
            if ($this->name($object->getHeader()->get('Subtype')) === 'Image') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Page objects mapped to their 1-based position, so an annotation's /P and
     * a structure element's /Pg can become a page number a person can turn to.
     *
     * @return SplObjectStorage<PDFObject, int>
     */
    private function pageNumbers(SmalotDocument $document): SplObjectStorage
    {
        $numbers = new SplObjectStorage;

        foreach (array_values($document->getPages()) as $index => $page) {
            $numbers[$page] = $index + 1;
        }

        return $numbers;
    }

    /** @param  SplObjectStorage<PDFObject, int>  $pageNumbers */
    private function pageOf(?Header $header, string $key, SplObjectStorage $pageNumbers): ?int
    {
        if ($header === null || ! $header->has($key)) {
            return null;
        }

        $reference = $header->get($key);

        return $reference instanceof PDFObject && $pageNumbers->contains($reference)
            ? $pageNumbers[$reference]
            : null;
    }

    /**
     * @param  SplObjectStorage<PDFObject, int>  $pageNumbers
     * @return list<FormField>
     */
    private function formFields(SmalotDocument $document, SplObjectStorage $pageNumbers): array
    {
        $fields = [];

        foreach ($document->getObjectsByType('Annot') as $annotation) {
            $header = $annotation->getHeader();
            if ($this->name($header->get('Subtype')) !== 'Widget') {
                continue;
            }

            // A field and its widget are usually one dictionary, but a field
            // with several widgets keeps /TU on the parent. Missing that reads
            // one labelled field as several unlabelled ones.
            $label = $header->has('TU') ? $this->text($header->get('TU')) : null;
            if ($label === null && $header->has('Parent')) {
                $parent = $header->get('Parent');
                if ($parent instanceof PDFObject && $parent->getHeader()->has('TU')) {
                    $label = $this->text($parent->getHeader()->get('TU'));
                }
            }

            $fields[] = new FormField(
                name: $header->has('T') ? $this->text($header->get('T')) : null,
                label: $label,
                type: $header->has('FT') ? $this->name($header->get('FT')) : null,
                page: $this->pageOf($header, 'P', $pageNumbers),
            );
        }

        return $fields;
    }

    /**
     * @param  SplObjectStorage<PDFObject, int>  $pageNumbers
     * @return list<Figure>
     */
    private function figures(SmalotDocument $document, SplObjectStorage $pageNumbers): array
    {
        $figures = [];

        foreach ($document->getObjectsByType('StructElem') as $element) {
            $header = $element->getHeader();
            if ($this->name($header->get('S')) !== 'Figure') {
                continue;
            }

            $figures[] = new Figure(
                hasAlt: $header->has('Alt'),
                hasActualText: $header->has('ActualText'),
                page: $this->pageOf($header, 'Pg', $pageNumbers),
            );
        }

        return $figures;
    }

    /** @return list<Font> */
    private function fonts(SmalotDocument $document): array
    {
        $fonts = [];

        foreach ($document->getFonts() as $font) {
            $header = $font->getHeader();
            $fonts[] = new Font(
                baseFont: $header->has('BaseFont') ? $this->name($header->get('BaseFont')) : null,
                subtype: $header->has('Subtype') ? $this->name($header->get('Subtype')) : null,
                hasToUnicode: $header->has('ToUnicode'),
            );
        }

        return $fonts;
    }

    /** A value out of a dictionary nested inside another, either of which may be missing. */
    private function nested(?PDFObject $object, string $dictionary, string $key): mixed
    {
        if ($object === null || ! $object->getHeader()->has($dictionary)) {
            return null;
        }

        $inner = $object->getHeader()->get($dictionary);

        if ($inner instanceof Header) {
            return $inner->has($key) ? $inner->get($key)->getContent() : null;
        }
        if ($inner instanceof PDFObject) {
            return $inner->getHeader()->has($key) ? $inner->getHeader()->get($key)->getContent() : null;
        }

        return null;
    }

    /** A name or string value as a plain string, or null if there is not one. */
    private function name(mixed $element): ?string
    {
        $content = is_object($element) && method_exists($element, 'getContent')
            ? $element->getContent()
            : null;

        return is_scalar($content) && (string) $content !== '' ? (string) $content : null;
    }

    private function text(mixed $element): ?string
    {
        return $this->name($element);
    }
}
