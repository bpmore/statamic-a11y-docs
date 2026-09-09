<?php

declare(strict_types=1);

namespace Bpmore\FixtureTools;

/**
 * A tiny, deterministic ZIP writer.
 *
 * PHP's ZipArchive stamps entries with the current time, so regenerating the
 * fixture corpus would produce different bytes every run and churn git. The
 * fixtures are checked in and their hashes are recorded in the manifest, so the
 * output has to be byte-stable: fixed DOS timestamp, fixed entry order, no
 * extra fields, no archive comment.
 */
final class Zip
{
    /** @var list<array{name: string, data: string}> */
    private array $entries = [];

    /** 2026-01-01 00:00:00, as DOS date/time. */
    private const DOS_DATE = ((2026 - 1980) << 9) | (1 << 5) | 1;

    private const DOS_TIME = 0;

    public function add(string $name, string $data): void
    {
        $this->entries[] = ['name' => $name, 'data' => $data];
    }

    public function bytes(): string
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($this->entries as $entry) {
            $name = $entry['name'];
            $raw = $entry['data'];
            $crc = crc32($raw);
            $compressed = gzdeflate($raw, 9);

            // Fall back to stored if deflate somehow made it bigger.
            if ($compressed === false || strlen($compressed) >= strlen($raw)) {
                $method = 0;
                $compressed = $raw;
            } else {
                $method = 8;
            }

            $header = "PK\x03\x04"
                .pack('v', 20)              // version needed
                .pack('v', 0)               // general purpose flags
                .pack('v', $method)
                .pack('v', self::DOS_TIME)
                .pack('v', self::DOS_DATE)
                .pack('V', $crc)
                .pack('V', strlen($compressed))
                .pack('V', strlen($raw))
                .pack('v', strlen($name))
                .pack('v', 0);              // extra field length

            $local .= $header.$name.$compressed;

            $central .= "PK\x01\x02"
                .pack('v', 20)              // version made by
                .pack('v', 20)              // version needed
                .pack('v', 0)
                .pack('v', $method)
                .pack('v', self::DOS_TIME)
                .pack('v', self::DOS_DATE)
                .pack('V', $crc)
                .pack('V', strlen($compressed))
                .pack('V', strlen($raw))
                .pack('v', strlen($name))
                .pack('v', 0)               // extra
                .pack('v', 0)               // comment
                .pack('v', 0)               // disk number start
                .pack('v', 0)               // internal attributes
                .pack('V', 0)               // external attributes
                .pack('V', $offset)
                .$name;

            $offset += strlen($header) + strlen($name) + strlen($compressed);
        }

        $eocd = "PK\x05\x06"
            .pack('v', 0)
            .pack('v', 0)
            .pack('v', count($this->entries))
            .pack('v', count($this->entries))
            .pack('V', strlen($central))
            .pack('V', $offset)
            .pack('v', 0);

        return $local.$central.$eocd;
    }
}
