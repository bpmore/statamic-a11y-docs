<?php

declare(strict_types=1);

/**
 * Structural check over the fixture corpus.
 *
 *     php tools/verify-fixtures.php
 *
 * There is no test runner in this repository yet — the addon skeleton is a
 * later task — so this script is what stands in for one. It checks that every
 * manifest entry exists with the recorded hash, that each file really is the
 * format it claims, and that the markers each fixture exists to carry are
 * actually present or absent as intended. Exits non-zero on any failure.
 */
$root = dirname(__DIR__).'/tests/fixtures/documents';
$manifestPath = $root.'/manifest.json';

if (! is_file($manifestPath)) {
    fwrite(STDERR, "manifest.json is missing. Run: php tools/build-fixtures.php\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$failures = [];
$checks = 0;

$fail = function (string $path, string $message) use (&$failures): void {
    $failures[] = "$path: $message";
};

$severities = $manifest['severities'];

foreach ($manifest['fixtures'] as $fixture) {
    $path = $fixture['path'];
    $file = $root.'/'.$path;

    if (! is_file($file)) {
        $fail($path, 'file is missing');

        continue;
    }

    $bytes = (string) file_get_contents($file);
    $checks++;

    if (strlen($bytes) !== $fixture['bytes']) {
        $fail($path, sprintf('size is %d, manifest says %d', strlen($bytes), $fixture['bytes']));
    }
    if (hash('sha256', $bytes) !== $fixture['sha256']) {
        $fail($path, 'sha256 does not match the manifest — regenerate with tools/build-fixtures.php');
    }

    foreach ($fixture['expect'] as $expectation) {
        if (! in_array($expectation['severity'], $severities, true)) {
            $fail($path, "unknown severity '{$expectation['severity']}'");
        }
        if ($expectation['count'] < 1) {
            $fail($path, "expectation for {$expectation['rule']} has a count below 1");
        }
    }

    $expectedRules = array_column($fixture['expect'], 'rule');
    $overlap = array_intersect($expectedRules, $fixture['expect_absent']);
    if ($overlap !== []) {
        $fail($path, 'rules appear in both expect and expect_absent: '.implode(', ', $overlap));
    }

    if ($fixture['status'] === 'pass' && $fixture['expect'] !== []) {
        $fail($path, 'status is pass but findings are expected');
    }
    if ($fixture['status'] === 'fail' && $fixture['expect'] === []) {
        $fail($path, 'status is fail but no findings are expected');
    }

    verify_shape($path, $fixture, $bytes, $fail);
}

/** Format-level sanity, plus the specific marker each fixture exists to carry. */
function verify_shape(string $path, array $fixture, string $bytes, callable $fail): void
{
    $isPdf = str_starts_with($bytes, '%PDF-');
    $isZip = str_starts_with($bytes, "PK\x03\x04");
    $isOle2 = str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");

    if (in_array($fixture['format'], ['docx', 'pptx', 'xlsx'], true)) {
        if (! $isZip) {
            $fail($path, 'is not a ZIP container');

            return;
        }
        verify_ooxml($path, $bytes, $fail);

        return;
    }

    if (in_array($fixture['format'], ['doc', 'ppt', 'xls'], true)) {
        if (! $isOle2) {
            $fail($path, 'is not an OLE2 compound file');
        }

        return;
    }

    if ($fixture['format'] === 'pdf' && $fixture['status'] !== 'error' && ! $isPdf) {
        $fail($path, 'does not start with %PDF-');
    }

    if ($fixture['format'] !== 'pdf' || $fixture['status'] === 'error') {
        return;
    }

    // Every non-error PDF must be structurally complete.
    foreach (['xref', 'trailer', 'startxref', '%%EOF'] as $marker) {
        if (! str_contains($bytes, $marker)) {
            $fail($path, "PDF is missing '$marker'");
        }
    }

    // The catalog markers the heuristics read, checked against what the
    // fixture claims. A fixture that stops carrying its own failure mode is
    // worse than no fixture, because the test that uses it still passes.
    $rules = array_column($fixture['expect'], 'rule');
    $absent = $fixture['expect_absent'];
    $markers = [
        'pdf.not_tagged' => '/StructTreeRoot',
        'pdf.no_lang' => '/Lang',
        'pdf.title_not_displayed' => '/DisplayDocTitle',
    ];

    foreach ($markers as $rule => $marker) {
        $present = str_contains($bytes, $marker);
        if (in_array($rule, $rules, true) && $present) {
            $fail($path, "expects $rule but still contains $marker");
        }
        if (in_array($rule, $absent, true) && ! $present) {
            $fail($path, "expects no $rule but does not contain $marker");
        }
    }

    if (in_array('pdf.extraction_blocked', $rules, true) && ! str_contains($bytes, '/Encrypt')) {
        $fail($path, 'expects pdf.extraction_blocked but is not encrypted');
    }

    // The bookmark rule needs both halves of its condition, so the fixture has
    // to be checked against page count as well as the presence of /Outlines.
    $pages = substr_count($bytes, '/Type /Page ');
    $hasOutlines = str_contains($bytes, '/Outlines');
    if (in_array('pdf.no_bookmarks', $rules, true) && ($hasOutlines || $pages <= 20)) {
        $fail($path, "expects pdf.no_bookmarks but has $pages page(s) and ".($hasOutlines ? 'an' : 'no').' /Outlines entry');
    }
    if (in_array('pdf.no_bookmarks', $absent, true) && $pages > 20 && ! $hasOutlines) {
        $fail($path, "expects no pdf.no_bookmarks but has $pages pages and no /Outlines entry");
    }
}

/** Every part named in [Content_Types].xml and every relationship target must exist. */
function verify_ooxml(string $path, string $bytes, callable $fail): void
{
    $temporary = tempnam(sys_get_temp_dir(), 'fixture');
    file_put_contents($temporary, $bytes);

    $zip = new ZipArchive;
    if ($zip->open($temporary) !== true) {
        $fail($path, 'ZipArchive could not open the package');
        unlink($temporary);

        return;
    }

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    if (! in_array('[Content_Types].xml', $names, true)) {
        $fail($path, '[Content_Types].xml is missing');
    }

    foreach ($names as $name) {
        if (! str_ends_with($name, '.xml') && ! str_ends_with($name, '.rels')) {
            continue;
        }
        $xml = $zip->getFromName($name);
        $document = new DOMDocument;
        if (@$document->loadXML((string) $xml) === false) {
            $fail($path, "$name is not well-formed XML");

            continue;
        }

        if (! str_ends_with($name, '.rels')) {
            continue;
        }

        // Relationship targets are relative to the part's own directory.
        $base = dirname(dirname($name));
        $base = $base === '.' ? '' : $base.'/';
        foreach ($document->getElementsByTagName('Relationship') as $relationship) {
            if ($relationship->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $target = $relationship->getAttribute('Target');
            $resolved = normalise_part($base.$target);
            if (! in_array($resolved, $names, true)) {
                $fail($path, "$name points at a missing part: $resolved");
            }
        }
    }

    $types = $zip->getFromName('[Content_Types].xml');
    $document = new DOMDocument;
    if (@$document->loadXML((string) $types) !== false) {
        foreach ($document->getElementsByTagName('Override') as $override) {
            $part = ltrim($override->getAttribute('PartName'), '/');
            if (! in_array($part, $names, true)) {
                $fail($path, "[Content_Types].xml declares a missing part: $part");
            }
        }
    }

    $zip->close();
    unlink($temporary);
}

function normalise_part(string $path): string
{
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);

            continue;
        }
        $segments[] = $segment;
    }

    return implode('/', $segments);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures)." problem(s):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - $failure\n");
    }
    exit(1);
}

printf("%d fixtures verified.\n", $checks);
