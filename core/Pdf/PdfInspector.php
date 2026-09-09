<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Pdf;

use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\Inspector;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\UncheckedRule;
use Bpmore\DocumentA11yCore\Version;
use InvalidArgumentException;
use Throwable;

/**
 * The ten PDF heuristics from spec §4.
 *
 * Cheap, no system dependencies, and between them they catch the overwhelming
 * majority of what is actually wrong with the PDFs in a real asset library.
 * veraPDF is the authoritative answer and is optional; this is the one that
 * runs everywhere.
 */
final class PdfInspector implements Inspector
{
    /**
     * Below this many non-whitespace characters per page, with images present,
     * a document is a scan rather than a document. Spec §4 says "≈ 0"; eight
     * leaves room for a stray page number or a scanning artefact without
     * letting a genuinely sparse text page through.
     */
    private const TEXT_CHARACTERS_PER_PAGE = 8;

    /** Over this many pages, a document without bookmarks is hard to navigate. */
    private const LONG_DOCUMENT_PAGES = 20;

    public function __construct(
        private readonly PdfReader $reader = new SmalotPdfReader,
    ) {}

    public function engine(): Engine
    {
        return Engine::heuristics(Version::CURRENT);
    }

    /** Pure PHP with no system dependencies, so always. */
    public function isAvailable(): bool
    {
        return true;
    }

    public function supports(Format $format): bool
    {
        return $format === Format::Pdf;
    }

    public function inspect(string $path, Format $format): InspectionResult
    {
        if (! $this->supports($format)) {
            throw new InvalidArgumentException(
                "PdfInspector was handed a {$format->value} file. Check supports() first."
            );
        }

        try {
            $document = $this->reader->read($path);
        } catch (Throwable $exception) {
            // One unreadable file in a library of 1,240 records an error and
            // lets the batch carry on. It never throws.
            return InspectionResult::error(
                $this->engine(),
                'This PDF could not be read: '.rtrim($exception->getMessage(), '.').'.'
            );
        }

        $findings = [];
        $unchecked = [];

        $this->tagging($document, $findings);
        $this->title($document, $findings, $unchecked);
        $this->titleDisplay($document, $findings);
        $this->language($document, $findings);
        $this->imageOnly($document, $findings, $unchecked);
        $this->extractionPermission($document, $findings, $unchecked);
        $this->bookmarks($document, $findings);
        $this->formFields($document, $findings);
        $this->figures($document, $findings);
        $this->fonts($document, $findings);

        return InspectionResult::checked($this->engine(), $findings, $unchecked, $document->pageCount);
    }

    /** @param  list<Finding>  $findings */
    private function tagging(PdfDocument $document, array &$findings): void
    {
        if ($document->isTagged()) {
            return;
        }

        $findings[] = PdfRule::NotTagged->finding(
            'This PDF has no tags. A screen reader cannot tell a heading from body text, '
            .'work out the reading order, or navigate the document at all.',
        );
    }

    /**
     * @param  list<Finding>  $findings
     * @param  list<UncheckedRule>  $unchecked
     */
    private function title(PdfDocument $document, array &$findings, array &$unchecked): void
    {
        // Strings in an encrypted document come back as ciphertext, so a title
        // that is present reads as gibberish rather than as absent. Reporting
        // "no title" here would be a guess dressed up as a finding.
        if ($document->isEncrypted) {
            $unchecked[] = new UncheckedRule(
                'pdf.no_title',
                'The document is encrypted, so its title could not be read. It may well have one.',
            );

            return;
        }

        if ($document->hasTitle()) {
            return;
        }

        $findings[] = PdfRule::NoTitle->finding(
            'This PDF has no title, so it is announced by its filename. '
            .'Someone with several documents open hears a list of filenames rather than a list of documents.',
        );
    }

    /** @param  list<Finding>  $findings */
    private function titleDisplay(PdfDocument $document, array &$findings): void
    {
        if ($document->hasDisplayDocTitle) {
            return;
        }

        $findings[] = PdfRule::TitleNotDisplayed->finding(
            'This PDF is not set to show its title in place of its filename. '
            .'It is a one-line setting almost every PDF misses, and PDF/UA requires it.',
        );
    }

    /** @param  list<Finding>  $findings */
    private function language(PdfDocument $document, array &$findings): void
    {
        // Presence is readable even under encryption, because dictionary keys
        // are not encrypted; only the value would be unreadable.
        $stated = $document->hasLang
            && ($document->isEncrypted || trim((string) $document->lang) !== '');

        if ($stated) {
            return;
        }

        $findings[] = PdfRule::NoLang->finding(
            'This PDF does not say what language it is written in, '
            .'so a screen reader may read it aloud with the wrong pronunciation rules.',
        );
    }

