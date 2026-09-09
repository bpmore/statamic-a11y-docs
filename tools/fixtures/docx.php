<?php

declare(strict_types=1);

namespace Bpmore\FixtureTools;

function docx_fixtures(): array
{
    return [
        docx_good(),
        docx_image_missing_alt(),
        docx_alt_text_is_filename(),
        docx_no_headings(),
        docx_table_missing_header(),
        docx_complex_table(),
        docx_link_text_not_meaningful(),
        docx_no_title_no_lang(),
        docx_protected(),
        docx_form_controls_untitled(),
        docx_header_image_missing_alt(),
    ];
}

/**
 * @param array{
 *   body: string, title?: ?string, lang?: ?string, protected?: bool,
 *   media?: bool, hyperlinks?: array<string, string>
 * } $options
 */
function docx_build(array $options): string
{
    $title = array_key_exists('title', $options) ? $options['title'] : 'Fixture Document';
    $lang = array_key_exists('lang', $options) ? $options['lang'] : 'en-US';
    $protected = $options['protected'] ?? false;
    $media = $options['media'] ?? false;
    $hyperlinks = $options['hyperlinks'] ?? [];
    $header = $options['header'] ?? null;

    $documentRels = [
        ['id' => 'rId1', 'type' => NS_REL.'/styles', 'target' => 'styles.xml'],
        ['id' => 'rId2', 'type' => NS_REL.'/settings', 'target' => 'settings.xml'],
    ];
    if ($media) {
        $documentRels[] = ['id' => 'rId3', 'type' => NS_REL.'/image', 'target' => 'media/image1.png'];
    }
    foreach ($hyperlinks as $id => $target) {
        $documentRels[] = ['id' => $id, 'type' => NS_REL.'/hyperlink', 'target' => $target, 'mode' => 'External'];
    }

    $headerReference = $header === null ? '' : '<w:headerReference w:type="default" r:id="rId20"/>';
    if ($header !== null) {
        $documentRels[] = ['id' => 'rId20', 'type' => NS_REL.'/header', 'target' => 'header1.xml'];
    }

    $document = OOXML_DECL
        .'<w:document'
        .' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        .' xmlns:r="'.NS_REL.'"'
        .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
        .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        .' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        .'<w:body>'
        .$options['body']
        .'<w:sectPr>'.$headerReference.'<w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
        .'</w:body></w:document>';

    $langDefault = $lang === null ? '' : '<w:lang w:val="'.$lang.'" w:eastAsia="'.$lang.'" w:bidi="ar-SA"/>';
    $styles = OOXML_DECL
        .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:docDefaults><w:rPrDefault><w:rPr>'.$langDefault.'</w:rPr></w:rPrDefault><w:pPrDefault/></w:docDefaults>'
        .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/>'
        .'<w:pPr><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'
        .'<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/>'
        .'<w:pPr><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style>'
        .'<w:style w:type="character" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/>'
        .'<w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr></w:style>'
        .'</w:styles>';

    $protection = $protected
        ? '<w:documentProtection w:edit="readOnly" w:enforcement="1" w:cryptProviderType="rsaAES"'
            .' w:cryptAlgorithmClass="hash" w:cryptAlgorithmType="typeAny" w:cryptAlgorithmSid="14"'
            .' w:cryptSpinCount="100000" w:hash="RGl4b24=" w:salt="U2FsdA=="/>'
        : '';
    $settings = OOXML_DECL
        .'<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .$protection
        .'</w:settings>';

    $defaults = [
        'rels' => 'application/vnd.openxmlformats-package.relationships+xml',
        'xml' => 'application/xml',
    ];
    if ($media) {
        $defaults['png'] = 'image/png';
    }

    $overrides = [
        '/word/document.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        '/word/styles.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
        '/word/settings.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml',
    ];
    if ($header !== null) {
        $overrides['/word/header1.xml'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml';
    }
    $overrides['/docProps/core.xml'] = 'application/vnd.openxmlformats-package.core-properties+xml';
    $overrides['/docProps/app.xml'] = 'application/vnd.openxmlformats-officedocument.extended-properties+xml';

    $zip = new Zip;
    $zip->add('[Content_Types].xml', ooxml_content_types($defaults, $overrides));
    $zip->add('_rels/.rels', ooxml_rels([
        ['id' => 'rId1', 'type' => NS_REL.'/officeDocument', 'target' => 'word/document.xml'],
        ['id' => 'rId2', 'type' => NS_PKG_REL.'/metadata/core-properties', 'target' => 'docProps/core.xml'],
        ['id' => 'rId3', 'type' => NS_REL.'/extended-properties', 'target' => 'docProps/app.xml'],
    ]));
    $zip->add('word/document.xml', $document);
    $zip->add('word/_rels/document.xml.rels', ooxml_rels($documentRels));
    $zip->add('word/styles.xml', $styles);
    $zip->add('word/settings.xml', $settings);
    if ($header !== null) {
        $zip->add('word/header1.xml', OOXML_DECL
            .'<w:hdr'
            .' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:r="'.NS_REL.'"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .$header
            .'</w:hdr>');
        // The header keeps its own relationships, which is exactly why an image
        // in one is easy for a rule to miss.
        $zip->add('word/_rels/header1.xml.rels', ooxml_rels([
            ['id' => 'rId1', 'type' => NS_REL.'/image', 'target' => 'media/image1.png'],
        ]));
    }
    if ($media) {
        $zip->add('word/media/image1.png', ooxml_png());
    }
    $zip->add('docProps/core.xml', ooxml_core_properties($title, $lang));
    $zip->add('docProps/app.xml', ooxml_app_properties('Microsoft Office Word'));

    return $zip->bytes();
}

function docx_paragraph(string $text, ?string $style = null): string
{
    $pPr = $style === null ? '' : '<w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr>';

    return '<w:p>'.$pPr.'<w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>';
}

/** A null $descr means the attribute is absent; '' means it is present but empty. */
function docx_picture(int $id, string $name, ?string $descr, string $relId = 'rId3'): string
{
    $descrAttribute = $descr === null ? '' : ' descr="'.htmlspecialchars($descr, ENT_XML1 | ENT_QUOTES).'"';

    return '<w:p><w:r><w:drawing>'
        .'<wp:inline distT="0" distB="0" distL="0" distR="0">'
        .'<wp:extent cx="914400" cy="914400"/>'
        .'<wp:effectExtent l="0" t="0" r="0" b="0"/>'
        .'<wp:docPr id="'.$id.'" name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES).'"'.$descrAttribute.'/>'
        .'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
        .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        .'<pic:pic>'
        .'<pic:nvPicPr><pic:cNvPr id="0" name="image1.png"/><pic:cNvPicPr/></pic:nvPicPr>'
        .'<pic:blipFill><a:blip r:embed="'.$relId.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="914400" cy="914400"/></a:xfrm>'
        .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        .'</pic:pic></a:graphicData></a:graphic>'
        .'</wp:inline></w:drawing></w:r></w:p>';
}

/** @param list<list<string>> $rows */
function docx_table(array $rows, bool $headerRow): string
{
    $columns = count($rows[0]);
    $grid = str_repeat('<w:gridCol w:w="3000"/>', $columns);

    $xml = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblLook w:val="04A0"/></w:tblPr>'
        .'<w:tblGrid>'.$grid.'</w:tblGrid>';

    foreach ($rows as $index => $cells) {
        $trPr = $index === 0 && $headerRow ? '<w:trPr><w:tblHeader/></w:trPr>' : '';
        $xml .= '<w:tr>'.$trPr;
        foreach ($cells as $cell) {
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/></w:tcPr>'
                .docx_paragraph($cell)
                .'</w:tc>';
        }
        $xml .= '</w:tr>';
    }

    return $xml.'</w:tbl>';
}

function docx_hyperlink(string $relId, string $linkText, string $before = '', string $after = ''): string
{
    $run = fn (string $text) => $text === ''
        ? ''
        : '<w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r>';

    return '<w:p>'.$run($before)
        .'<w:hyperlink r:id="'.$relId.'"><w:r><w:rPr><w:rStyle w:val="Hyperlink"/></w:rPr>'
        .'<w:t xml:space="preserve">'.htmlspecialchars($linkText, ENT_XML1).'</w:t></w:r></w:hyperlink>'
        .$run($after).'</w:p>';
}

function docx_content_control(int $id, string $tag, ?string $alias, string $placeholder): string
{
    $aliasElement = $alias === null ? '' : '<w:alias w:val="'.htmlspecialchars($alias, ENT_XML1 | ENT_QUOTES).'"/>';

    return '<w:sdt><w:sdtPr>'.$aliasElement
        .'<w:tag w:val="'.htmlspecialchars($tag, ENT_XML1 | ENT_QUOTES).'"/>'
        .'<w:id w:val="'.$id.'"/><w:text/></w:sdtPr>'
        .'<w:sdtContent>'.docx_paragraph($placeholder).'</w:sdtContent></w:sdt>';
}

function docx_good(): array
{
    $body = docx_paragraph('Accessibility Policy 2026', 'Heading1')
        .docx_paragraph('This document is the negative control for the Word rule set.')
        .docx_paragraph('Scope', 'Heading2')
        .docx_paragraph('Every rule in the Word rule set should stay silent on this file.')
        .docx_picture(1, 'Chart 1', 'Bar chart: enrolment rose from 12,400 in 2024 to 14,900 in 2026.')
        .docx_paragraph('Contacts', 'Heading2')
        .docx_table([['Team', 'Email'], ['Web', 'web@example.edu'], ['Estates', 'estates@example.edu']], true)
        .docx_hyperlink('rId10', 'Accessibility statement for example.edu', 'Read the ', ' before submitting.');

    return [
        'path' => 'docx/good.docx',
        'format' => 'docx',
        'status' => 'pass',
        'summary' => 'Title, language, heading styles, described image, table with a repeating header row, meaningful link text.',
        'expect' => [],
        'expect_absent' => [
            'docx.image_missing_alt', 'docx.alt_text_is_filename', 'docx.no_headings',
            'docx.table_missing_header_row', 'docx.complex_table', 'docx.link_text_not_meaningful',
            'docx.no_title', 'docx.no_lang', 'docx.document_protected', 'docx.content_control_untitled',
        ],
        'notes' => 'The primary negative control for Word.',
        'bytes' => docx_build([
            'body' => $body,
            'title' => 'Accessibility Policy 2026',
            'media' => true,
            'hyperlinks' => ['rId10' => 'https://example.edu/accessibility'],
        ]),
    ];
}

function docx_image_missing_alt(): array
{
    $body = docx_paragraph('Campus Images', 'Heading1')
        .docx_picture(1, 'Picture 1', 'The main quad on an open day, with prospective students at stalls.')
        .docx_picture(2, 'Picture 2', '')
        .docx_picture(3, 'Picture 3', null);

    return [
        'path' => 'docx/image-missing-alt.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => 'Three images: one described, one with descr="", one with no descr attribute at all.',
        'expect' => [['rule' => 'docx.image_missing_alt', 'severity' => 'critical', 'count' => 2]],
        'expect_absent' => ['docx.alt_text_is_filename', 'docx.no_headings', 'docx.no_title', 'docx.no_lang'],
        'notes' => 'Absent and empty are different in the XML and identical to a screen reader user; both must be reported. The described image must not be.',
        'bytes' => docx_build([
            'body' => $body,
            'title' => 'Campus Images',
            'media' => true,
        ]),
    ];
}

function docx_alt_text_is_filename(): array
{
    $body = docx_paragraph('Gallery', 'Heading1')
        .docx_picture(1, 'Picture 1', 'Students queueing outside the library at 8am on results day.')
        .docx_picture(2, 'Picture 2', 'image1.png')
        .docx_picture(3, 'Picture 3', 'DSC_0042.JPG')
        .docx_picture(4, 'Picture 4', 'final-v2-FINAL.jpeg')
        .docx_picture(5, 'Picture 5', 'Picture 3');

    return [
        'path' => 'docx/alt-text-is-filename.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => 'Alt text is present on every image; three are filenames and one is a default shape name.',
        'expect' => [
            ['rule' => 'docx.alt_text_is_filename', 'severity' => 'critical', 'count' => 3],
            ['rule' => 'docx.alt_text_not_descriptive', 'severity' => 'critical', 'count' => 1],
        ],
        'expect_absent' => ['docx.image_missing_alt', 'docx.no_title', 'docx.no_lang'],
        'notes' => 'Presence is not quality. Image 5 carries "Picture 3" — a default Word shape name rather than a filename — and is reported under its own rule: the two are the same failure for a reader but different mistakes to explain, and separating them keeps each message specific.',
        'bytes' => docx_build([
            'body' => $body,
            'title' => 'Gallery',
            'media' => true,
        ]),
    ];
}

function docx_no_headings(): array
{
    $body = '';
    for ($i = 1; $i <= 30; $i++) {
        $body .= docx_paragraph("Paragraph $i. Long-form body text with no structure applied to it, formatted by hand with bold and larger type instead of heading styles.");
    }

    return [
        'path' => 'docx/no-headings.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => '30 body paragraphs, not one of them using a Heading style.',
        'expect' => [['rule' => 'docx.no_headings', 'severity' => 'serious', 'count' => 1]],
        'expect_absent' => ['docx.no_title', 'docx.no_lang', 'docx.image_missing_alt'],
        'notes' => 'One finding for the document, not one per paragraph. The rule needs a paragraph-count threshold so a two-line memo is not reported.',
        'bytes' => docx_build(['body' => $body, 'title' => 'Unstructured Memo']),
    ];
}

function docx_table_missing_header(): array
{
    $body = docx_paragraph('Tables', 'Heading1')
        .docx_paragraph('The first table repeats its header row. The second does not.')
        .docx_table([['Building', 'Opened'], ['Library', '1974'], ['Science Block', '2011']], true)
        .docx_paragraph('Second table', 'Heading2')
        .docx_table([['Course', 'Places'], ['History', '60'], ['Physics', '45']], false);

    return [
        'path' => 'docx/table-missing-header.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => 'Two tables; only the first has w:trPr/w:tblHeader on its first row.',
        'expect' => [['rule' => 'docx.table_missing_header_row', 'severity' => 'serious', 'count' => 1]],
        'expect_absent' => ['docx.complex_table', 'docx.no_headings', 'docx.no_title', 'docx.no_lang'],
        'notes' => 'One finding per table, located to the table, not one for the document.',
        'bytes' => docx_build(['body' => $body, 'title' => 'Tables']),
    ];
}

function docx_complex_table(): array
{
    $table = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr>'
        .'<w:tblGrid><w:gridCol w:w="3000"/><w:gridCol w:w="3000"/><w:gridCol w:w="3000"/></w:tblGrid>'
        .'<w:tr><w:trPr><w:tblHeader/></w:trPr>'
        .'<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/></w:tcPr>'.docx_paragraph('Site').'</w:tc>'
        .'<w:tc><w:tcPr><w:tcW w:w="6000" w:type="dxa"/><w:gridSpan w:val="2"/></w:tcPr>'.docx_paragraph('Opening hours').'</w:tc>'
        .'</w:tr>'
        .'<w:tr>'
        .'<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/><w:vMerge w:val="restart"/></w:tcPr>'.docx_paragraph('Main campus').'</w:tc>'
        .'<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/></w:tcPr>'.docx_paragraph('Weekdays').'</w:tc>'
        .'<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/></w:tcPr>'.docx_paragraph('08:00-20:00').'</w:tc>'
        .'</w:tr>'
        .'<w:tr>'
        .'<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/><w:vMerge/></w:tcPr>'.docx_paragraph('').'</w:tc>'
        .'<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/></w:tcPr>'.docx_paragraph('Weekends').'</w:tc>'
        .'<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/></w:tcPr>'.docx_paragraph('10:00-16:00').'</w:tc>'
        .'</w:tr>'
        .'</w:tbl>';

    $body = docx_paragraph('Opening Hours', 'Heading1')
        .docx_paragraph('A merged-cell layout that a screen reader cannot navigate predictably.')
        .$table;

    return [
        'path' => 'docx/complex-table.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => 'One table using both w:gridSpan and w:vMerge. Its header row is correctly marked.',
        'expect' => [['rule' => 'docx.complex_table', 'severity' => 'moderate', 'count' => 1]],
        'expect_absent' => ['docx.table_missing_header_row', 'docx.no_title', 'docx.no_lang'],
        'notes' => 'Header row is present on purpose: complexity and a missing header are independent problems, and this fixture must not trip the header rule.',
        'bytes' => docx_build(['body' => $body, 'title' => 'Opening Hours']),
    ];
}

function docx_link_text_not_meaningful(): array
{
    $body = docx_paragraph('Links', 'Heading1')
        .docx_hyperlink('rId10', 'Accessibility statement for example.edu', 'Good: read the ', '.')
        .docx_hyperlink('rId11', 'click here', 'To download the prospectus, ', '.')
        .docx_hyperlink('rId12', 'https://example.edu/policies/accessibility-policy-2026.pdf', 'The policy is at ', '.')
        .docx_hyperlink('rId13', 'Read more', 'Term dates have changed. ', '.');

    return [
        'path' => 'docx/link-text-not-meaningful.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => 'Four hyperlinks: one descriptive, one "click here", one bare URL, one "Read more".',
        'expect' => [['rule' => 'docx.link_text_not_meaningful', 'severity' => 'serious', 'count' => 3]],
        'expect_absent' => ['docx.no_headings', 'docx.no_title', 'docx.no_lang'],
        'notes' => 'Matching must be case-insensitive: the fixture uses "Read more", not "read more". The descriptive link must not be reported.',
        'bytes' => docx_build([
            'body' => $body,
            'title' => 'Links',
            'hyperlinks' => [
                'rId10' => 'https://example.edu/accessibility',
                'rId11' => 'https://example.edu/prospectus.pdf',
                'rId12' => 'https://example.edu/policies/accessibility-policy-2026.pdf',
                'rId13' => 'https://example.edu/term-dates',
            ],
        ]),
    ];
}

function docx_no_title_no_lang(): array
{
    $body = docx_paragraph('Untitled Document', 'Heading1')
        .docx_paragraph('docProps/core.xml has no dc:title, and no w:lang is set anywhere.');

    return [
        'path' => 'docx/no-title-no-lang.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => 'No dc:title in docProps/core.xml and no w:lang in the style defaults or any run.',
        'expect' => [
            ['rule' => 'docx.no_title', 'severity' => 'moderate', 'count' => 1],
            ['rule' => 'docx.no_lang', 'severity' => 'serious', 'count' => 1],
        ],
        'expect_absent' => ['docx.no_headings', 'docx.image_missing_alt'],
        'notes' => 'The Heading 1 text reads "Untitled Document" — a visible heading is not a document title, and the rule must not read one as the other.',
        'bytes' => docx_build(['body' => $body, 'title' => null, 'lang' => null]),
    ];
}

function docx_protected(): array
{
    $body = docx_paragraph('Read-only Form', 'Heading1')
        .docx_paragraph('w:documentProtection with w:edit="readOnly" and enforcement on.');

    return [
        'path' => 'docx/protected.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => 'word/settings.xml carries an enforced w:documentProtection element.',
        'expect' => [['rule' => 'docx.document_protected', 'severity' => 'critical', 'count' => 1]],
        'expect_absent' => ['docx.no_title', 'docx.no_lang', 'docx.no_headings'],
        'notes' => 'Enforcement is the part that matters: w:enforcement="0" means the protection is recorded but not applied, and must not be reported.',
        'bytes' => docx_build(['body' => $body, 'title' => 'Read-only Form', 'protected' => true]),
    ];
}

function docx_form_controls_untitled(): array
{
    $body = docx_paragraph('Application', 'Heading1')
        .docx_content_control(101, 'applicantName', 'Applicant name', 'Enter your name')
        .docx_content_control(102, 'applicantDob', null, 'Enter your date of birth')
        .docx_content_control(103, 'applicantCourse', null, 'Enter your course');

    return [
        'path' => 'docx/form-controls-untitled.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => 'Three w:sdt content controls; only the first has a w:alias title.',
        'expect' => [['rule' => 'docx.content_control_untitled', 'severity' => 'serious', 'count' => 2]],
        'expect_absent' => ['docx.no_title', 'docx.no_lang', 'docx.no_headings'],
        'notes' => 'All three have a w:tag. A tag is a developer identifier, not a label; only w:alias is announced.',
        'bytes' => docx_build(['body' => $body, 'title' => 'Application']),
    ];
}

function docx_header_image_missing_alt(): array
{
    $header = docx_picture(1, 'Logo', null, 'rId1')
        .docx_paragraph('University of Somewhere');

    $body = docx_paragraph('Estates Report', 'Heading1')
        .docx_paragraph('The letterhead logo sits in the header, not in the body.')
        .docx_picture(2, 'Chart 1', 'Bar chart: maintenance spend fell by 12% in 2026.');

    return [
        'path' => 'docx/header-image-missing-alt.docx',
        'format' => 'docx',
        'status' => 'fail',
        'summary' => 'An undescribed logo in word/header1.xml, and a properly described chart in the body.',
        'expect' => [['rule' => 'docx.image_missing_alt', 'severity' => 'critical', 'count' => 1]],
        'expect_absent' => ['docx.alt_text_is_filename', 'docx.no_title', 'docx.no_lang', 'docx.no_headings'],
        'notes' => 'Headers and footers are separate parts with their own relationships, so a rule that only reads word/document.xml misses every letterhead logo in the library — which is most of them. The body image is described, so the single finding must come from the header.',
        'bytes' => docx_build([
            'body' => $body,
            'title' => 'Estates Report',
            'media' => true,
            'header' => $header,
        ]),
    ];
}
