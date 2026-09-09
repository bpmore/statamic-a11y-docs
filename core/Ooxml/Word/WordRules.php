<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml\Word;

use Bpmore\DocumentA11yCore\AltText;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlPackage;
use Bpmore\DocumentA11yCore\Ooxml\RuleSet;
use Bpmore\DocumentA11yCore\Ooxml\XmlPart;

/**
 * The Word rule set from spec §6.
 *
 * Thin, which is the point: every question about ZIPs, relationships,
 * namespaces and part resolution was answered once in {@see OoxmlPackage}, and
 * what is left here is only what is wrong with a Word document.
 */
final class WordRules implements RuleSet
{
    /**
     * Below this many paragraphs, a document without headings is a memo rather
     * than an unstructured document. Flagging every two-line note would make
     * the rule noise.
     */
    private const HEADING_THRESHOLD_PARAGRAPHS = 15;

    /**
     * Link text that describes the link mechanism rather than its destination.
     * Someone tabbing through a document hears these out of context, as a list.
     */
    private const UNHELPFUL_LINK_TEXT = [
        'click here', 'click', 'here', 'read more', 'more', 'learn more',
        'link', 'this link', 'this', 'download', 'more info', 'more information',
        'go', 'continue', 'details', 'see more',
    ];

    /** The content-control types that are form fields rather than structure. */
    private const FORM_CONTROLS = ['text', 'comboBox', 'dropDownList', 'date', 'picture', 'richText'];

    public function format(): Format
    {
        return Format::Docx;
    }

    /** @return list<Finding> */
    public function check(OoxmlPackage $package): array
    {
        $document = $package->requireXml($package->mainPart() ?? 'word/document.xml');

        return [
            ...$this->documentProtection($package),
            ...$this->title($package),
            ...$this->language($package, $document),
            ...$this->headings($package, $document),
            ...$this->tables($document),
            ...$this->links($package, $document),
            ...$this->images($package, $document),
            ...$this->contentControls($document),
        ];
    }

    /** @return list<Finding> */
    private function documentProtection(OoxmlPackage $package): array
    {
        $settings = $package->xml('word/settings.xml');
        $protection = $settings?->first('/w:settings/w:documentProtection');

        if ($protection === null) {
            return [];
        }

        // Recorded but not enforced is not protection. Word stores the setting
        // either way, and reporting the unenforced case would fire on documents
        // nobody has restricted.
        if (! self::isOn($settings->attribute($protection, 'enforcement', 'w'))) {
            return [];
        }

        $edit = $settings->attribute($protection, 'edit', 'w') ?? 'readOnly';

        // Tracked-changes protection restricts editing without restricting
        // reading, so it is not an accessibility problem.
        if ($edit === 'trackedChanges' || $edit === 'none') {
            return [];
        }

        return [DocxRule::DocumentProtected->finding(
            "This document is protected against editing (\"$edit\"), which can also stop "
            .'assistive technology reading its content.',
        )];
    }

    /** @return list<Finding> */
    private function title(OoxmlPackage $package): array
    {
        if ($package->title() !== null) {
            return [];
        }

        return [DocxRule::NoTitle->finding(
            'This document has no title in its properties, so it is listed and announced '
            .'by its filename. A heading on the first page is not a document title.',
        )];
    }

    /** @return list<Finding> */
    private function language(OoxmlPackage $package, XmlPart $document): array
    {
        // Word records the language on runs and in the style defaults. Either
        // will do; neither means a screen reader has to guess.
        $styles = $package->xml('word/styles.xml');
        $declared = ($styles?->exists('//w:lang[@w:val]') ?? false)
            || $document->exists('//w:lang[@w:val]');

        if ($declared) {
            return [];
        }

        return [DocxRule::NoLang->finding(
            'This document does not say what language it is written in, so a screen reader '
            .'may read it aloud with the wrong pronunciation rules.',
        )];
    }

