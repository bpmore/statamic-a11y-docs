<?php

declare(strict_types=1);
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Header;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;

/**
 * Spike: how much of the PDF heuristics table can smalot/pdfparser actually
 * answer? (Phase 1, task 2.)
 *
 *     composer require smalot/pdfparser        # somewhere with a vendor/ dir
 *     php tools/spike-pdfparser.php --autoload=/path/to/vendor/autoload.php [extra.pdf ...]
 *
 * This is evidence, not the inspector. The probes below are the smallest thing
 * that answers "can the library reach this value at all"; the real rules get
 * written in document-a11y-core against the same fixtures, with locations,
 * severities and error handling this script does not attempt.
 *
 * Part 1 runs every probe over the fixture corpus and scores it against the
 * expectations in manifest.json. Part 2 parses whatever real-world PDFs are
 * passed on the command line and reports cost and failures.
 */
$autoload = null;
$extra = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--autoload=')) {
        $autoload = substr($argument, 11);

        continue;
    }
    $extra[] = $argument;
}

$autoload ??= dirname(__DIR__).'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "No autoloader at $autoload. Pass --autoload=/path/to/vendor/autoload.php\n");
    exit(1);
}
require $autoload;

if (! class_exists(Parser::class)) {
    fwrite(STDERR, "smalot/pdfparser is not installed in that autoloader.\n");
    exit(1);
}

const STANDARD_14 = [
    'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
    'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique',
    'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
    'Symbol', 'ZapfDingbats',
];

function parser(): Parser
{
    $config = new Config;
    // Without this, every encrypted file throws before the trailer is read,
    // and /P — the only place the accessibility permission lives — is
    // unreachable. With it, dictionaries parse; strings and streams do not.
    $config->setIgnoreEncryption(true);

    return new Parser([], $config);
}

function content(?object $element): mixed
{
    if ($element === null) {
        return null;
    }

    return method_exists($element, 'getContent') ? $element->getContent() : null;
}

function catalogOf(Document $document): ?PDFObject
{
    foreach ($document->getObjectsByType('Catalog') as $catalog) {
        return $catalog;
    }

    return null;
}

function nested(?PDFObject $object, string $dictionary, string $key): mixed
{
    if ($object === null || ! $object->getHeader()->has($dictionary)) {
        return null;
    }
    $inner = $object->getHeader()->get($dictionary);
    if ($inner instanceof Header) {
        return $inner->has($key) ? content($inner->get($key)) : null;
    }
    if ($inner instanceof PDFObject) {
        return $inner->getHeader()->has($key) ? content($inner->getHeader()->get($key)) : null;
    }

    return null;
}

/**
 * Run every probe. Each returns true (rule fires), false (rule does not fire)
 * or null (the library cannot answer this at all).
 *
 * @return array<string, bool|null>
 */
