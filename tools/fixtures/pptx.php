<?php

declare(strict_types=1);

namespace Bpmore\FixtureTools;

const PPTX_NS = ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
    .' xmlns:r="'.NS_REL.'"'
    .' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

function pptx_fixtures(): array
{
    return [
        pptx_good(),
        pptx_slide_missing_title(),
        pptx_duplicate_slide_titles(),
        pptx_shape_missing_alt(),
        pptx_reading_order(),
        pptx_media_without_captions(),
        pptx_default_section_names(),
        pptx_alt_text_not_descriptive(),
    ];
}

function pptx_empty_group(): string
{
    return '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
        .'<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
}

/** A title placeholder. $text === null omits the placeholder entirely; '' leaves it empty. */
function pptx_title_shape(?string $text): string
{
    if ($text === null) {
        return '';
    }

    $paragraph = $text === ''
        ? '<a:p><a:endParaRPr lang="en-US"/></a:p>'
        : '<a:p><a:r><a:rPr lang="en-US"/><a:t>'.htmlspecialchars($text, ENT_XML1).'</a:t></a:r></a:p>';

    return '<p:sp>'
        .'<p:nvSpPr><p:cNvPr id="2" name="Title 1"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>'
        .'<p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
        .'<p:spPr><a:xfrm><a:off x="838200" y="365125"/><a:ext cx="10515600" cy="1325563"/></a:xfrm></p:spPr>'
        .'<p:txBody><a:bodyPr/><a:lstStyle/>'.$paragraph.'</p:txBody>'
        .'</p:sp>';
}

function pptx_body_shape(int $id, string $text): string
{
    return '<p:sp>'
        .'<p:nvSpPr><p:cNvPr id="'.$id.'" name="Content Placeholder '.$id.'"/>'
        .'<p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph idx="1"/></p:nvPr></p:nvSpPr>'
        .'<p:spPr><a:xfrm><a:off x="838200" y="1825625"/><a:ext cx="10515600" cy="4351338"/></a:xfrm></p:spPr>'
        .'<p:txBody><a:bodyPr/><a:lstStyle/>'
        .'<a:p><a:r><a:rPr lang="en-US"/><a:t>'.htmlspecialchars($text, ENT_XML1).'</a:t></a:r></a:p>'
        .'</p:txBody></p:sp>';
}

function pptx_picture(int $id, string $name, ?string $descr, int $offsetX = 838200): string
{
    $descrAttribute = $descr === null ? '' : ' descr="'.htmlspecialchars($descr, ENT_XML1 | ENT_QUOTES).'"';

    return '<p:pic>'
        .'<p:nvPicPr><p:cNvPr id="'.$id.'" name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES).'"'.$descrAttribute.'/>'
        .'<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
        .'<p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
        .'<p:spPr><a:xfrm><a:off x="'.$offsetX.'" y="2000250"/><a:ext cx="2286000" cy="2286000"/></a:xfrm>'
        .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
        .'</p:pic>';
}

/** A drawn shape (not a picture) — the alt-text rule applies to these too. */
function pptx_decorative_shape(int $id, string $name, ?string $descr): string
{
    $descrAttribute = $descr === null ? '' : ' descr="'.htmlspecialchars($descr, ENT_XML1 | ENT_QUOTES).'"';

    return '<p:sp>'
        .'<p:nvSpPr><p:cNvPr id="'.$id.'" name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES).'"'.$descrAttribute.'/>'
        .'<p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
        .'<p:spPr><a:xfrm><a:off x="6000000" y="2000250"/><a:ext cx="2286000" cy="1143000"/></a:xfrm>'
        .'<a:prstGeom prst="roundRect"><a:avLst/></a:prstGeom>'
        .'<a:solidFill><a:schemeClr val="accent2"/></a:solidFill></p:spPr>'
        .'<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="en-US"/><a:t>42%</a:t></a:r></a:p></p:txBody>'
        .'</p:sp>';
}

function pptx_slide(string $shapes): string
{
    return OOXML_DECL
        .'<p:sld'.PPTX_NS.'>'
        .'<p:cSld><p:spTree>'.pptx_empty_group().$shapes.'</p:spTree></p:cSld>'
        .'<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
        .'</p:sld>';
}

/** @param list<string> $slides fully-formed slide XML documents */
/**
 * @param  array{sections?: list<string>, slide_rels?: array<int, list<array<string, string>>>, media_file?: bool}  $options
 */
