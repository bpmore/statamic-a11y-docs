<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml\PowerPoint;

use Bpmore\DocumentA11yCore\AltText;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlPackage;
use Bpmore\DocumentA11yCore\Ooxml\RuleSet;
use Bpmore\DocumentA11yCore\Ooxml\XmlPart;
use DOMElement;

/** The PowerPoint rule set from spec §6. */
final class PowerPointRules implements RuleSet
{
    /** Names PowerPoint gives a section nobody has renamed. */
    private const DEFAULT_SECTION_NAMES = ['untitled section', 'default section', 'section'];

    public function format(): Format
    {
        return Format::Pptx;
    }

    /** @return list<Finding> */
    public function check(OoxmlPackage $package): array
    {
        $presentation = $package->requireXml($package->mainPart() ?? 'ppt/presentation.xml');
        $slides = $this->slideParts($package, $presentation);

        $findings = [];
        $titles = [];

        foreach ($slides as $number => $part) {
            $slide = $package->xml($part);
            if ($slide === null) {
                continue;
            }

            $title = $this->titleOf($slide);
            if ($title !== null) {
                $titles[$number] = $title;
            }

            $findings = [
                ...$findings,
                ...$this->slideTitle($number, $title),
                ...$this->shapes($slide, $number),
                ...$this->media($package, $slide, $part, $number),
                ...$this->readingOrder($slide, $number),
            ];
        }

        return [
            ...$findings,
            ...$this->duplicateTitles($titles),
            ...$this->sections($presentation),
        ];
    }

    /**
     * Slide parts in presentation order, keyed by the number a person sees.
     *
     * Read from p:sldIdLst rather than by sorting part names, because
     * slide10.xml sorts before slide2.xml and the deck's order is the
     * presentation's to declare.
     *
     * @return array<int, string>
     */
    private function slideParts(OoxmlPackage $package, XmlPart $presentation): array
    {
        $main = $package->mainPart() ?? 'ppt/presentation.xml';
        $parts = [];
        $number = 0;

        foreach ($presentation->elements('/p:presentation/p:sldIdLst/p:sldId') as $slideId) {
            $number++;
            $id = $presentation->attribute($slideId, 'id', 'r');
            $target = $id === null ? null : $package->relationship($id, $main)?->target;

            if ($target !== null) {
                $parts[$number] = $target;
            }
        }

        return $parts;
    }

    /** The text of the slide's title placeholder, or null when it has none. */
    private function titleOf(XmlPart $slide): ?string
    {
        $placeholder = $slide->first(
            "//p:sp[p:nvSpPr/p:nvPr/p:ph[@type='title' or @type='ctrTitle']]"
        );

        if ($placeholder === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $placeholder->textContent) ?? '');

