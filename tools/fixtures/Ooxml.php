<?php

declare(strict_types=1);

namespace Bpmore\FixtureTools;

/**
 * Shared OOXML scaffolding.
 *
 * DOCX, PPTX and XLSX are the same container — a ZIP of XML parts wired
 * together by relationship files — which is the whole reason one reader can
 * serve three formats. The fixtures are built the same way for the same reason.
 */
const OOXML_DECL = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";

const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

/** A 16x16 PNG. Small, valid, and byte-identical every run. */
function ooxml_png(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAANklEQVQoz2NU8O'
        .'thIAUwMZAISNbAAmddWJSCR51B3Bx6OYlkDYw0D1YCoQQPHDp6ehCGEskaAG22CRCKIQI0AAAAAElFTkSuQmCC'
    );
}

/** @param list<array{id: string, type: string, target: string, mode?: string}> $relationships */
function ooxml_rels(array $relationships): string
{
    $xml = OOXML_DECL.'<Relationships xmlns="'.NS_PKG_REL.'">';
    foreach ($relationships as $rel) {
        $xml .= '<Relationship Id="'.$rel['id'].'" Type="'.$rel['type'].'" Target="'.$rel['target'].'"';
        if (isset($rel['mode'])) {
            $xml .= ' TargetMode="'.$rel['mode'].'"';
        }
        $xml .= '/>';
    }

    return $xml.'</Relationships>';
}

/**
 * @param  array<string, string>  $defaults  extension => content type
 * @param  array<string, string>  $overrides  part name => content type
 */
function ooxml_content_types(array $defaults, array $overrides): string
{
    $xml = OOXML_DECL.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    foreach ($defaults as $extension => $type) {
        $xml .= '<Default Extension="'.$extension.'" ContentType="'.$type.'"/>';
    }
    foreach ($overrides as $part => $type) {
        $xml .= '<Override PartName="'.$part.'" ContentType="'.$type.'"/>';
    }

    return $xml.'</Types>';
}

/** docProps/core.xml. A null title means the element is absent, not empty. */
function ooxml_core_properties(?string $title, ?string $language = 'en-US'): string
{
    $xml = OOXML_DECL
        .'<cp:coreProperties'
        .' xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        .' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        .' xmlns:dcterms="http://purl.org/dc/terms/"'
        .' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
        .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
    if ($title !== null) {
        $xml .= '<dc:title>'.htmlspecialchars($title, ENT_XML1).'</dc:title>';
    }
    if ($language !== null) {
        $xml .= '<dc:language>'.$language.'</dc:language>';
    }
    $xml .= '<dc:creator>A11y Docs fixtures</dc:creator>'
        .'<cp:lastModifiedBy>A11y Docs fixtures</cp:lastModifiedBy>'
        .'<dcterms:created xsi:type="dcterms:W3CDTF">2026-01-01T00:00:00Z</dcterms:created>'
        .'<dcterms:modified xsi:type="dcterms:W3CDTF">2026-01-01T00:00:00Z</dcterms:modified>';

    return $xml.'</cp:coreProperties>';
}

function ooxml_app_properties(string $application): string
{
    return OOXML_DECL
        .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        .' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        .'<Application>'.$application.'</Application>'
        .'</Properties>';
}

/** A minimal but schema-shaped DrawingML theme, required by PPTX packages. */
function ooxml_theme(): string
{
    $colours = ['dk1' => '000000', 'lt1' => 'FFFFFF', 'dk2' => '1F3864', 'lt2' => 'E7E6E6',
        'accent1' => '204E8C', 'accent2' => 'B85C38', 'accent3' => '4F7A28', 'accent4' => '7A4F9E',
        'accent5' => '2E8B99', 'accent6' => 'A8842A', 'hlink' => '0563C1', 'folHlink' => '954F72'];

    $scheme = '';
    foreach ($colours as $name => $hex) {
        $scheme .= "<a:$name><a:srgbClr val=\"$hex\"/></a:$name>";
    }

    $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
    $line = '<a:ln w="9525" cap="flat" cmpd="sng" algn="ctr">'.$fill.'<a:prstDash val="solid"/></a:ln>';

    return OOXML_DECL
        .'<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Fixture">'
        .'<a:themeElements>'
        .'<a:clrScheme name="Fixture">'.$scheme.'</a:clrScheme>'
        .'<a:fontScheme name="Fixture">'
        .'<a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
        .'<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
        .'</a:fontScheme>'
        .'<a:fmtScheme name="Fixture">'
        .'<a:fillStyleLst>'.str_repeat($fill, 3).'</a:fillStyleLst>'
        .'<a:lnStyleLst>'.str_repeat($line, 3).'</a:lnStyleLst>'
        .'<a:effectStyleLst>'.str_repeat('<a:effectStyle><a:effectLst/></a:effectStyle>', 3).'</a:effectStyleLst>'
        .'<a:bgFillStyleLst>'.str_repeat($fill, 3).'</a:bgFillStyleLst>'
        .'</a:fmtScheme>'
        .'</a:themeElements>'
        .'</a:theme>';
}