function pptx_build(array $slides, ?string $title, bool $media = false, array $options = []): string
{
    $slideCount = count($slides);

    $presentationRels = [['id' => 'rId1', 'type' => NS_REL.'/slideMaster', 'target' => 'slideMasters/slideMaster1.xml']];
    $slideIds = '';
    for ($i = 1; $i <= $slideCount; $i++) {
        $relId = 'rId'.($i + 1);
        $presentationRels[] = ['id' => $relId, 'type' => NS_REL.'/slide', 'target' => "slides/slide$i.xml"];
        $slideIds .= '<p:sldId id="'.(255 + $i).'" r:id="'.$relId.'"/>';
    }
    $presentationRels[] = ['id' => 'rId'.($slideCount + 2), 'type' => NS_REL.'/theme', 'target' => 'theme/theme1.xml'];

    $presentation = OOXML_DECL
        .'<p:presentation'.PPTX_NS.' saveSubsetFonts="1">'
        .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
        .'<p:sldIdLst>'.$slideIds.'</p:sldIdLst>'
        .'<p:sldSz cx="12192000" cy="6858000"/><p:notesSz cx="6858000" cy="9144000"/>'
        .pptx_sections($options['sections'] ?? null)
        .'</p:presentation>';

    $slideMaster = OOXML_DECL
        .'<p:sldMaster'.PPTX_NS.'>'
        .'<p:cSld><p:bg><p:bgPr><a:solidFill><a:schemeClr val="bg1"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
        .'<p:spTree>'.pptx_empty_group().'</p:spTree></p:cSld>'
        .'<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2"'
        .' accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
        .'<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
        .'</p:sldMaster>';

    $slideLayout = OOXML_DECL
        .'<p:sldLayout'.PPTX_NS.' type="obj" preserve="1">'
        .'<p:cSld name="Title and Content"><p:spTree>'.pptx_empty_group()
        .pptx_title_shape('Click to edit Master title style')
        .'</p:spTree></p:cSld>'
        .'<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
        .'</p:sldLayout>';

    $defaults = [
        'rels' => 'application/vnd.openxmlformats-package.relationships+xml',
        'xml' => 'application/xml',
    ];
    if ($media) {
        $defaults['png'] = 'image/png';
    }
    if ($options['media_file'] ?? false) {
        $defaults['mp4'] = 'video/mp4';
        $defaults['vtt'] = 'text/vtt';
    }

    $overrides = [
        '/ppt/presentation.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
        '/ppt/slideMasters/slideMaster1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml',
        '/ppt/slideLayouts/slideLayout1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml',
        '/ppt/theme/theme1.xml' => 'application/vnd.openxmlformats-officedocument.theme+xml',
    ];
    for ($i = 1; $i <= $slideCount; $i++) {
        $overrides["/ppt/slides/slide$i.xml"] = 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml';
    }
    $overrides['/docProps/core.xml'] = 'application/vnd.openxmlformats-package.core-properties+xml';
    $overrides['/docProps/app.xml'] = 'application/vnd.openxmlformats-officedocument.extended-properties+xml';

    $zip = new Zip;
    $zip->add('[Content_Types].xml', ooxml_content_types($defaults, $overrides));
    $zip->add('_rels/.rels', ooxml_rels([
        ['id' => 'rId1', 'type' => NS_REL.'/officeDocument', 'target' => 'ppt/presentation.xml'],
        ['id' => 'rId2', 'type' => NS_PKG_REL.'/metadata/core-properties', 'target' => 'docProps/core.xml'],
        ['id' => 'rId3', 'type' => NS_REL.'/extended-properties', 'target' => 'docProps/app.xml'],
    ]));
    $zip->add('ppt/presentation.xml', $presentation);
    $zip->add('ppt/_rels/presentation.xml.rels', ooxml_rels($presentationRels));
    $zip->add('ppt/slideMasters/slideMaster1.xml', $slideMaster);
    $zip->add('ppt/slideMasters/_rels/slideMaster1.xml.rels', ooxml_rels([
        ['id' => 'rId1', 'type' => NS_REL.'/slideLayout', 'target' => '../slideLayouts/slideLayout1.xml'],
        ['id' => 'rId2', 'type' => NS_REL.'/theme', 'target' => '../theme/theme1.xml'],
    ]));
    $zip->add('ppt/slideLayouts/slideLayout1.xml', $slideLayout);
    $zip->add('ppt/slideLayouts/_rels/slideLayout1.xml.rels', ooxml_rels([
        ['id' => 'rId1', 'type' => NS_REL.'/slideMaster', 'target' => '../slideMasters/slideMaster1.xml'],
    ]));
    $zip->add('ppt/theme/theme1.xml', ooxml_theme());

    foreach ($slides as $index => $slideXml) {
        $number = $index + 1;
        $slideRels = [['id' => 'rId1', 'type' => NS_REL.'/slideLayout', 'target' => '../slideLayouts/slideLayout1.xml']];
        if ($media) {
            $slideRels[] = ['id' => 'rId2', 'type' => NS_REL.'/image', 'target' => '../media/image1.png'];
        }
        foreach ($options['slide_rels'][$number] ?? [] as $extra) {
            $slideRels[] = $extra;
        }
        $zip->add("ppt/slides/slide$number.xml", $slideXml);
        $zip->add("ppt/slides/_rels/slide$number.xml.rels", ooxml_rels($slideRels));
    }
    if ($media) {
        $zip->add('ppt/media/image1.png', ooxml_png());
    }
    if ($options['media_file'] ?? false) {
        // Enough of an MP4 header to be recognisably one. Nothing reads it; the
        // rule is about whether anybody captioned it.
        $zip->add('ppt/media/media1.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
        $zip->add('ppt/media/media1.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:04.000\nWelcome to the 2026 open day.\n");
    }

    $zip->add('docProps/core.xml', ooxml_core_properties($title));
    $zip->add('docProps/app.xml', ooxml_app_properties('Microsoft Office PowerPoint'));

    return $zip->bytes();
}

/** The section list PowerPoint keeps in an extension on the presentation part. */
function pptx_sections(?array $names): string
{
    if ($names === null || $names === []) {
        return '';
    }

    $sections = '';
    foreach ($names as $index => $name) {
        $sections .= '<p14:section name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES).'"'
            .' id="{00000000-0000-0000-0000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT).'}">'
            .'<p14:sldIdLst><p14:sldId id="'.(256 + $index).'"/></p14:sldIdLst>'
            .'</p14:section>';
    }

    return '<p:extLst><p:ext uri="{521415D9-36F7-43E2-AB2F-B90AF26B5E84}">'
        .'<p14:sectionLst xmlns:p14="http://schemas.microsoft.com/office/powerpoint/2010/main">'
        .$sections
        .'</p14:sectionLst></p:ext></p:extLst>';
}

/** A video, as a picture whose non-visual properties name a media file. */
function pptx_video(int $id, string $name, string $relId, ?string $descr = 'Recording of the 2026 open day welcome talk.'): string
{
    $descrAttribute = $descr === null ? '' : ' descr="'.htmlspecialchars($descr, ENT_XML1 | ENT_QUOTES).'"';

    return '<p:pic>'
        .'<p:nvPicPr>'
        .'<p:cNvPr id="'.$id.'" name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES).'"'.$descrAttribute.'/>'
        .'<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>'
        .'<p:nvPr><a:videoFile r:link="'.$relId.'"/></p:nvPr>'
        .'</p:nvPicPr>'
        .'<p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
        .'<p:spPr><a:xfrm><a:off x="838200" y="2000250"/><a:ext cx="4572000" cy="2571750"/></a:xfrm>'
        .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
        .'</p:pic>';
}

function pptx_good(): array
{
    $slides = [
        pptx_slide(pptx_title_shape('Accessibility Review 2026').pptx_body_shape(3, 'Where we are and what happens next.')),
        pptx_slide(
            pptx_title_shape('Document Backlog')
            .pptx_body_shape(3, '1,240 PDFs in the asset library.')
            .pptx_picture(4, 'Chart 1', 'Bar chart: 890 of 1,240 PDFs are untagged.')
        ),
        pptx_slide(pptx_title_shape('Next Steps').pptx_body_shape(3, 'Triage by severity, then remediate.')),
    ];

    return [
        'path' => 'pptx/good.pptx',
        'format' => 'pptx',
        'status' => 'pass',
        'summary' => 'Three slides, each with a distinct non-empty title placeholder, and every picture described.',
        'expect' => [],
        'expect_absent' => ['pptx.slide_missing_title', 'pptx.duplicate_slide_titles', 'pptx.shape_missing_alt'],
        'notes' => 'The primary negative control for PowerPoint.',
        'bytes' => pptx_build($slides, 'Accessibility Review 2026', true),
    ];
}

function pptx_slide_missing_title(): array
{
    $slides = [
        pptx_slide(pptx_title_shape('Quarterly Update').pptx_body_shape(3, 'Slide one has a title.')),
        pptx_slide(pptx_body_shape(3, 'Slide two has no title placeholder at all.')),
        pptx_slide(pptx_title_shape('').pptx_body_shape(3, 'Slide three has a title placeholder with no text in it.')),
    ];

    return [
        'path' => 'pptx/slide-missing-title.pptx',
        'format' => 'pptx',
        'status' => 'fail',
        'summary' => 'Three slides: titled, no title placeholder, empty title placeholder.',
        'expect' => [['rule' => 'pptx.slide_missing_title', 'severity' => 'critical', 'count' => 2]],
        'expect_absent' => ['pptx.duplicate_slide_titles', 'pptx.shape_missing_alt'],
        'notes' => 'An empty title placeholder is as bad as a missing one, and it is the more common authoring mistake. Findings must be located to slides 2 and 3.',
        'bytes' => pptx_build($slides, 'Quarterly Update'),
    ];
}

function pptx_duplicate_slide_titles(): array
{
    $slides = [
        pptx_slide(pptx_title_shape('Results').pptx_body_shape(3, 'First results slide.')),
        pptx_slide(pptx_title_shape('Results').pptx_body_shape(3, 'Second results slide, same title.')),
        pptx_slide(pptx_title_shape('Results').pptx_body_shape(3, 'Third results slide, same title again.')),
        pptx_slide(pptx_title_shape('Conclusions').pptx_body_shape(3, 'A distinct title.')),
    ];

    return [
        'path' => 'pptx/duplicate-slide-titles.pptx',
        'format' => 'pptx',
        'status' => 'fail',
        'summary' => 'Four slides. Three share the title "Results"; the fourth is distinct.',
        'expect' => [['rule' => 'pptx.duplicate_slide_titles', 'severity' => 'minor', 'count' => 1]],
        'expect_absent' => ['pptx.slide_missing_title', 'pptx.shape_missing_alt'],
        'notes' => 'Microsoft treats this as a tip, which maps to minor. One finding for the duplicate group rather than one per slide; the fixture uses three duplicates so a per-slide implementation is visibly wrong.',
        'bytes' => pptx_build($slides, 'Results Deck'),
    ];
}

function pptx_shape_missing_alt(): array
{
    $slides = [
        pptx_slide(
            pptx_title_shape('Enrolment')
            .pptx_picture(3, 'Picture 2', 'Photograph of the new science block from the north entrance.')
            .pptx_decorative_shape(4, 'Rounded Rectangle 3', 'Callout: 42% growth since 2024.')
        ),
        pptx_slide(
            pptx_title_shape('Estates')
            .pptx_picture(3, 'Picture 2', null)
            .pptx_decorative_shape(4, 'Rounded Rectangle 3', '')
        ),
    ];

    return [
        'path' => 'pptx/shape-missing-alt.pptx',
        'format' => 'pptx',
        'status' => 'fail',
        'summary' => 'Slide 1 describes both its picture and its drawn shape. Slide 2 describes neither.',
        'expect' => [['rule' => 'pptx.shape_missing_alt', 'severity' => 'critical', 'count' => 2]],
        'expect_absent' => ['pptx.slide_missing_title', 'pptx.duplicate_slide_titles'],
        'notes' => 'Covers both p:pic and p:sp: alt text lives on p:cNvPr/@descr for both, and a rule that only walks pictures will miss half of a real deck.',
        'bytes' => pptx_build($slides, 'Estates Review', true),
    ];
}

function pptx_reading_order(): array
{
    // Slide 2 puts the body text before the title in the shape tree while the
    // title still sits above it on screen. A screen reader follows the tree, so
    // it reads the slide bottom-first.
    $shapes = pptx_body_shape(3, 'This paragraph is read first, though it appears below the title.')
        .pptx_title_shape('Estates Review');

    $slides = [
        pptx_slide(pptx_title_shape('Agenda').pptx_body_shape(3, 'Read in the order it is laid out.')),
        pptx_slide($shapes),
    ];

    return [
        'path' => 'pptx/reading-order.pptx',
        'format' => 'pptx',
        'status' => 'fail',
        'summary' => 'Slide 2 lists its body text before its title in p:spTree while the title is positioned above it.',
        'expect' => [['rule' => 'pptx.reading_order', 'severity' => 'minor', 'count' => 1]],
        'expect_absent' => ['pptx.slide_missing_title', 'pptx.duplicate_slide_titles', 'pptx.shape_missing_alt'],
        'notes' => 'Spec §12 warns this rule produces false positives on decorative layouts, so it ships as a tip rather than a warning and only fires when one shape sits entirely above another it follows in the tree. Slide 1 is the negative control: same two shapes, correct order.',
        'bytes' => pptx_build($slides, 'Estates Review'),
    ];
}

function pptx_media_without_captions(): array
{
    $slides = [
        pptx_slide(pptx_title_shape('Welcome').pptx_video(3, 'Welcome talk', 'rId3')),
        pptx_slide(pptx_title_shape('Campus tour').pptx_video(3, 'Campus tour', 'rId3')),
    ];

    return [
        'path' => 'pptx/media-without-captions.pptx',
        'format' => 'pptx',
        'status' => 'fail',
        'summary' => 'Two slides each embedding a video. Slide 1 carries a caption relationship; slide 2 carries none.',
        'expect' => [['rule' => 'pptx.media_without_captions', 'severity' => 'moderate', 'count' => 1]],
        'expect_absent' => ['pptx.slide_missing_title', 'pptx.shape_missing_alt', 'pptx.duplicate_slide_titles'],
        'notes' => 'The caption relationship type here is the Microsoft 2016 extension, written from documentation rather than captured from a real captioned deck — so the negative control tests our own assumption. The finding is worded as "captions could not be found", which stays true either way, rather than as an assertion that the video is uncaptioned.',
        'bytes' => pptx_build($slides, 'Open Day', true, [
            'media_file' => true,
            'slide_rels' => [
                1 => [
                    ['id' => 'rId3', 'type' => NS_REL.'/video', 'target' => '../media/media1.mp4'],
                    ['id' => 'rId4', 'type' => 'http://schemas.microsoft.com/office/2016/01/relationships/videoCaptions', 'target' => '../media/media1.vtt'],
                ],
                2 => [
                    ['id' => 'rId3', 'type' => NS_REL.'/video', 'target' => '../media/media1.mp4'],
                ],
            ],
        ]),
    ];
}

function pptx_default_section_names(): array
{
    $slides = [
        pptx_slide(pptx_title_shape('Introduction').pptx_body_shape(3, 'First section.')),
        pptx_slide(pptx_title_shape('Findings').pptx_body_shape(3, 'Second section.')),
        pptx_slide(pptx_title_shape('Costs').pptx_body_shape(3, 'Third section.')),
        pptx_slide(pptx_title_shape('Next steps').pptx_body_shape(3, 'Fourth section.')),
    ];

    return [
        'path' => 'pptx/default-section-names.pptx',
        'format' => 'pptx',
        'status' => 'fail',
        'summary' => 'Four sections: two left at PowerPoint\'s default name, two sharing a name, one named properly.',
        'expect' => [['rule' => 'pptx.default_section_names', 'severity' => 'minor', 'count' => 3]],
        'expect_absent' => ['pptx.slide_missing_title', 'pptx.duplicate_slide_titles', 'pptx.shape_missing_alt'],
        'notes' => 'Sections live in a p14 extension on the presentation part, not in the presentation schema proper. Three findings: the two "Untitled Section" entries and the second "Estates". The slide titles are all distinct, so a rule that confused sections with slides would be visible.',
        'bytes' => pptx_build($slides, 'Annual Review', false, [
            'sections' => ['Untitled Section', 'Estates', 'Untitled Section', 'Estates'],
        ]),
    ];
}

function pptx_alt_text_not_descriptive(): array
{
    $slides = [
        pptx_slide(
            pptx_title_shape('Estates')
            .pptx_picture(3, 'Picture 2', 'Photograph of the new science block from the north entrance.')
            .pptx_picture(4, 'Picture 4', 'slide1.png', 3200000)
            .pptx_decorative_shape(5, 'Rounded Rectangle 3', 'Rounded Rectangle 3')
        ),
    ];

    return [
        'path' => 'pptx/alt-text-not-descriptive.pptx',
        'format' => 'pptx',
        'status' => 'fail',
        'summary' => 'Three objects, all with alt text: one describes the picture, one is a filename, one is the shape\'s own name.',
        'expect' => [
            ['rule' => 'pptx.alt_text_is_filename', 'severity' => 'critical', 'count' => 1],
            ['rule' => 'pptx.alt_text_not_descriptive', 'severity' => 'critical', 'count' => 1],
        ],
        'expect_absent' => ['pptx.shape_missing_alt', 'pptx.slide_missing_title', 'pptx.reading_order'],
        'notes' => 'Every object here has alt text, so a rule that only checks presence reports a clean slide. The third case is alt text copied from the object\'s own name, which no list of generic words could catch — it is found by comparing the two.',
        'bytes' => pptx_build($slides, 'Estates', true),
    ];
}
