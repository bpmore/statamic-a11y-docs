<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Reporting;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Prints the report through headless Chrome.
 *
 * Chrome's print-to-PDF produces a genuinely tagged PDF: structure tree,
 * MarkInfo, language, title and DisplayDocTitle. Measured, not assumed — the
 * output is run through this addon's own PDF inspector in the tests.
 *
 * The one thing it does not write is an XMP metadata packet, which PDF/UA
 * requires, so {@see PdfUaMetadata} adds one afterwards.
 */
final class ChromePdfRenderer implements PdfRenderer
{
    /** The usual places a browser lives, in the order worth trying. */
    private const CANDIDATES = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
    ];

    public function __construct(
        private readonly ?string $binary = null,
        private readonly int $timeoutSeconds = 120,
        private readonly PdfUaMetadata $metadata = new PdfUaMetadata,
    ) {}

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    public function render(string $html, string $destination, string $title = 'Document Accessibility Report'): void
    {
        $binary = $this->binary()
            ?? throw new RuntimeException(
                'No browser was found to render the PDF with. Install Chrome, or export the HTML instead.'
            );

        // Written to a file rather than passed as a data: URL, because a report
        // of a thousand documents is larger than a command line.
        $source = tempnam(sys_get_temp_dir(), 'a11y-report').'.html';
        file_put_contents($source, $html);

        try {
            $process = new Process([
                $binary,
                '--headless',
                '--disable-gpu',
                '--no-sandbox',
                // Chrome's default header and footer stamp a URL and a date
                // into the margins as untagged artifacts.
                '--no-pdf-header-footer',
                '--print-to-pdf='.$destination,
                'file://'.$source,
            ]);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            if (! is_file($destination) || filesize($destination) === 0) {
                throw new RuntimeException(
                    'The browser did not produce a PDF: '.trim($process->getErrorOutput() ?: 'no output')
                );
            }
        } finally {
            @unlink($source);
        }

        // Without this the report is a tagged PDF that still fails PDF/UA
        // validation on one clause — which, in a report about PDF/UA, is the
        // screenshot spec §12 warns about.
        $this->metadata->addTo($destination, $title);
    }

    private function binary(): ?string
    {
        if ($this->binary !== null) {
            return is_executable($this->binary) ? $this->binary : null;
        }

        foreach (self::CANDIDATES as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
