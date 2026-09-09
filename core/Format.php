<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

/**
 * The document formats this addon knows about, and how to work out which one a
 * file actually is.
 *
 * Detection reads the file, not the extension. An asset library is full of
 * documents that were renamed at some point in their life, and handing a PDF
 * to the OOXML reader produces a ZIP error that gets recorded as `error` —
 * hiding whatever real finding the file was carrying.
 */
enum Format: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';
    case Pptx = 'pptx';
    case Xlsx = 'xlsx';

    // The binary formats that predate OOXML. Detected so they can be reported
    // as unsupported; never parsed.
    case Doc = 'doc';
    case Ppt = 'ppt';
    case Xls = 'xls';

    private const PDF_SIGNATURE = '%PDF-';

    private const ZIP_SIGNATURE = "PK\x03\x04";

    private const OLE2_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** The part whose presence identifies each OOXML flavour. */
    private const OOXML_MARKERS = [
        'word/document.xml' => self::Docx,
        'ppt/presentation.xml' => self::Pptx,
        'xl/workbook.xml' => self::Xlsx,
    ];

    /** The stream that identifies each legacy flavour inside an OLE2 container. */
    private const OLE2_MARKERS = [
        'WordDocument' => self::Doc,
        'PowerPoint Document' => self::Ppt,
        'Workbook' => self::Xls,
        'Book' => self::Xls,
    ];

    /**
     * Work out the format of a file: what is inside it first, what it is called
     * second. The extension is the fallback rather than the answer, so a
     * renamed file is classified correctly and an unreadable one is still
     * classified as whatever it claims to be — which is what lets an empty or
     * truncated file be recorded as a failed PDF rather than as a mystery.
     */
    public static function detect(string $path): ?self
    {
        return self::fromContent($path) ?? self::fromExtension($path);
    }

    public static function fromExtension(string $path): ?self
    {
        return self::tryFrom(strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    }

    public static function fromContent(string $path): ?self
    {
        $signature = self::readAt($path, 0, strlen(self::OLE2_SIGNATURE));

        if ($signature === null) {
            return null;
        }
        if (str_starts_with($signature, self::PDF_SIGNATURE)) {
            return self::Pdf;
        }
        if (str_starts_with($signature, self::ZIP_SIGNATURE)) {
            return self::fromOoxmlParts($path);
        }
        if (str_starts_with($signature, self::OLE2_SIGNATURE)) {
            return self::fromOle2Streams($path);
        }

        return null;
    }

    public function isOoxml(): bool
    {
        return in_array($this, [self::Docx, self::Pptx, self::Xlsx], true);
    }

    /** A binary Office format that must be reported as unsupported, not parsed. */
    public function isLegacy(): bool
    {
        return in_array($this, [self::Doc, self::Ppt, self::Xls], true);
    }

    public function isSupported(): bool
    {
        return ! $this->isLegacy();
    }

    /** What to tell someone to re-save a legacy document as. */
    public function modernEquivalent(): self
    {
        return match ($this) {
            self::Doc => self::Docx,
            self::Ppt => self::Pptx,
            self::Xls => self::Xlsx,
            default => $this,
        };
    }

    /** Which OOXML flavour, decided by the marker part in the ZIP directory. */
    private static function fromOoxmlParts(string $path): ?self
    {
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            foreach (self::OOXML_MARKERS as $part => $format) {
                if ($zip->locateName($part) !== false) {
                    return $format;
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /**
     * Which legacy flavour, decided by the stream names in the compound file's
     * directory. Only the first directory sector is read: the main stream is
     * always among the first entries, and returning null here simply falls
     * through to the extension, which for these files is nearly always right.
     */
    private static function fromOle2Streams(string $path): ?self
    {
        $header = self::readAt($path, 0, 512);
        if ($header === null || strlen($header) < 512) {
            return null;
        }

        $sectorSize = 1 << (unpack('v', substr($header, 30, 2))[1] ?? 9);
        $firstDirectorySector = unpack('V', substr($header, 48, 4))[1] ?? 0;
        if ($sectorSize < 128 || $sectorSize > 1048576) {
            return null;
        }

        $directory = self::readAt($path, ($firstDirectorySector + 1) * $sectorSize, $sectorSize);
        if ($directory === null) {
            return null;
        }

        foreach (self::OLE2_MARKERS as $stream => $format) {
            if (str_contains($directory, self::utf16le($stream))) {
                return $format;
            }
        }

        return null;
    }

    /** Directory entry names are UTF-16LE. The names we look for are all ASCII. */
    private static function utf16le(string $ascii): string
    {
        return implode('', array_map(
            static fn (string $character): string => $character."\x00",
            str_split($ascii)
        ));
    }

    /** Read a bounded slice. Never loads a whole document to identify it. */
    private static function readAt(string $path, int $offset, int $length): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                return null;
            }
            $bytes = fread($handle, $length);
        } finally {
            fclose($handle);
        }

        return $bytes === false || $bytes === '' ? null : $bytes;
    }
}
