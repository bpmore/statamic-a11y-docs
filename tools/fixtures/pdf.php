<?php

declare(strict_types=1);

namespace Bpmore\FixtureTools;

/**
 * The PDF half of the fixture corpus.
 *
 * Each entry is one deliberately-shaped file plus the findings the inspector is
 * expected to produce from it. `expect_absent` is as important as `expect`: a
 * rule that fires on everything is worse than a rule that never fires, and the
 * near-miss fixtures are there to catch exactly that.
 */
function pdf_fixtures(): array
{
    return [
        pdf_tagged_good(),
        pdf_untagged(),
        pdf_no_title(),
        pdf_xmp_title_only(),
        pdf_title_not_displayed(),
        pdf_no_lang(),
        pdf_image_only_scan(),
        pdf_encrypted_no_extract(),
        pdf_encrypted_accessible(),
        pdf_long_no_bookmarks(),
        pdf_long_with_bookmarks(),
        pdf_form_unlabelled_fields(),
        pdf_figure_missing_alt(),
        pdf_no_tounicode(),
        pdf_corrupt_truncated(),
    ];
}

/** A single tagged page: marked content, a struct element per MCID, a parent tree. */
function pdf_tagged_pages(PdfBuilder $pdf, int $pagesNum, int $fontNum, int $structRoot, int $count): array
{
    $pageNums = [];
    $elemNums = [];
    $contents = [];

    for ($i = 0; $i < $count; $i++) {
        $pageNums[] = $pdf->reserve();
        $elemNums[] = $pdf->reserve();
    }

    $docElem = $pdf->reserve();
    $parentTree = $pdf->reserve();

    for ($i = 0; $i < $count; $i++) {
        $n = $i + 1;
        $content = "/P <</MCID 0>> BDC\nBT /F1 14 Tf 72 720 Td (Page $n of $count. Tagged body text.) Tj ET\nEMC\n";
        $stream = $pdf->stream('', $content);
        $contents[] = $stream;
        $pdf->obj(
            "<< /Type /Page /Parent $pagesNum 0 R /MediaBox [0 0 612 792]"
            ." /Resources << /Font << /F1 $fontNum 0 R >> >>"
            ." /Contents $stream 0 R /StructParents $i >>",
            $pageNums[$i]
        );
        $pdf->obj(
            "<< /Type /StructElem /S /P /P $docElem 0 R /Pg {$pageNums[$i]} 0 R /K 0 >>",
            $elemNums[$i]
        );
    }

    $kids = implode(' ', array_map(fn ($n) => "$n 0 R", $elemNums));
    $pdf->obj("<< /Type /StructElem /S /Document /P $structRoot 0 R /K [ $kids ] >>", $docElem);

    $nums = [];
    foreach ($elemNums as $i => $elem) {
        $nums[] = "$i [ $elem 0 R ]";
    }
    $pdf->obj('<< /Nums [ '.implode(' ', $nums).' ] >>', $parentTree);

    return [
        'pages' => $pageNums,
        'doc_elem' => $docElem,
        'parent_tree' => $parentTree,
        'next_key' => $count,
    ];
}

function pdf_xmp(string $title, string $language = 'en-US'): string
{
    $title = htmlspecialchars($title, ENT_XML1);

    return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
        ."<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n"
        ." <rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
        ."  <rdf:Description rdf:about=\"\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">\n"
        ."   <dc:title><rdf:Alt><rdf:li xml:lang=\"x-default\">$title</rdf:li></rdf:Alt></dc:title>\n"
        ."   <dc:language><rdf:Bag><rdf:li>$language</rdf:li></rdf:Bag></dc:language>\n"
        ."  </rdf:Description>\n"
        ." </rdf:RDF>\n"
        ."</x:xmpmeta>\n"
        .'<?xpacket end="w"?>';
}

