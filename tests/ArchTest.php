<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Format;

// The core package must not import Statamic or Laravel. This assertion is
// worth more here than in the core package's own suite, where neither
// framework is installed at all: Statamic\
// is a registered PSR-4 prefix in this repository, so Pest can actually see a
// dependency on it.
arch('core stays framework-agnostic')
    ->expect('Bpmore\DocumentA11yCore')
    ->not->toUse(['Statamic', 'Illuminate']);

arch('the addon may depend on core, and core may not depend on the addon')
    ->expect('Bpmore\DocumentA11yCore')
    ->not->toUse('Bpmore\StatamicA11yDocs');

arch('the addon declares strict types')
    ->expect('Bpmore\StatamicA11yDocs')
    ->toUseStrictTypes();

arch('core declares strict types')
    ->expect('Bpmore\DocumentA11yCore')
    ->toUseStrictTypes();

it('never reaches for a framework', function (string $file) {
    $frameworks = ['Statamic', 'Illuminate', 'Laravel', 'Orchestra'];

    $violations = array_values(array_unique(array_filter(
        referencedNames($file),
        static fn (string $name): bool => in_array(strtok($name, '\\'), $frameworks, true),
    )));

    expect($violations)->toBe([]);
})->with(sourceFiles());

it('exposes the namespace the boundary is drawn around', function () {
    // Guards the arch expectations above: if the namespace is ever renamed,
    // they would silently start matching nothing at all.
    expect(Format::class)->toStartWith('Bpmore\\DocumentA11yCore\\');
});

it('keeps the PDF library behind one class', function () {
    // The constraint the reader decision was made under. It cannot be an arch
    // expectation: smalot/pdfparser autoloads via psr-0, and Pest resolves
    // dependencies against the registered psr-4 prefixes only, so
    // toUse('Smalot\\PdfParser') matches nothing and passes on any code at all.
    $offenders = [];

    foreach (allSourceFiles() as $relative => $path) {
        $smalot = array_values(array_unique(array_filter(
            referencedNames($path),
            static fn (string $name): bool => str_starts_with($name, 'Smalot\\'),
        )));

        if ($smalot !== []) {
            $offenders[$relative] = $smalot;
        }
    }

    // Exactly one file, and it is the reader — which also proves the check is
    // looking at something rather than passing because it found nothing.
    expect(array_keys($offenders))->toBe(['core/Pdf/SmalotPdfReader.php']);
});

it('keeps process execution behind one class', function () {
    // Same reasoning as the PDF library, and the same mechanism: the files that
    // may start an external process are named, so adding one is a deliberate
    // decision rather than something that creeps in.
    $offenders = [];

    foreach (allSourceFiles() as $relative => $path) {
        $process = array_values(array_unique(array_filter(
            referencedNames($path),
            static fn (string $name): bool => str_starts_with($name, 'Symfony\\Component\\Process'),
        )));

        if ($process !== []) {
            $offenders[$relative] = $process;
        }
    }

    // An allowlist, not a count. Two external programs, each behind one class:
    // veraPDF validates documents, Chrome prints the report. Anything else
    // starting a process has to be added here on purpose.
    expect(array_keys($offenders))->toBe([
        'core/VeraPdf/ProcessVeraPdfRunner.php',
        'src/Reporting/ChromePdfRenderer.php',
    ]);
});