    /**
     * @param  list<Finding>  $findings
     * @param  list<UncheckedRule>  $unchecked
     */
    private function imageOnly(PdfDocument $document, array &$findings, array &$unchecked): void
    {
        if ($document->isEncrypted) {
            $unchecked[] = new UncheckedRule(
                'pdf.image_only',
                'The document is encrypted, so no text could be extracted from it — '
                .'which is not evidence that there is none.',
            );

            return;
        }

        $expected = self::TEXT_CHARACTERS_PER_PAGE * max(1, $document->pageCount);

        if ($document->imageCount === 0 || $document->textLength >= $expected) {
            return;
        }

        $findings[] = PdfRule::ImageOnly->finding(
            'This PDF is a scan with no text layer: its pages are pictures of words. '
            .'Nothing in it can be read aloud, searched, selected or copied.',
        );
    }

    /**
     * @param  list<Finding>  $findings
     * @param  list<UncheckedRule>  $unchecked
     */
    private function extractionPermission(PdfDocument $document, array &$findings, array &$unchecked): void
    {
        $allowed = $document->allowsAccessibilityExtraction();

        if ($allowed === null) {
            $unchecked[] = new UncheckedRule(
                'pdf.extraction_blocked',
                'The document is encrypted and its permissions could not be read.',
            );

            return;
        }
        if ($allowed) {
            return;
        }

        $findings[] = PdfRule::ExtractionBlocked->finding(
            'This PDF\'s security settings forbid extracting its text for assistive technology. '
            .'A conforming reader will refuse a screen reader access to the content.',
        );
    }

    /** @param  list<Finding>  $findings */
    private function bookmarks(PdfDocument $document, array &$findings): void
    {
        if ($document->pageCount <= self::LONG_DOCUMENT_PAGES || $document->hasOutlines) {
            return;
        }

        $findings[] = PdfRule::NoBookmarks->finding(
            "This {$document->pageCount}-page PDF has no bookmarks, "
            .'so there is no way to jump to a section — only scrolling.',
        );
    }

    /** @param  list<Finding>  $findings */
    private function formFields(PdfDocument $document, array &$findings): void
    {
        foreach ($document->unlabelledFormFields() as $field) {
            $findings[] = PdfRule::UnlabelledFormFields->finding(
                ucfirst($field->describe()).' has no tooltip, so a screen reader announces '
                .'only that there is a box, not what belongs in it.',
                $this->locate($field->page, $field->name),
            );
        }
    }

    /** @param  list<Finding>  $findings */
    private function figures(PdfDocument $document, array &$findings): void
    {
        foreach ($document->undescribedFigures() as $figure) {
            $findings[] = PdfRule::FigureMissingAlt->finding(
                'A figure has neither alternative text nor a text equivalent, '
                .'so anyone who cannot see it is told only that an image is there.',
                $this->locate($figure->page, 'Figure'),
            );
        }
    }

    /**
     * One finding for the document, not one per font.
     *
     * A real 302-page PDF turned up nine fonts without a character map. That is
     * one problem — the document has to be produced again — and nine rows in a
     * remediation queue is nine things somebody has to read before working out
     * it was one thing. The font names belong in the message, not in the count.
     *
     * @param  list<Finding>  $findings
     */
    private function fonts(PdfDocument $document, array &$findings): void
    {
        $fonts = $document->fontsWithoutToUnicode();

        if ($fonts === []) {
            return;
        }

        $findings[] = PdfRule::NoToUnicode->finding(
            'The text in this PDF cannot be reliably extracted: '
            .$this->describeFonts($fonts)
            .'. It may look perfectly readable on screen and still be unavailable to a screen reader, '
            .'a search index, or anyone trying to copy from it.',
        );
    }

    /** @param  list<Font>  $fonts */
    private function describeFonts(array $fonts): string
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn (Font $font): string => $font->name(),
            $fonts,
        ))));

        $count = count($fonts);
        $noun = $count === 1 ? 'one font has' : "$count fonts have";

        if ($names === []) {
            return "$noun no character map";
        }

        $listed = array_slice($names, 0, 3);
        $remaining = count($names) - count($listed);
        $list = '"'.implode('", "', $listed).'"';
        if ($remaining > 0) {
            $list .= " and $remaining ".($remaining === 1 ? 'other' : 'others');
        }

        return "$noun no character map ($list)";
    }

    private function locate(?int $page, ?string $element): ?Location
    {
        $element = $element === null || trim($element) === '' ? null : trim($element);

        if ($page !== null) {
            return Location::page($page, $element);
        }

        return $element === null ? null : Location::element($element);
    }
}