function pdf_tagged_good(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $tagged = pdf_tagged_pages($pdf, $pagesNum, $font, $structRoot, 1);
    $pdf->obj("<< /Type /Pages /Kids [ {$tagged['pages'][0]} 0 R ] /Count 1 >>", $pagesNum);
    $pdf->obj(
        "<< /Type /StructTreeRoot /K [ {$tagged['doc_elem']} 0 R ]"
        ." /ParentTree {$tagged['parent_tree']} 0 R /ParentTreeNextKey {$tagged['next_key']} /RoleMap << >> >>",
        $structRoot
    );

    $metadata = $pdf->stream('/Type /Metadata /Subtype /XML', pdf_xmp('Accessible Annual Report 2026'));
    $info = $pdf->reserve();
    $pdf->obj('<< /Title '.$pdf->str('Accessible Annual Report 2026', $info).' /Producer '.$pdf->str('a11y-docs fixtures', $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang (en-US)"
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R"
        .' /ViewerPreferences << /DisplayDocTitle true >>'
        ." /Metadata $metadata 0 R >>",
        $catalog
    );

    return [
        'path' => 'pdf/tagged-good.pdf',
        'format' => 'pdf',
        'status' => 'pass',
        'summary' => 'Tagged, titled in both /Info and XMP, /Lang set, DisplayDocTitle true, extractable text.',
        'expect' => [],
        'expect_absent' => [
            'pdf.not_tagged', 'pdf.no_title', 'pdf.title_not_displayed', 'pdf.no_lang',
            'pdf.image_only', 'pdf.extraction_blocked', 'pdf.no_bookmarks',
            'pdf.unlabelled_form_fields', 'pdf.figure_missing_alt', 'pdf.no_tounicode',
        ],
        'notes' => 'The primary negative control. Uses Helvetica, one of the standard 14 fonts, so pdf.no_tounicode must exempt it rather than fire.',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_untagged(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $pages = [];
    for ($i = 1; $i <= 2; $i++) {
        $content = $pdf->stream('', "BT /F1 14 Tf 72 720 Td (Page $i. Readable text, but no structure at all.) Tj ET\n");
        $pages[] = $pdf->obj("<< /Type /Page /Parent $pagesNum 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 $font 0 R >> >> /Contents $content 0 R >>");
    }
    $kids = implode(' ', array_map(fn ($n) => "$n 0 R", $pages));
    $pdf->obj("<< /Type /Pages /Kids [ $kids ] /Count 2 >>", $pagesNum);

    $info = $pdf->reserve();
    $pdf->obj('<< /Title '.$pdf->str('Untagged Handbook', $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang (en-US)"
        .' /ViewerPreferences << /DisplayDocTitle true >> >>',
        $catalog
    );

    return [
        'path' => 'pdf/untagged.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'No /StructTreeRoot and no /MarkInfo. Everything else is correct, so this isolates the tagging rule.',
        'expect' => [['rule' => 'pdf.not_tagged', 'severity' => 'critical', 'count' => 1]],
        'expect_absent' => ['pdf.no_title', 'pdf.no_lang', 'pdf.title_not_displayed', 'pdf.no_bookmarks', 'pdf.image_only'],
        'notes' => 'Also the negative control for pdf.no_bookmarks: two pages and no /Outlines must not fire the rule, which only applies over 20 pages.',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_no_title(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $tagged = pdf_tagged_pages($pdf, $pagesNum, $font, $structRoot, 1);
    $pdf->obj("<< /Type /Pages /Kids [ {$tagged['pages'][0]} 0 R ] /Count 1 >>", $pagesNum);
    $pdf->obj("<< /Type /StructTreeRoot /K [ {$tagged['doc_elem']} 0 R ] /ParentTree {$tagged['parent_tree']} 0 R /ParentTreeNextKey {$tagged['next_key']} /RoleMap << >> >>", $structRoot);

    $info = $pdf->reserve();
    $pdf->obj('<< /Author '.$pdf->str('Communications Office', $info).' /Producer '.$pdf->str('a11y-docs fixtures', $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang (en-US)"
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R"
        .' /ViewerPreferences << /DisplayDocTitle true >> >>',
        $catalog
    );

    return [
        'path' => 'pdf/no-title.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'No /Info /Title and no XMP dc:title. /Info exists but only carries /Author.',
        'expect' => [['rule' => 'pdf.no_title', 'severity' => 'serious', 'count' => 1]],
        'expect_absent' => ['pdf.not_tagged', 'pdf.no_lang', 'pdf.title_not_displayed'],
        'notes' => 'A present-but-titleless /Info dictionary is the common case; do not treat "has /Info" as "has a title".',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_xmp_title_only(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $tagged = pdf_tagged_pages($pdf, $pagesNum, $font, $structRoot, 1);
    $pdf->obj("<< /Type /Pages /Kids [ {$tagged['pages'][0]} 0 R ] /Count 1 >>", $pagesNum);
    $pdf->obj("<< /Type /StructTreeRoot /K [ {$tagged['doc_elem']} 0 R ] /ParentTree {$tagged['parent_tree']} 0 R /ParentTreeNextKey {$tagged['next_key']} /RoleMap << >> >>", $structRoot);

    $metadata = $pdf->stream('/Type /Metadata /Subtype /XML', pdf_xmp('Title Lives Only In XMP'));
    $info = $pdf->reserve();
    $pdf->obj('<< /Producer '.$pdf->str('a11y-docs fixtures', $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang (en-US)"
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R"
        .' /ViewerPreferences << /DisplayDocTitle true >>'
        ." /Metadata $metadata 0 R >>",
        $catalog
    );

    return [
        'path' => 'pdf/xmp-title-only.pdf',
        'format' => 'pdf',
        'status' => 'pass',
        'summary' => 'No /Info /Title, but dc:title is present in the XMP packet.',
        'expect' => [],
        'expect_absent' => ['pdf.no_title', 'pdf.not_tagged', 'pdf.no_lang', 'pdf.title_not_displayed'],
        'notes' => 'Tests the "and" in the rule: the title check fails only when both /Info /Title and XMP dc:title are missing.',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_title_not_displayed(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $tagged = pdf_tagged_pages($pdf, $pagesNum, $font, $structRoot, 1);
    $pdf->obj("<< /Type /Pages /Kids [ {$tagged['pages'][0]} 0 R ] /Count 1 >>", $pagesNum);
    $pdf->obj("<< /Type /StructTreeRoot /K [ {$tagged['doc_elem']} 0 R ] /ParentTree {$tagged['parent_tree']} 0 R /ParentTreeNextKey {$tagged['next_key']} /RoleMap << >> >>", $structRoot);

    $info = $pdf->reserve();
    $pdf->obj('<< /Title '.$pdf->str('Policy On Something Important', $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang (en-US)"
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R >>",
        $catalog
    );

    return [
        'path' => 'pdf/title-not-displayed.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'Has a title, but no /ViewerPreferences at all, so DisplayDocTitle is absent.',
        'expect' => [['rule' => 'pdf.title_not_displayed', 'severity' => 'serious', 'count' => 1]],
        'expect_absent' => ['pdf.no_title', 'pdf.not_tagged', 'pdf.no_lang'],
        'notes' => 'A missing /ViewerPreferences dictionary and a present one with DisplayDocTitle false must both fail. This fixture covers the missing-dictionary case, which is by far the common one.',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_no_lang(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $tagged = pdf_tagged_pages($pdf, $pagesNum, $font, $structRoot, 1);
    $pdf->obj("<< /Type /Pages /Kids [ {$tagged['pages'][0]} 0 R ] /Count 1 >>", $pagesNum);
    $pdf->obj("<< /Type /StructTreeRoot /K [ {$tagged['doc_elem']} 0 R ] /ParentTree {$tagged['parent_tree']} 0 R /ParentTreeNextKey {$tagged['next_key']} /RoleMap << >> >>", $structRoot);

    $info = $pdf->reserve();
    $pdf->obj('<< /Title '.$pdf->str('Document With No Language', $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R"
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R"
        .' /ViewerPreferences << /DisplayDocTitle true >> >>',
        $catalog
    );

    return [
        'path' => 'pdf/no-lang.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'No /Lang on the catalog. Tagged and titled otherwise.',
        'expect' => [['rule' => 'pdf.no_lang', 'severity' => 'serious', 'count' => 1]],
        'expect_absent' => ['pdf.not_tagged', 'pdf.no_title', 'pdf.title_not_displayed'],
        'notes' => null,
        'bytes' => $pdf->build($catalog, $info),
    ];
}

/** A page-sized greyscale bitmap with dark bands, so it looks like a scan of text. */
function pdf_scan_image(): string
{
    $width = 600;
    $height = 800;
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $row = '';
        for ($x = 0; $x < $width; $x++) {
            $ink = $x > 60 && $x < 540 && $y > 80 && $y < 700 && ($y % 40) < 12;
            $row .= chr($ink ? 40 : 235);
        }
        $raw .= $row;
    }

    return gzcompress($raw, 9);
}

function pdf_image_only_scan(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();

    $image = $pdf->stream(
        '/Type /XObject /Subtype /Image /Width 600 /Height 800 /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode',
        pdf_scan_image()
    );

    $pages = [];
    for ($i = 1; $i <= 2; $i++) {
        $content = $pdf->stream('', "q 540 0 0 720 36 36 cm /Im1 Do Q\n");
        $pages[] = $pdf->obj(
            "<< /Type /Page /Parent $pagesNum 0 R /MediaBox [0 0 612 792]"
            ." /Resources << /XObject << /Im1 $image 0 R >> >> /Contents $content 0 R >>"
        );
    }
    $kids = implode(' ', array_map(fn ($n) => "$n 0 R", $pages));
    $pdf->obj("<< /Type /Pages /Kids [ $kids ] /Count 2 >>", $pagesNum);
    $pdf->obj("<< /Type /Catalog /Pages $pagesNum 0 R >>", $catalog);

    return [
        'path' => 'pdf/image-only-scan.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'Two pages, each a single full-page greyscale bitmap. No fonts, no text operators, no metadata — a flatbed scan.',
        'expect' => [
            ['rule' => 'pdf.image_only', 'severity' => 'critical', 'count' => 1],
            ['rule' => 'pdf.not_tagged', 'severity' => 'critical', 'count' => 1],
            ['rule' => 'pdf.no_title', 'severity' => 'serious', 'count' => 1],
            ['rule' => 'pdf.title_not_displayed', 'severity' => 'serious', 'count' => 1],
            ['rule' => 'pdf.no_lang', 'severity' => 'serious', 'count' => 1],
        ],
        'expect_absent' => ['pdf.no_tounicode'],
        'notes' => 'Deliberately fails five rules at once, because that is what a real scan does. There is no /Font resource anywhere, so a font-walking rule must cope with its absence rather than error.',
        'bytes' => $pdf->build($catalog),
    ];
}

function pdf_encrypted(int $permissions, string $title): string
{
    $pdf = new PdfBuilder;
    $encryptObj = $pdf->reserve();
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $encryption = $pdf->encrypt($permissions);
    $pdf->obj($pdf->encryptDict($encryption), $encryptObj);

    $tagged = pdf_tagged_pages($pdf, $pagesNum, $font, $structRoot, 1);
    $pdf->obj("<< /Type /Pages /Kids [ {$tagged['pages'][0]} 0 R ] /Count 1 >>", $pagesNum);
    $pdf->obj("<< /Type /StructTreeRoot /K [ {$tagged['doc_elem']} 0 R ] /ParentTree {$tagged['parent_tree']} 0 R /ParentTreeNextKey {$tagged['next_key']} /RoleMap << >> >>", $structRoot);

    $info = $pdf->reserve();
    $pdf->obj('<< /Title '.$pdf->str($title, $info).' >>', $info);

    // Every string in an encrypted document is encrypted, /Lang included. A
    // literal (en-US) here would be readable by a parser that ignores
    // encryption and garbage to one that honours it — a fixture that behaves
    // differently depending on who reads it is not a fixture.
    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang ".$pdf->str('en-US', $catalog)
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R"
        .' /ViewerPreferences << /DisplayDocTitle true >> >>',
        $catalog
    );

    return $pdf->build($catalog, $info, $encryptObj);
}

function pdf_encrypted_no_extract(): array
{
    // -1 with bit 10 (accessibility extraction, 512) and bit 5 (copy, 16) cleared.
    $permissions = -1 & ~512 & ~16;

    return [
        'path' => 'pdf/encrypted-no-extract.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'RC4 128-bit, revision 3, empty user password, /P '.$permissions.' — permission bit 10 cleared.',
        'expect' => [['rule' => 'pdf.extraction_blocked', 'severity' => 'critical', 'count' => 1]],
        'expect_absent' => ['pdf.not_tagged', 'pdf.no_title', 'pdf.no_lang', 'pdf.title_not_displayed'],
        'notes' => 'Opens without a password, so a reader that handles empty-user-password decryption still sees the tags. Revision 3 on purpose: revision 2 leaves bit 10 undefined, so clearing it there would mean nothing. Every string is encrypted, /Lang included, so a heuristics-only reader sees the key is present but cannot read its value — presence is what the language rule asks about, which is why it still holds here.',
        'bytes' => pdf_encrypted($permissions, 'Locked Down Report'),
    ];
}

function pdf_encrypted_accessible(): array
{
    // -1 with bit 3 (print, 4) cleared. Bit 10 stays set.
    $permissions = -1 & ~4;

    return [
        'path' => 'pdf/encrypted-accessible.pdf',
        'format' => 'pdf',
        'status' => 'pass',
        'summary' => 'Same encryption as encrypted-no-extract.pdf, but only printing is restricted: /P '.$permissions.'.',
        'expect' => [],
        'expect_absent' => ['pdf.extraction_blocked', 'pdf.not_tagged', 'pdf.no_title', 'pdf.no_lang', 'pdf.title_not_displayed'],
        'notes' => 'The negative control for the encryption rule. "Encrypted" must never on its own mean "inaccessible" — only bit 10 does. Title and language are unreadable here without decryption, so the rules that read string values have to report that they could not check rather than that the document failed.',
        'bytes' => pdf_encrypted($permissions, 'Print Restricted Report'),
    ];
}

function pdf_long(bool $withOutlines): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $tagged = pdf_tagged_pages($pdf, $pagesNum, $font, $structRoot, 25);
    $kids = implode(' ', array_map(fn ($n) => "$n 0 R", $tagged['pages']));
    $pdf->obj("<< /Type /Pages /Kids [ $kids ] /Count 25 >>", $pagesNum);
    $pdf->obj("<< /Type /StructTreeRoot /K [ {$tagged['doc_elem']} 0 R ] /ParentTree {$tagged['parent_tree']} 0 R /ParentTreeNextKey {$tagged['next_key']} /RoleMap << >> >>", $structRoot);

    $outlinesEntry = '';
    if ($withOutlines) {
        $outlines = $pdf->reserve();
        $first = $pdf->reserve();
        $second = $pdf->reserve();
        $third = $pdf->reserve();
        $pdf->obj("<< /Type /Outlines /First $first 0 R /Last $third 0 R /Count 3 >>", $outlines);
        $pdf->obj('<< /Title '.$pdf->str('Introduction', $first)." /Parent $outlines 0 R /Next $second 0 R /Dest [ {$tagged['pages'][0]} 0 R /Fit ] >>", $first);
        $pdf->obj('<< /Title '.$pdf->str('Findings', $second)." /Parent $outlines 0 R /Prev $first 0 R /Next $third 0 R /Dest [ {$tagged['pages'][9]} 0 R /Fit ] >>", $second);
        $pdf->obj('<< /Title '.$pdf->str('Appendix', $third)." /Parent $outlines 0 R /Prev $second 0 R /Dest [ {$tagged['pages'][19]} 0 R /Fit ] >>", $third);
        $outlinesEntry = " /Outlines $outlines 0 R /PageMode /UseOutlines";
    }

    $info = $pdf->reserve();
    $title = $withOutlines ? 'Long Report With Bookmarks' : 'Long Report Without Bookmarks';
    $pdf->obj('<< /Title '.$pdf->str($title, $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang (en-US)"
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R"
        .' /ViewerPreferences << /DisplayDocTitle true >>'
        .$outlinesEntry.' >>',
        $catalog
    );

    return [$pdf, $catalog, $info];
}

function pdf_long_no_bookmarks(): array
{
    [$pdf, $catalog, $info] = pdf_long(false);

    return [
        'path' => 'pdf/long-no-bookmarks.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => '25 tagged pages, correct metadata, no /Outlines.',
        'expect' => [['rule' => 'pdf.no_bookmarks', 'severity' => 'moderate', 'count' => 1]],
        'expect_absent' => ['pdf.not_tagged', 'pdf.no_title', 'pdf.no_lang', 'pdf.title_not_displayed'],
        'notes' => 'Page count is 25, just over the threshold of 20. Pair with untagged.pdf (2 pages, no outlines, no finding) to pin the boundary.',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_long_with_bookmarks(): array
{
    [$pdf, $catalog, $info] = pdf_long(true);

    return [
        'path' => 'pdf/long-with-bookmarks.pdf',
        'format' => 'pdf',
        'status' => 'pass',
        'summary' => 'The same 25-page document with a three-entry /Outlines tree.',
        'expect' => [],
        'expect_absent' => ['pdf.no_bookmarks', 'pdf.not_tagged', 'pdf.no_title', 'pdf.no_lang', 'pdf.title_not_displayed'],
        'notes' => 'The negative control for the bookmark rule; catches an implementation that only checks page count.',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_form_unlabelled_fields(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $tagged = pdf_tagged_pages($pdf, $pagesNum, $font, $structRoot, 1);
    $page = $tagged['pages'][0];

    $labelled = $pdf->obj(
        '<< /Type /Annot /Subtype /Widget /FT /Tx /T (fullName) /TU (Your full name)'
        ." /Rect [ 72 640 400 664 ] /P $page 0 R /F 4 /DA (/Helv 0 Tf 0 g) >>"
    );
    $unlabelledOne = $pdf->obj(
        '<< /Type /Annot /Subtype /Widget /FT /Tx /T (field2)'
        ." /Rect [ 72 600 400 624 ] /P $page 0 R /F 4 /DA (/Helv 0 Tf 0 g) >>"
    );
    $unlabelledTwo = $pdf->obj(
        '<< /Type /Annot /Subtype /Widget /FT /Ch /T (field3)'
        ." /Rect [ 72 560 400 584 ] /P $page 0 R /F 4 /DA (/Helv 0 Tf 0 g) /Opt [ (Yes) (No) ] >>"
    );

    // Re-emit the tagged page with the annotation list attached.
    $content = $pdf->stream('', "/P <</MCID 0>> BDC\nBT /F1 14 Tf 72 720 Td (Application form. Please complete all fields.) Tj ET\nEMC\n");
    $pdf->obj(
        "<< /Type /Page /Parent $pagesNum 0 R /MediaBox [0 0 612 792]"
        ." /Resources << /Font << /F1 $font 0 R /Helv $font 0 R >> >>"
        ." /Contents $content 0 R /StructParents 0"
        ." /Annots [ $labelled 0 R $unlabelledOne 0 R $unlabelledTwo 0 R ] >>",
        $page
    );

    $pdf->obj("<< /Type /Pages /Kids [ $page 0 R ] /Count 1 >>", $pagesNum);
    $pdf->obj("<< /Type /StructTreeRoot /K [ {$tagged['doc_elem']} 0 R ] /ParentTree {$tagged['parent_tree']} 0 R /ParentTreeNextKey {$tagged['next_key']} /RoleMap << >> >>", $structRoot);

    $acroForm = $pdf->obj(
        "<< /Fields [ $labelled 0 R $unlabelledOne 0 R $unlabelledTwo 0 R ]"
        ." /DA (/Helv 0 Tf 0 g) /DR << /Font << /Helv $font 0 R >> >> /NeedAppearances true >>"
    );

    $info = $pdf->reserve();
    $pdf->obj('<< /Title '.$pdf->str('Application Form', $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang (en-US)"
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R"
        ." /AcroForm $acroForm 0 R"
        .' /ViewerPreferences << /DisplayDocTitle true >> >>',
        $catalog
    );

    return [
        'path' => 'pdf/form-unlabelled-fields.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'Three form widgets. The first has /TU; the other two do not.',
        'expect' => [['rule' => 'pdf.unlabelled_form_fields', 'severity' => 'serious', 'count' => 2]],
        'expect_absent' => ['pdf.not_tagged', 'pdf.no_title', 'pdf.no_lang', 'pdf.title_not_displayed'],
        'notes' => 'The count matters: one finding per unlabelled field, not one per document, and the labelled field must not be reported. /T is a field name, not a label — a rule that accepts /T as a description will wrongly pass all three.',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_figure_missing_alt(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();
    $page = $pdf->reserve();
    $docElem = $pdf->reserve();
    $font = $pdf->obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');

    $heading = $pdf->obj("<< /Type /StructElem /S /H1 /P $docElem 0 R /Pg $page 0 R /K 0 >>");
    $describedFigure = $pdf->obj(
        "<< /Type /StructElem /S /Figure /P $docElem 0 R /Pg $page 0 R /K 1"
        .' /Alt (Bar chart: enrolment rose from 12,400 in 2024 to 14,900 in 2026.) >>'
    );
    $bareFigure = $pdf->obj("<< /Type /StructElem /S /Figure /P $docElem 0 R /Pg $page 0 R /K 2 >>");

    $pdf->obj("<< /Type /StructElem /S /Document /P $structRoot 0 R /K [ $heading 0 R $describedFigure 0 R $bareFigure 0 R ] >>", $docElem);
    $parentTree = $pdf->obj("<< /Nums [ 0 [ $heading 0 R $describedFigure 0 R $bareFigure 0 R ] ] >>");

    $content = $pdf->stream('', implode("\n", [
        '/H1 <</MCID 0>> BDC',
        'BT /F1 20 Tf 72 720 Td (Enrolment) Tj ET',
        'EMC',
        '/Figure <</MCID 1>> BDC',
        '0.2 0.4 0.8 rg 72 520 200 140 re f',
        'EMC',
        '/Figure <</MCID 2>> BDC',
        '0.8 0.3 0.2 rg 320 520 200 140 re f',
        'EMC',
        '',
    ]));

    $pdf->obj(
        "<< /Type /Page /Parent $pagesNum 0 R /MediaBox [0 0 612 792]"
        ." /Resources << /Font << /F1 $font 0 R >> >> /Contents $content 0 R /StructParents 0 >>",
        $page
    );
    $pdf->obj("<< /Type /Pages /Kids [ $page 0 R ] /Count 1 >>", $pagesNum);
    $pdf->obj("<< /Type /StructTreeRoot /K [ $docElem 0 R ] /ParentTree $parentTree 0 R /ParentTreeNextKey 1 /RoleMap << >> >>", $structRoot);

    $info = $pdf->reserve();
    $pdf->obj('<< /Title '.$pdf->str('Enrolment Figures', $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang (en-US)"
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R"
        .' /ViewerPreferences << /DisplayDocTitle true >> >>',
        $catalog
    );

    return [
        'path' => 'pdf/figure-missing-alt.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'Two /Figure structure elements. One carries /Alt; the other carries neither /Alt nor /ActualText.',
        'expect' => [['rule' => 'pdf.figure_missing_alt', 'severity' => 'serious', 'count' => 1]],
        'expect_absent' => ['pdf.not_tagged', 'pdf.no_title', 'pdf.no_lang', 'pdf.title_not_displayed'],
        'notes' => 'Requires walking the structure tree, not just reading the catalog. Exactly one finding: the described figure must not be reported.',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_no_tounicode(): array
{
    $pdf = new PdfBuilder;
    $catalog = $pdf->reserve();
    $pagesNum = $pdf->reserve();
    $structRoot = $pdf->reserve();

    $descriptor = $pdf->obj(
        '<< /Type /FontDescriptor /FontName /AAAAAA+SubsetSans /Flags 4 /FontBBox [ -200 -250 1200 950 ]'
        .' /ItalicAngle 0 /Ascent 950 /Descent -250 /CapHeight 700 /StemV 80 >>'
    );
    $cidFont = $pdf->obj(
        '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /AAAAAA+SubsetSans'
        .' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
        ." /FontDescriptor $descriptor 0 R /DW 1000 >>"
    );
    $font = $pdf->obj(
        '<< /Type /Font /Subtype /Type0 /BaseFont /AAAAAA+SubsetSans /Encoding /Identity-H'
        ." /DescendantFonts [ $cidFont 0 R ] >>"
    );

    $tagged = pdf_tagged_pages($pdf, $pagesNum, $font, $structRoot, 1);
    $page = $tagged['pages'][0];
    $content = $pdf->stream('', "/P <</MCID 0>> BDC\nBT /F1 14 Tf 72 720 Td <002400480051004800550044> Tj ET\nEMC\n");
    $pdf->obj(
        "<< /Type /Page /Parent $pagesNum 0 R /MediaBox [0 0 612 792]"
        ." /Resources << /Font << /F1 $font 0 R >> >> /Contents $content 0 R /StructParents 0 >>",
        $page
    );

    $pdf->obj("<< /Type /Pages /Kids [ $page 0 R ] /Count 1 >>", $pagesNum);
    $pdf->obj("<< /Type /StructTreeRoot /K [ {$tagged['doc_elem']} 0 R ] /ParentTree {$tagged['parent_tree']} 0 R /ParentTreeNextKey {$tagged['next_key']} /RoleMap << >> >>", $structRoot);

    $info = $pdf->reserve();
    $pdf->obj('<< /Title '.$pdf->str('Subset Font Without ToUnicode', $info).' >>', $info);

    $pdf->obj(
        "<< /Type /Catalog /Pages $pagesNum 0 R /Lang (en-US)"
        .' /MarkInfo << /Marked true >>'
        ." /StructTreeRoot $structRoot 0 R"
        .' /ViewerPreferences << /DisplayDocTitle true >> >>',
        $catalog
    );

    return [
        'path' => 'pdf/no-tounicode.pdf',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'A Type0/Identity-H subset font with no /ToUnicode CMap. The page shows text, but the glyph codes map to nothing.',
        'expect' => [['rule' => 'pdf.no_tounicode', 'severity' => 'moderate', 'count' => 1]],
        'expect_absent' => ['pdf.image_only', 'pdf.not_tagged', 'pdf.no_title', 'pdf.no_lang', 'pdf.title_not_displayed'],
        'notes' => 'A deliberate near-miss for pdf.image_only: text extraction yields nothing useful here, but there are no images, so the image-only rule must not fire. The font programme itself is not embedded — the fixture exercises the resource dictionary, not rendering.',
        'bytes' => $pdf->build($catalog, $info),
    ];
}

function pdf_corrupt_truncated(): array
{
    $body = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n"
        ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>\nendobj\n"
        ."4 0 obj\n<< /Length 240 >>\nstream\nBT /F1 12 Tf 72 720 Td (This file stops mid-";

    return [
        'path' => 'pdf/corrupt-truncated.pdf',
        'format' => 'pdf',
        'status' => 'error',
        'summary' => 'Truncated mid-stream: no xref table, no trailer, no %%EOF, and a /Length that overruns the file.',
        'expect' => [],
        'expect_absent' => [],
        'notes' => 'Must be recorded as status=error with the parser message preserved, never as a pass and never as an uncaught exception that fails the whole batch.',
        'bytes' => $body,
    ];
}
