<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml;

/**
 * The XML namespaces the three OOXML formats are written in, and the prefixes
 * this package uses for them.
 *
 * Registered on every part, whatever its format. A DOCX will never contain a
 * `p:sld` and an XLSX will never contain a `w:tbl`, so a single map costs
 * nothing and means a rule can be written without first working out which
 * prefixes the document happened to declare. Documents are free to use
 * different prefixes for the same namespaces — Word and LibreOffice do not
 * always agree — which is exactly why rules must never match on prefixes.
 */
final class Namespaces
{
    /** @var array<string, string> */
    public const MAP = [
        // WordprocessingML
        'w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',

        // PresentationML
        'p' => 'http://schemas.openxmlformats.org/presentationml/2006/main',

        // SpreadsheetML
        'x' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
        'xdr' => 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing',

        // DrawingML, shared by all three
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',

        // Package plumbing
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'rel' => 'http://schemas.openxmlformats.org/package/2006/relationships',
        'ct' => 'http://schemas.openxmlformats.org/package/2006/content-types',

        // Document properties
        'cp' => 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties',
        'ep' => 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',

        // Markup compatibility, which wraps anything a reader may not understand
        'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',

        // PowerPoint's own extension namespace. Sections live here rather than
        // in the presentation schema proper, because they were added later.
        'p14' => 'http://schemas.microsoft.com/office/powerpoint/2010/main',
    ];

    /** The base of every relationship type, so rules can name the short form. */
    public const RELATIONSHIP_TYPE_BASE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    public const PACKAGE_RELATIONSHIP_TYPE_BASE = 'http://schemas.openxmlformats.org/package/2006/relationships/';
}