    /** @return list<Finding> */
    private function headings(OoxmlPackage $package, XmlPart $document): array
    {
        $paragraphs = $document->count('//w:body//w:p');

        if ($paragraphs < self::HEADING_THRESHOLD_PARAGRAPHS) {
            return [];
        }

        $headingStyles = $this->headingStyleIds($package);

        foreach ($document->elements('//w:pStyle') as $style) {
            if (in_array($document->attribute($style, 'val', 'w'), $headingStyles, true)) {
                return [];
            }
        }

        return [DocxRule::NoHeadings->finding(
            "None of this document's $paragraphs paragraphs uses a heading style. "
            .'Text made large and bold looks like a heading and is not one, so there is '
            .'nothing for anybody to navigate by.',
        )];
    }

    /**
     * The style ids that are headings, read from the document's own styles.
     *
     * Resolved rather than assumed: a document may define "Ttulo1" or a custom
     * style whose name is "heading 1", and matching only the id "Heading1"
     * would report a properly structured document as unstructured.
     *
     * @return list<string>
     */
    private function headingStyleIds(OoxmlPackage $package): array
    {
        $styles = $package->xml('word/styles.xml');
        $ids = [];

        foreach ($styles?->elements('/w:styles/w:style') ?? [] as $style) {
            $id = $styles->attribute($style, 'styleId', 'w') ?? '';
            $name = $styles->first('w:name', $style);
            $friendly = $name === null ? '' : ($styles->attribute($name, 'val', 'w') ?? '');

            if (stripos($id, 'heading') === 0 || stripos($friendly, 'heading') === 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<Finding> */
    private function tables(XmlPart $document): array
    {
        $findings = [];

        foreach ($document->elements('//w:tbl') as $index => $table) {
            $number = $index + 1;
            $location = Location::element("Table $number");

            $firstRow = $document->first('w:tr', $table);
            $header = $firstRow === null ? null : $document->first('w:trPr/w:tblHeader', $firstRow);

            if ($firstRow !== null && ($header === null || ! self::isOn($document->attribute($header, 'val', 'w')))) {
                $findings[] = DocxRule::TableMissingHeaderRow->finding(
                    "Table $number does not mark its first row as a header row, so a screen reader "
                    .'reads every cell without saying which column it is in — and the header does '
                    .'not repeat if the table runs over a page.',
                    $location,
                );
            }

            // Merged cells break the grid a screen reader navigates by. The
            // header row is a separate question, so a table can fail one and
            // pass the other.
            if ($document->exists('.//w:gridSpan', $table) || $document->exists('.//w:vMerge', $table)) {
                $findings[] = DocxRule::ComplexTable->finding(
                    "Table $number merges cells, so it has no consistent grid. Screen reader users "
                    .'navigate tables cell by cell, and merged cells make that unpredictable.',
                    $location,
                );
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function links(OoxmlPackage $package, XmlPart $document): array
    {
        $findings = [];

        foreach ($document->elements('//w:hyperlink') as $link) {
            $text = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');

            if (! $this->isUnhelpfulLinkText($text)) {
                continue;
            }

            $id = $document->attribute($link, 'id', 'r');
            $target = $id === null
                ? null
                : $package->relationship($id, $package->mainPart() ?? 'word/document.xml')?->target;

            $findings[] = DocxRule::LinkTextNotMeaningful->finding(
                $text === ''
                    ? 'A link has no text at all, so there is nothing for a screen reader to announce.'
                    : "The link text \"$text\" does not say where the link goes. Screen reader users "
                      .'often pull up a list of a document\'s links, where each one has to make sense on its own.',
                $target === null ? null : Location::element($target),
            );
        }

        return $findings;
    }

    private function isUnhelpfulLinkText(string $text): bool
    {
        if ($text === '') {
            return true;
        }

        // A bare URL read aloud is a string of letters, slashes and dots.
        if (preg_match('#^(https?://|www\.)#i', $text) === 1) {
            return true;
        }

        $normalised = strtolower(rtrim($text, ' .!:>»→'));

        return in_array($normalised, self::UNHELPFUL_LINK_TEXT, true);
    }

    /**
     * Images anywhere in the document, including headers and footers.
     *
     * Those are separate parts with their own relationships, and a rule that
     * only reads word/document.xml misses every letterhead logo in the library
     * — which is most of them.
     *
     * @return list<Finding>
     */
    private function images(OoxmlPackage $package, XmlPart $document): array
    {
        $findings = [];

        foreach ($this->partsWithContent($package, $document) as $part) {
            $findings = [...$findings, ...$this->imagesIn($part)];
        }

        return $findings;
    }

    /**
     * The main document plus every header and footer it references.
     *
     * @return list<XmlPart>
     */
    private function partsWithContent(OoxmlPackage $package, XmlPart $document): array
    {
        $parts = [$document];
        $main = $package->mainPart() ?? 'word/document.xml';

        foreach ($package->relationships($main) as $relationship) {
            if ($relationship->external || ! in_array($relationship->shortType(), ['header', 'footer'], true)) {
                continue;
            }
            $part = $package->xml($relationship->target);
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /** @return list<Finding> */
    private function imagesIn(XmlPart $document): array
    {
        $findings = [];

        foreach ($document->elements('//wp:docPr') as $properties) {
            $name = $document->attribute($properties, 'name') ?? '';
            $alt = $document->attribute($properties, 'descr');
            $where = str_contains($document->name, 'header')
                ? 'header'
                : (str_contains($document->name, 'footer') ? 'footer' : null);
            $label = $name === '' ? 'an image' : $name;
            $location = Location::element($where === null ? $label : "$label (in the $where)");

            // Absent and empty are different in the XML and identical to
            // somebody using a screen reader.
            if (AltText::isMissing($alt)) {
                $findings[] = DocxRule::ImageMissingAlt->finding(
                    'This image has no alternative text, so anybody who cannot see it '
                    .'is told only that an image is there.',
                    $location,
                );

                continue;
            }

            if (AltText::isFilename($alt)) {
                $findings[] = DocxRule::AltTextIsFilename->finding(
                    'This image\'s alternative text is "'.trim($alt).'", which is a filename '
                    .'rather than a description. Read aloud it says nothing about the image.',
                    $location,
                );

                continue;
            }

            if (AltText::isPlaceholder($alt, $name === '' ? null : $name)) {
                $findings[] = DocxRule::AltTextNotDescriptive->finding(
                    'This image\'s alternative text is "'.trim($alt).'", which is the name the '
                    .'software gave it rather than a description of what it shows.',
                    $location,
                );
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function contentControls(XmlPart $document): array
    {
        $findings = [];
        $number = 0;

        foreach ($document->elements('//w:sdt') as $control) {
            $properties = $document->first('w:sdtPr', $control);
            if ($properties === null) {
                continue;
            }

            // Word uses w:sdt for structure too — a table of contents, a cover
            // page block. Only the ones declaring a form control type are the
            // fields somebody has to fill in.
            $isFormControl = false;
            foreach (self::FORM_CONTROLS as $type) {
                if ($document->exists("w:$type", $properties)) {
                    $isFormControl = true;

                    break;
                }
            }
            if (! $isFormControl) {
                continue;
            }

            $number++;
            $alias = $document->first('w:alias', $properties);
            $title = $alias === null ? null : $document->attribute($alias, 'val', 'w');

            if ($title !== null && trim($title) !== '') {
                continue;
            }

            // w:tag is a developer identifier and is not announced; only the
            // alias is. Accepting a tag as a title would pass every unlabelled
            // form in existence.
            $tag = $document->first('w:tag', $properties);
            $tagValue = $tag === null ? null : $document->attribute($tag, 'val', 'w');

            $findings[] = DocxRule::ContentControlUntitled->finding(
                'This form field has no title, so a screen reader announces that there is a '
                .'box to fill in without saying what belongs in it.',
                Location::element($tagValue !== null && $tagValue !== '' ? $tagValue : "Form field $number"),
            );
        }

        return $findings;
    }

    /** OOXML booleans are "1", "true" or "on" — and absent means true on a flag element. */
    private static function isOn(?string $value): bool
    {
        return $value === null || in_array(strtolower($value), ['1', 'true', 'on'], true);
    }
}
