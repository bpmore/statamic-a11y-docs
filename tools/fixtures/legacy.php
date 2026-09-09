<?php

declare(strict_types=1);

namespace Bpmore\FixtureTools;

/**
 * Legacy binary Office formats and mislabelled files.
 *
 * .doc, .ppt and .xls are OLE2 compound files, not ZIPs. The addon must detect
 * them and record `unsupported` with a clear message. It must not attempt to
 * parse them, and it must not silently pass them: an unparsed document that
 * reports "no issues found" is worse than one that reports "cannot check this".
 */
function legacy_fixtures(): array
{
    return [
        legacy_ole2('legacy/handbook.doc', 'doc', 'WordDocument'),
        legacy_ole2('legacy/deck.ppt', 'ppt', 'PowerPoint Document'),
        legacy_ole2('legacy/budget.xls', 'xls', 'Workbook'),
        misc_pdf_named_docx(),
        misc_empty_pdf(),
    ];
}

/** A structurally plausible OLE2 header, directory sector and named stream entry. */
function ole2_container(string $streamName): string
{
    $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"   // signature
        .str_repeat("\x00", 16)                     // CLSID
        .pack('v', 0x003E)                          // minor version
        .pack('v', 0x0003)                          // major version
        .pack('v', 0xFFFE)                          // little-endian byte order mark
        .pack('v', 9)                               // sector shift: 512-byte sectors
        .pack('v', 6)                               // mini sector shift: 64-byte mini sectors
        .str_repeat("\x00", 6)                      // reserved
        .pack('V', 0)                               // directory sector count
        .pack('V', 1)                               // FAT sector count
        .pack('V', 1)                               // first directory sector
        .pack('V', 0)                               // transaction signature
        .pack('V', 4096)                            // mini stream cutoff
        .pack('V', 0xFFFFFFFE)                      // first mini FAT sector: end of chain
        .pack('V', 0)                               // mini FAT sector count
        .pack('V', 0xFFFFFFFE)                      // first DIFAT sector: end of chain
        .pack('V', 0);                              // DIFAT sector count

    $difat = pack('V', 0).str_repeat(pack('V', 0xFFFFFFFF), 108);
    $header .= $difat;

    // Sector 0: the FAT. Sector 0 is the FAT itself, sector 1 the directory.
    $fat = pack('V', 0xFFFFFFFD).pack('V', 0xFFFFFFFE).str_repeat(pack('V', 0xFFFFFFFF), 126);

    // Sector 1: the directory, four 128-byte entries.
    $directory = ole2_directory_entry('Root Entry', 5)   // 5 = root storage
        .ole2_directory_entry($streamName, 2)            // 2 = stream
        .ole2_directory_entry('', 0)
        .ole2_directory_entry('', 0);

    return $header.$fat.$directory;
}

function ole2_directory_entry(string $name, int $type): string
{
    $utf16 = $name === '' ? '' : mb_convert_encoding($name."\x00", 'UTF-16LE', 'UTF-8');
    $entry = str_pad($utf16, 64, "\x00");
    $entry .= pack('v', strlen($utf16));
    $entry .= chr($type);
    $entry .= chr(1);                                     // colour: black
    $entry .= pack('V', 0xFFFFFFFF)                       // left sibling
        .pack('V', 0xFFFFFFFF)                            // right sibling
        .pack('V', $type === 5 ? 1 : 0xFFFFFFFF);         // child
    $entry .= str_repeat("\x00", 16);                     // CLSID
    $entry .= pack('V', 0);                               // state bits
    $entry .= str_repeat("\x00", 16);                     // created / modified times
    $entry .= pack('V', $type === 2 ? 2 : 0xFFFFFFFE);    // starting sector
    $entry .= pack('V', $type === 2 ? 1024 : 0).pack('V', 0);

    return str_pad($entry, 128, "\x00");
}

function legacy_ole2(string $path, string $format, string $streamName): array
{
    return [
        'path' => $path,
        'format' => $format,
        'status' => 'unsupported',
        'summary' => "An OLE2 compound file carrying a \"$streamName\" stream — the binary format that predates OOXML.",
        'expect' => [],
        'expect_absent' => [],
        'notes' => 'Detection is by the D0CF11E0A1B11AE1 signature plus the stream name, not by extension. Must be recorded as unsupported with a message the user can act on, never parsed and never reported as passing.',
        'bytes' => ole2_container($streamName),
    ];
}

function misc_pdf_named_docx(): array
{
    $pdf = pdf_untagged();

    return [
        'path' => 'misc/actually-a-pdf.docx',
        'format' => 'pdf',
        'status' => 'fail',
        'summary' => 'A PDF with a .docx extension. Someone renamed it; the asset library believes the extension.',
        'expect' => [['rule' => 'pdf.not_tagged', 'severity' => 'critical', 'count' => 1]],
        'expect_absent' => ['docx.image_missing_alt', 'docx.no_title'],
        'notes' => 'Format detection must sniff the content. Handing this file to the OOXML reader produces a ZIP error, which would be recorded as status=error and hide a real critical finding.',
        'bytes' => $pdf['bytes'],
    ];
}

function misc_empty_pdf(): array
{
    return [
        'path' => 'misc/empty.pdf',
        'format' => 'pdf',
        'status' => 'error',
        'summary' => 'A zero-byte file with a .pdf extension.',
        'expect' => [],
        'expect_absent' => [],
        'notes' => 'The cheapest possible crash: an empty file. Must be caught before any parser is invoked.',
        'bytes' => '',
    ];
}