        return $text === '' ? null : $text;
    }

    /** @return list<Finding> */
    private function slideTitle(int $number, ?string $title): array
    {
        if ($title !== null) {
            return [];
        }

        // An empty title placeholder and no title placeholder are the same
        // thing to somebody navigating by slide title, and the empty one is the
        // more common mistake.
        return [PptxRule::SlideMissingTitle->finding(
            "Slide $number has no title. Slide titles are how screen reader users move through a "
            .'deck, so a slide without one cannot be found or skipped.',
            Location::slide($number),
        )];
    }

    /** @return list<Finding> */
    private function duplicateTitles(array $titles): array
    {
        $groups = [];
        foreach ($titles as $number => $title) {
            $groups[mb_strtolower($title)][] = $number;
        }

        $findings = [];
        foreach ($groups as $slides) {
            if (count($slides) < 2) {
                continue;
            }

            // One finding for the group, not one per slide: three slides called
            // "Results" is one thing to fix, and three rows in a queue is three
            // things somebody reads before working that out.
            $title = $titles[$slides[0]];
            $findings[] = PptxRule::DuplicateSlideTitles->finding(
                'Slides '.implode(', ', $slides)." all have the title \"$title\", so a listing of "
                .'slide titles cannot tell them apart.',
                Location::slide($slides[0]),
            );
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function shapes(XmlPart $slide, int $number): array
    {
        $findings = [];

        foreach ($this->shapesNeedingAlt($slide) as $shape) {
            $properties = $slide->first('.//p:cNvPr', $shape);
            if ($properties === null) {
                continue;
            }

            $alt = $slide->attribute($properties, 'descr');
            $name = $slide->attribute($properties, 'name') ?? '';
            $location = Location::slide($number, $name === '' ? null : $name);

            if (AltText::isMissing($alt)) {
                $findings[] = PptxRule::ShapeMissingAlt->finding(
                    'This has no alternative text, so anybody who cannot see it is told only '
                    .'that something is there.',
                    $location,
                );

                continue;
            }

            // Present but useless is a different problem from absent, and the
            // author fixes it differently — which is why it is a separate rule
            // rather than a footnote on the same one.
            if (AltText::isFilename($alt)) {
                $findings[] = PptxRule::AltTextIsFilename->finding(
                    'The alternative text here is "'.trim((string) $alt).'", which is a filename '
                    .'rather than a description of what is in the picture.',
                    $location,
                );

                continue;
            }

            if (AltText::isPlaceholder($alt, $name === '' ? null : $name)) {
                $findings[] = PptxRule::AltTextNotDescriptive->finding(
                    'The alternative text here is "'.trim((string) $alt).'", which is the name '
                    .'PowerPoint gave the object rather than a description of it.',
                    $location,
                );
            }
        }

        return $findings;
    }

    /**
     * Pictures always, and shapes that are not placeholders or text boxes.
     *
     * A placeholder's content is its text, and a text box is read aloud as
     * written, so neither needs describing. `p:cNvSpPr/@txBox` is how
     * PowerPoint records the difference, which is why it is checked rather than
     * guessed at from whether the shape happens to contain words.
     *
     * @return list<DOMElement>
     */
    private function shapesNeedingAlt(XmlPart $slide): array
    {
        return [
            ...$slide->elements('//p:pic'),
            ...$slide->elements("//p:sp[not(p:nvSpPr/p:nvPr/p:ph)][not(p:nvSpPr/p:cNvSpPr[@txBox='1'])]"),
        ];
    }

    /** @return list<Finding> */
    private function media(OoxmlPackage $package, XmlPart $slide, string $part, int $number): array
    {
        if (! $slide->exists('//p:nvPr/a:videoFile') && ! $slide->exists('//p:nvPr/a:audioFile')) {
            return [];
        }

        foreach ($package->relationships($part) as $relationship) {
            if (stripos($relationship->shortType(), 'caption') !== false) {
                return [];
            }
        }

        // Worded as what we can say for certain. PowerPoint attaches captions
        // through a relationship added by a later extension, and a deck whose
        // captions are burned into the video would be reported here too — so
        // this asks somebody to check rather than asserting a failure.
        return [PptxRule::MediaWithoutCaptions->finding(
            "Slide $number embeds audio or video and no captions could be found for it. "
            .'Check that it is captioned: without them the content is unavailable to anybody '
            .'who cannot hear it.',
            Location::slide($number),
        )];
    }

    /**
     * Shapes listed in an order that does not match how the slide reads.
     *
     * A screen reader follows `p:spTree`, not the layout, so a slide whose
     * title is added last is read last. Only unambiguous inversions count — one
     * shape sitting entirely above another it follows in the tree — because
     * comparing positions loosely produces false positives on any decorative
     * layout, which is what spec §12 warns about.
     *
     * @return list<Finding>
     */
    private function readingOrder(XmlPart $slide, int $number): array
    {
        $positions = [];

        foreach ($slide->elements('/p:sld/p:cSld/p:spTree/*[self::p:sp or self::p:pic or self::p:graphicFrame]') as $shape) {
            $offset = $slide->first('.//a:xfrm/a:off', $shape);
            $extent = $slide->first('.//a:xfrm/a:ext', $shape);

            // A shape with no explicit position inherits one from the layout,
            // and guessing at it would be exactly the false positive to avoid.
            if ($offset === null || $extent === null) {
                continue;
            }

            $positions[] = [
                'top' => (int) $slide->attribute($offset, 'y'),
                'bottom' => (int) $slide->attribute($offset, 'y') + (int) $slide->attribute($extent, 'cy'),
            ];
        }

        foreach ($positions as $index => $earlier) {
            foreach (array_slice($positions, $index + 1) as $later) {
                if ($later['bottom'] <= $earlier['top']) {
                    return [PptxRule::ReadingOrder->finding(
                        "Slide $number lists its shapes in an order that does not match the way it "
                        .'reads: something below is announced before something above it. A screen '
                        .'reader follows the order the shapes were added, not the layout.',
                        Location::slide($number),
                    )];
                }
            }
        }

        return [];
    }

    /** @return list<Finding> */
    private function sections(XmlPart $presentation): array
    {
        $findings = [];
        $seen = [];

        foreach ($presentation->elements('//p14:sectionLst/p14:section') as $index => $section) {
            $number = $index + 1;
            $name = trim($presentation->attribute($section, 'name') ?? '');
            $normalised = mb_strtolower($name);

            if ($name === '' || in_array($normalised, self::DEFAULT_SECTION_NAMES, true)) {
                $findings[] = PptxRule::DefaultSectionNames->finding(
                    "Section $number still has PowerPoint's default name"
                    .($name === '' ? '' : " (\"$name\")").', so it says nothing about what is in it.',
                    Location::element("Section $number"),
                );

                continue;
            }

            if (isset($seen[$normalised])) {
                $findings[] = PptxRule::DefaultSectionNames->finding(
                    "Section $number repeats the name \"$name\", already used by section "
                    .$seen[$normalised].'.',
                    Location::element("Section $number"),
                );

                continue;
            }

            $seen[$normalised] = $number;
        }

        return $findings;
    }
}
