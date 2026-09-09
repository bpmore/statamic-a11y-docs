<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Ooxml\OoxmlException;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlPackage;
use Bpmore\DocumentA11yCore\Ooxml\Relationship;

/** Build a package in a temp file, for shapes no fixture has. */
function temporaryPackage(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'ooxml').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return $path;
}

it('opens every Office fixture in the corpus', function (array $fixture) {
    $package = OoxmlPackage::open(corpusPath($fixture['path']));

    expect($package->has('[Content_Types].xml'))->toBeTrue()
        ->and($package->mainPart())->not->toBeNull()
        ->and($package->parts())->not->toBeEmpty();
})->with(array_map(
    static fn (array $fixture): array => [$fixture],
    array_filter(
        corpusFixtures(),
        static fn (array $fixture): bool => in_array($fixture['format'], ['docx', 'pptx', 'xlsx'], true),
    ),
));

it('finds the main part by asking the package rather than assuming the name', function (string $path, string $expected) {
    // The conventional names are a convention. The package says which part is
    // the document, and that is the same question in all three formats — which
    // is the whole reason one reader can serve them.
    expect(OoxmlPackage::open(corpusPath($path))->mainPart())->toBe($expected);
})->with([
    ['docx/good.docx', 'word/document.xml'],
    ['pptx/good.pptx', 'ppt/presentation.xml'],
    ['xlsx/good.xlsx', 'xl/workbook.xml'],
]);

it('resolves a relationship target against the part that owns it', function () {
    // ppt/slides/_rels/slide1.xml.rels points at "../slideLayouts/slideLayout1.xml",
    // which resolves against ppt/slides and not against the package root.
    $package = OoxmlPackage::open(corpusPath('pptx/good.pptx'));

    $layout = $package->relationshipsOfType('slideLayout', 'ppt/slides/slide1.xml')[0];

    expect($layout->target)->toBe('ppt/slideLayouts/slideLayout1.xml')
        ->and($package->has($layout->target))->toBeTrue();
});

it('resolves relationships two directories deep', function () {
    // xl/drawings/_rels/drawing1.xml.rels -> ../media/image1.png
    $package = OoxmlPackage::open(corpusPath('xlsx/drawing-missing-alt.xlsx'));

    $image = $package->relationshipsOfType('image', 'xl/drawings/drawing1.xml')[0];

    expect($image->target)->toBe('xl/media/image1.png')
        ->and($package->has($image->target))->toBeTrue();
});

it('keeps external targets out of the package', function () {
    // A hyperlink points at the web. Resolving it as a part name would produce
    // a part that cannot exist and a rule that never fires.
    $package = OoxmlPackage::open(corpusPath('docx/link-text-not-meaningful.docx'));

    $links = $package->relationshipsOfType('hyperlink', 'word/document.xml');

    expect($links)->toHaveCount(4)
        ->and($links[0]->external)->toBeTrue()
        ->and($links[0]->target)->toStartWith('https://');
});

it('looks a relationship up by the id an element refers to', function () {
    $package = OoxmlPackage::open(corpusPath('docx/good.docx'));

    expect($package->relationship('rId3', 'word/document.xml')?->target)->toBe('word/media/image1.png')
        ->and($package->relationship('rId3', 'word/document.xml')?->is('image'))->toBeTrue()
        ->and($package->relationship('nope', 'word/document.xml'))->toBeNull();
});

it('reads content types from overrides and from extension defaults', function () {
    $package = OoxmlPackage::open(corpusPath('docx/good.docx'));

    expect($package->contentType('word/document.xml'))
        ->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml')
        ->and($package->contentType('word/media/image1.png'))->toBe('image/png')
        ->and($package->contentType('nothing/here.xml'))->toBe('application/xml');
});

it('finds every part of a content type', function () {
    $package = OoxmlPackage::open(corpusPath('pptx/duplicate-slide-titles.pptx'));

    expect($package->partsOfContentType(
        'application/vnd.openxmlformats-officedocument.presentationml.slide+xml'
    ))->toHaveCount(4);
});

it('queries with namespace prefixes of its own, not the document\'s', function () {
    // Word and LibreOffice do not always choose the same prefixes for the same
    // namespaces, so a rule that matched on prefixes would work on some files
    // and not others.
    $package = OoxmlPackage::open(corpusPath('docx/image-missing-alt.docx'));
    $document = $package->requireXml('word/document.xml');

    expect($document->count('//w:drawing//wp:docPr'))->toBe(3)
        ->and($document->count('//w:p'))->toBeGreaterThan(3);
});

it('tells an absent attribute from an empty one', function () {
    // The whole of the alt-text rules. descr="" and no descr at all are
    // different in the XML and identical to somebody using a screen reader.
    $document = OoxmlPackage::open(corpusPath('docx/image-missing-alt.docx'))
        ->requireXml('word/document.xml');

    $described = $document->elements('//wp:docPr');

    expect($document->attribute($described[0], 'descr'))->toStartWith('The main quad')
        ->and($document->attribute($described[1], 'descr'))->toBe('')
        ->and($document->attribute($described[2], 'descr'))->toBeNull();
});

it('reads namespaced attributes by their namespace', function () {
    $package = OoxmlPackage::open(corpusPath('docx/good.docx'));
    $document = $package->requireXml('word/document.xml');
    $blip = $document->first('//a:blip');

    expect($document->attribute($blip, 'embed', 'r'))->toBe('rId3')
        // Same local name without the namespace is a different attribute.
        ->and($document->attribute($blip, 'embed'))->toBeNull();
});