function probe(Document $document): array
{
    $catalog = catalogOf($document);
    $header = $catalog?->getHeader();
    $details = $document->getDetails();
    $trailer = $document->getTrailer();
    $encrypted = $trailer->has('Encrypt');

    $findings = [];

    // Not tagged: no /StructTreeRoot and no /MarkInfo << /Marked true >>.
    $findings['pdf.not_tagged'] = $header === null
        ? null
        : ! ($header->has('StructTreeRoot') || nested($catalog, 'MarkInfo', 'Marked') === true);

    // No title: /Info /Title empty AND no XMP dc:title. Strings are encrypted,
    // so on an encrypted file this reads noise rather than nothing.
    // getDetails() values are not reliably scalar: a real-world file turned up
    // an array under Title, which is a silent "Array to string conversion".
    $scalar = fn (mixed $value): string => is_array($value)
        ? trim(implode(' ', array_filter($value, 'is_scalar')))
        : trim((string) $value);
    $title = $scalar($details['Title'] ?? '');
    $xmpTitle = $scalar($details['dc:title'] ?? '');
    $findings['pdf.no_title'] = $encrypted ? null : ($title === '' && $xmpTitle === '');

    // Title not displayed: /ViewerPreferences << /DisplayDocTitle true >>.
    $findings['pdf.title_not_displayed'] = $header === null
        ? null
        : nested($catalog, 'ViewerPreferences', 'DisplayDocTitle') !== true;

    // No language: /Lang on the catalog. Also a string, so also unreadable
    // under encryption — but presence is what the rule asks about.
    $findings['pdf.no_lang'] = $header === null
        ? null
        : ! ($header->has('Lang') && trim((string) content($header->get('Lang'))) !== '');

    // Image-only: no extractable text while pages carry images.
    $images = 0;
    foreach ($document->getObjects() as $object) {
        if (content($object->getHeader()->get('Subtype')) === 'Image') {
            $images++;
        }
    }
    $findings['pdf.image_only'] = $encrypted
        ? null
        : (strlen(trim($document->getText())) < 10 && $images > 0);

    // Accessibility extraction blocked: permission bit 10 (value 512).
    $findings['pdf.extraction_blocked'] = false;
    if ($encrypted) {
        $encrypt = $trailer->get('Encrypt');
        $permissions = $encrypt instanceof PDFObject
            ? content($encrypt->getHeader()->get('P'))
            : null;
        // /P arrives as a float, so it has to be cast before any bitwise test.
        $findings['pdf.extraction_blocked'] = is_numeric($permissions)
            ? ((int) $permissions & 512) === 0
            : null;
    }

    // No bookmarks in a long document.
    $pages = count($document->getPages());
    $findings['pdf.no_bookmarks'] = $header === null
        ? null
        : ($pages > 20 && ! $header->has('Outlines'));

    // Unlabelled form fields: widget annotations without /TU.
    $unlabelled = 0;
    foreach ($document->getObjectsByType('Annot') as $annotation) {
        if (content($annotation->getHeader()->get('Subtype')) !== 'Widget') {
            continue;
        }
        if (! $annotation->getHeader()->has('TU')) {
            $unlabelled++;
        }
    }
    $findings['pdf.unlabelled_form_fields'] = $unlabelled > 0 ? $unlabelled : false;

    // Figures without /Alt or /ActualText.
    $bareFigures = 0;
    foreach ($document->getObjectsByType('StructElem') as $element) {
        if (content($element->getHeader()->get('S')) !== 'Figure') {
            continue;
        }
        if (! $element->getHeader()->has('Alt') && ! $element->getHeader()->has('ActualText')) {
            $bareFigures++;
        }
    }
    $findings['pdf.figure_missing_alt'] = $bareFigures > 0 ? $bareFigures : false;

    // Fonts without /ToUnicode, excluding the standard 14 and the CID
    // descendants that inherit their parent's CMap.
    $untranslatable = 0;
    foreach ($document->getFonts() as $font) {
        $subtype = content($font->getHeader()->get('Subtype'));
        if (in_array($subtype, ['CIDFontType0', 'CIDFontType2'], true)) {
            continue;
        }
        $base = (string) content($font->getHeader()->get('BaseFont'));
        if (in_array(ltrim($base, '/'), STANDARD_14, true)) {
            continue;
        }
        if (! $font->getHeader()->has('ToUnicode')) {
            $untranslatable++;
        }
    }
    $findings['pdf.no_tounicode'] = $untranslatable > 0 ? $untranslatable : false;

    return $findings;
}

// ---------------------------------------------------------------- part one ---

