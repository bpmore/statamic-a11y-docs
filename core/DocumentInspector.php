<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

use Bpmore\DocumentA11yCore\Ooxml\OoxmlInspector;
use Bpmore\DocumentA11yCore\Pdf\AugmentedPdfInspector;

/**
 * Hand it a file; it works out what the file is and checks it.
 *
 * The single entry point everything above this package uses — the command line,
 * the queue, the control panel — so that "a .doc is reported as unsupported"
 * is true of the product and not only of one class nobody remembered to call.
 */
final class DocumentInspector
{
    /** @var list<Inspector> */
    private readonly array $inspectors;

    /** @param  list<Inspector>|null  $inspectors */
    public function __construct(?array $inspectors = null)
    {
        $this->inspectors = $inspectors ?? [
            new AugmentedPdfInspector,
            new OoxmlInspector,
            new LegacyOfficeInspector,
        ];
    }

    public function inspect(string $path): InspectionResult
    {
        // Reads the file rather than trusting its name, so a PDF somebody
        // renamed .docx is checked as a PDF instead of failing as a broken ZIP.
        $format = Format::detect($path);

        if ($format === null) {
            return InspectionResult::unsupported(
                'This file is not a document this addon knows how to check.'
            );
        }

        $inspector = $this->inspectorFor($format);

        if ($inspector === null) {
            return InspectionResult::unsupported(
                "There is no accessibility check for {$format->value} files."
            );
        }

        // Timed here because it is the only place that sees the whole job, and
        // spec §7 wants duration on every check — a scan that slows down over
        // months is a thing somebody needs to be able to see.
        $started = hrtime(true);
        $result = $inspector->inspect($path, $format);

        return $result->withDuration((int) round((hrtime(true) - $started) / 1_000_000));
    }

    /** What would check a file of this format, if anything. */
    public function inspectorFor(Format $format): ?Inspector
    {
        foreach ($this->inspectors as $inspector) {
            if ($inspector->supports($format)) {
                return $inspector;
            }
        }

        return null;
    }

    public function supports(Format $format): bool
    {
        return $this->inspectorFor($format) !== null;
    }

    /** @return list<Format> */
    public function formatsChecked(): array
    {
        return array_values(array_filter(
            Format::cases(),
            fn (Format $format): bool => $format->isSupported() && $this->supports($format),
        ));
    }
}