it('reads the title and language every format keeps in the same place', function () {
    expect(OoxmlPackage::open(corpusPath('docx/good.docx'))->title())->toBe('Accessibility Policy 2026')
        ->and(OoxmlPackage::open(corpusPath('pptx/good.pptx'))->title())->toBe('Accessibility Review 2026')
        ->and(OoxmlPackage::open(corpusPath('xlsx/good.xlsx'))->title())->toBe('Enrolment and Estates')
        ->and(OoxmlPackage::open(corpusPath('docx/good.docx'))->language())->toBe('en-US');
});

it('reports a missing title as missing rather than as empty', function () {
    $package = OoxmlPackage::open(corpusPath('docx/no-title-no-lang.docx'));

    expect($package->title())->toBeNull()
        ->and($package->language())->toBeNull()
        ->and($package->coreProperties())->not->toBeNull();
});

it('refuses a file that is not a package', function () {
    expect(fn () => OoxmlPackage::open(corpusPath('pdf/untagged.pdf')))
        ->toThrow(OoxmlException::class, 'not a readable ZIP')
        ->and(fn () => OoxmlPackage::open('/no/such/file.docx'))
        ->toThrow(OoxmlException::class, 'could not be opened');
});

it('refuses a ZIP that is not an OOXML document', function () {
    // A ZIP with no [Content_Types].xml is some other kind of archive, and
    // reading parts out of it would produce silence rather than an answer.
    $path = temporaryPackage(['readme.txt' => 'just a zip']);

    try {
        expect(fn () => OoxmlPackage::open($path))->toThrow(OoxmlException::class, 'not an OOXML document');
    } finally {
        @unlink($path);
    }
});

it('says which part is malformed rather than failing silently', function () {
    $path = temporaryPackage([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'word/document.xml' => '<w:document><w:body>',
    ]);

    try {
        expect(fn () => OoxmlPackage::open($path)->xml('word/document.xml'))
            ->toThrow(OoxmlException::class, "The part 'word/document.xml' is not well-formed XML");
    } finally {
        @unlink($path);
    }
});

it('returns null for a part that is not there, and throws when one is required', function () {
    $package = OoxmlPackage::open(corpusPath('docx/good.docx'));

    expect($package->xml('word/footnotes.xml'))->toBeNull()
        ->and($package->has('word/footnotes.xml'))->toBeFalse()
        ->and(fn () => $package->requireXml('word/footnotes.xml'))->toThrow(OoxmlException::class)
        ->and($package->relationships('word/footnotes.xml'))->toBe([]);
});

it('refuses a part that would expand out of all proportion to the file', function () {
    // A ZIP can be made to expand to gigabytes from a few kilobytes, and a
    // queue worker that reads one of those into a string does not come back.
    $path = temporaryPackage([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'word/document.xml' => str_repeat('a', 200_000),
    ]);

    try {
        $package = OoxmlPackage::open($path, maxPartBytes: 1_024);

        expect(fn () => $package->contents('word/document.xml'))
            ->toThrow(OoxmlException::class, 'over the 1 KB limit for a single part');
    } finally {
        @unlink($path);
    }
});

it('parses a part once however often it is asked for', function () {
    // Three rule sets querying the same part should not reparse it three times.
    $package = OoxmlPackage::open(corpusPath('docx/good.docx'));

    expect($package->xml('word/document.xml'))->toBe($package->xml('word/document.xml'));
});

it('says so when asked to read from a package that has been closed', function () {
    $package = OoxmlPackage::open(corpusPath('docx/good.docx'));
    $package->close();

    expect(fn () => $package->contents('word/document.xml'))
        ->toThrow(OoxmlException::class, 'has been closed');
});

it('works the same way whichever of the three formats it is given', function (string $path, string $bodyExpression) {
    // The claim spec §3 makes: one reader, three rule sets. If any of these
    // needed a different call, the abstraction would not be paying for itself.
    $package = OoxmlPackage::open(corpusPath($path));
    $main = $package->requireXml($package->mainPart());

    expect($package->title())->not->toBeNull()
        ->and($package->relationships($package->mainPart()))->not->toBeEmpty()
        ->and($package->contentType($package->mainPart()))->toContain('openxmlformats')
        ->and($main->exists($bodyExpression))->toBeTrue();
})->with([
    ['docx/good.docx', '/w:document/w:body'],
    ['pptx/good.pptx', '/p:presentation/p:sldIdLst'],
    ['xlsx/good.xlsx', '/x:workbook/x:sheets'],
]);

it('names relationships by their short type on either base URI', function () {
    expect((new Relationship('rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', 'x'))->shortType())
        ->toBe('image')
        ->and((new Relationship('rId2', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'x'))->shortType())
        ->toBe('core-properties');
});

it('reads a package this project did not generate', function () {
    // Everything in the document corpus was written by tools/build-fixtures.php,
    // so none of it can surprise the reader. This one came out of macOS's
    // textutil: it declares namespaces we do not (VML, urn:schemas-microsoft-com)
    // and binds markup-compatibility to "ve" rather than "mc". Anything matching
    // on prefixes would find nothing in it.
    $package = OoxmlPackage::open(dirname(__DIR__).'/fixtures/ooxml/textutil.docx');
    $document = $package->requireXml($package->mainPart());

    expect($package->mainPart())->toBe('word/document.xml')
        ->and($package->contentType('word/document.xml'))->toContain('wordprocessingml')
        ->and($document->count('//w:p'))->toBe(3)
        ->and($document->text('//w:p[1]'))->toBe('Accessibility Policy')
        ->and(array_map(
            static fn (Relationship $r): string => $r->shortType(),
            $package->relationships('word/document.xml'),
        ))->toBe(['customXml', 'theme'])
        // No dc:title, which is what a document produced from a plain text file
        // honestly has — and the reader reports it as absent rather than empty.
        ->and($package->title())->toBeNull();
});