$root = dirname(__DIR__).'/tests/fixtures/documents';
$manifest = json_decode((string) file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$agree = $disagree = $unanswerable = 0;
$disagreements = [];

echo 'smalot/pdfparser '.installedVersion()." against the fixture corpus\n";
echo str_repeat('-', 72)."\n";

foreach ($manifest['fixtures'] as $fixture) {
    if ($fixture['format'] !== 'pdf') {
        continue;
    }

    $path = $root.'/'.$fixture['path'];
    $expected = [];
    foreach ($fixture['expect'] as $expectation) {
        $expected[$expectation['rule']] = $expectation['count'];
    }

    try {
        $document = parser()->parseFile($path);
    } catch (Throwable $e) {
        $verdict = $fixture['status'] === 'error' ? 'threw as expected' : 'UNEXPECTED THROW';
        printf("%-32s %s: %s\n", basename($fixture['path']), $verdict, get_class($e));
        if ($fixture['status'] !== 'error') {
            $disagreements[] = $fixture['path'].': threw '.get_class($e).' — '.$e->getMessage();
            $disagree++;
        }

        continue;
    }

    $found = probe($document);
    $line = [];
    foreach ($found as $rule => $result) {
        $short = substr($rule, 4);
        if ($result === null) {
            // Only counts against the library when the fixture needs an answer.
            if (isset($expected[$rule]) || in_array($rule, $fixture['expect_absent'], true)) {
                $unanswerable++;
                $line[] = "$short=?";
                $disagreements[] = $fixture['path'].": cannot answer $rule";
            }

            continue;
        }

        $fired = $result !== false;
        $shouldFire = isset($expected[$rule]);
        if ($fired !== $shouldFire) {
            $disagree++;
            $line[] = "$short=".($fired ? 'FIRED' : 'silent').'!';
            $disagreements[] = sprintf(
                '%s: %s %s but the manifest says it should %s',
                $fixture['path'], $rule, $fired ? 'fired' : 'stayed silent', $shouldFire ? 'fire' : 'not'
            );

            continue;
        }
        if ($shouldFire && is_int($result) && $result !== $expected[$rule]) {
            $disagree++;
            $line[] = "$short=$result/{$expected[$rule]}!";
            $disagreements[] = sprintf(
                '%s: %s found %d, the manifest expects %d',
                $fixture['path'], $rule, $result, $expected[$rule]
            );

            continue;
        }
        $agree++;
        if ($shouldFire) {
            $line[] = $short.(is_int($result) ? "=$result" : '');
        }
    }

    printf("%-32s %s\n", basename($fixture['path']), $line === [] ? '(clean)' : implode(' ', $line));
}

echo str_repeat('-', 72)."\n";
printf("%d probes agreed with the manifest, %d disagreed, %d unanswerable.\n", $agree, $disagree, $unanswerable);
foreach ($disagreements as $problem) {
    echo "  - $problem\n";
}

// ---------------------------------------------------------------- part two ---

if ($extra !== []) {
    echo "\nReal-world PDFs\n".str_repeat('-', 72)."\n";
    foreach ($extra as $path) {
        if (! is_file($path)) {
            printf("%-44s missing\n", basename($path));

            continue;
        }
        $size = filesize($path);
        $before = memory_get_peak_usage(true);
        $start = hrtime(true);
        try {
            $document = parser()->parseFile($path);
            $found = probe($document);
            $milliseconds = (hrtime(true) - $start) / 1e6;
            $fired = [];
            foreach ($found as $rule => $result) {
                if ($result !== false && $result !== null) {
                    $fired[] = substr($rule, 4).(is_int($result) ? "=$result" : '');
                }
            }
            printf(
                "%-44s %4dp %6.1fKB %7.0fms %5.1fMB  %s\n",
                substr(basename($path), 0, 44),
                count($document->getPages()),
                $size / 1024,
                $milliseconds,
                (memory_get_peak_usage(true) - $before) / 1048576,
                $fired === [] ? '(clean)' : implode(' ', $fired)
            );
        } catch (Throwable $e) {
            printf(
                "%-44s %6.1fKB  THREW %s: %s\n",
                substr(basename($path), 0, 44), $size / 1024,
                (new ReflectionClass($e))->getShortName(), $e->getMessage()
            );
        }
    }
}

function installedVersion(): string
{
    // .../vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php -> .../vendor
    $reflection = new ReflectionClass(Parser::class);
    $installed = dirname($reflection->getFileName(), 6).'/composer/installed.json';
    if (! is_file($installed)) {
        return '(version unknown)';
    }
    $data = json_decode((string) file_get_contents($installed), true);
    foreach ($data['packages'] ?? [] as $package) {
        if ($package['name'] === 'smalot/pdfparser') {
            return $package['version'];
        }
    }

    return '(version unknown)';
}
