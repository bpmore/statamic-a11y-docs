<?php

declare(strict_types=1);

/**
 * Regenerates the document fixture corpus in tests/fixtures/documents.
 *
 *     php tools/build-fixtures.php
 *
 * Output is deterministic: fixed ZIP timestamps, fixed PDF /ID values, fixed
 * image bytes. Running this on a clean tree must leave `git status` clean.
 * If it does not, something has become time- or environment-dependent and the
 * checked-in hashes in manifest.json can no longer be trusted.
 */

namespace Bpmore\FixtureTools;

require __DIR__.'/fixtures/Zip.php';
require __DIR__.'/fixtures/PdfBuilder.php';
require __DIR__.'/fixtures/Ooxml.php';
require __DIR__.'/fixtures/pdf.php';
require __DIR__.'/fixtures/docx.php';
require __DIR__.'/fixtures/pptx.php';
require __DIR__.'/fixtures/xlsx.php';
require __DIR__.'/fixtures/legacy.php';

$root = dirname(__DIR__).'/tests/fixtures/documents';

$fixtures = [
    ...pdf_fixtures(),
    ...docx_fixtures(),
    ...pptx_fixtures(),
    ...xlsx_fixtures(),
    ...legacy_fixtures(),
];

$manifest = [];
$written = 0;

foreach ($fixtures as $fixture) {
    $path = $root.'/'.$fixture['path'];
    $directory = dirname($path);
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $bytes = $fixture['bytes'];
    $existing = is_file($path) ? file_get_contents($path) : null;
    if ($existing !== $bytes) {
        file_put_contents($path, $bytes);
        $written++;
    }

    $manifest[] = [
        'path' => $fixture['path'],
        'format' => $fixture['format'],
        'status' => $fixture['status'],
        'bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'summary' => $fixture['summary'],
        'expect' => $fixture['expect'],
        'expect_absent' => $fixture['expect_absent'],
        'notes' => $fixture['notes'],
    ];
}

usort($manifest, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

$paths = array_column($manifest, 'path');
$duplicates = array_diff_assoc($paths, array_unique($paths));
if ($duplicates !== []) {
    fwrite(STDERR, 'Duplicate fixture paths: '.implode(', ', $duplicates)."\n");
    exit(1);
}

$document = [
    'generated_by' => 'tools/build-fixtures.php',
    'description' => 'Deliberately broken (and deliberately correct) documents, with the findings each one is expected to produce.',
    'severities' => ['critical', 'serious', 'moderate', 'minor'],
    'statuses' => ['pass', 'fail', 'error', 'skipped', 'unsupported'],
    'count' => count($manifest),
    'fixtures' => $manifest,
];

file_put_contents(
    $root.'/manifest.json',
    json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
);

$byFormat = array_count_values(array_column($manifest, 'format'));
ksort($byFormat);
$formats = [];
foreach ($byFormat as $format => $count) {
    $formats[] = "$format:$count";
}

printf("%d fixtures (%s), %d rewritten.\n", count($manifest), implode(' ', $formats), $written);
