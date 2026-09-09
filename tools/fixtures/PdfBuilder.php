<?php

declare(strict_types=1);

namespace Bpmore\FixtureTools;

/**
 * A minimal PDF writer, enough to hand-build fixtures with precise control over
 * the catalog-level entries the inspector cares about: /StructTreeRoot,
 * /MarkInfo, /Lang, /ViewerPreferences, /Outlines, /AcroForm, encryption.
 *
 * Deliberately not a general PDF library. It emits classic cross-reference
 * tables (no xref streams) so the output stays greppable, which matters when
 * you are debugging why an inspector missed something.
 */
final class PdfBuilder
{
    /** @var array<int, array{body: string, stream: ?string}> */
    private array $objects = [];

    private int $next = 1;

    private ?string $encKey = null;

    private int $encKeyLength = 16;

    /** The /ID value. Fixed, so regenerating a fixture is byte-stable. */
    private string $id;

    /** Padding string from the PDF standard security handler (Algorithm 2). */
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        ."\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    public function __construct(string $id = "\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff\x00")
    {
        $this->id = $id;
    }

    public function reserve(): int
    {
        return $this->next++;
    }

    /** Write a plain object. $body is the complete object body, e.g. "<< /Type /Catalog >>". */
    public function obj(string $body, ?int $num = null): int
    {
        $num ??= $this->reserve();
        $this->objects[$num] = ['body' => $body, 'stream' => null];

        return $num;
    }

    /**
     * Write a stream object. $dictEntries is the dictionary *without* the
     * enclosing << >> and without /Length, which is computed here (after
     * encryption, which changes the length).
     */
    public function stream(string $dictEntries, string $data, ?int $num = null): int
    {
        $num ??= $this->reserve();
        $this->objects[$num] = ['body' => $dictEntries, 'stream' => $data];

        return $num;
    }

    /**
     * Turn on the standard security handler: revision 3, 128-bit RC4, empty
     * user password so the file still opens, and $p as the permission bitfield.
     *
     * Bit 10 (value 512) is "extract text and graphics for accessibility".
     * Revision 2 leaves that bit undefined, which is why the fixtures use
     * revision 3 — clearing bit 10 only means something from R3 onwards.
     */
    public function encrypt(int $p, string $ownerPassword = 'owner', string $userPassword = ''): array
    {
        $n = $this->encKeyLength;

        // Algorithm 3 — the /O value.
        $h = md5($this->pad($ownerPassword !== '' ? $ownerPassword : $userPassword), true);
        for ($i = 0; $i < 50; $i++) {
            $h = md5($h, true);
        }
        $rc4Key = substr($h, 0, $n);
        $o = self::rc4($rc4Key, $this->pad($userPassword));
        for ($i = 1; $i <= 19; $i++) {
            $o = self::rc4(self::xorKey($rc4Key, $i), $o);
        }

        // Algorithm 2 — the file encryption key.
        $key = md5($this->pad($userPassword).$o.pack('V', $p).$this->id, true);
        for ($i = 0; $i < 50; $i++) {
            $key = md5(substr($key, 0, $n), true);
        }
        $key = substr($key, 0, $n);

        // Algorithm 5 — the /U value.
        $u = self::rc4($key, md5(self::PAD.$this->id, true));
        for ($i = 1; $i <= 19; $i++) {
            $u = self::rc4(self::xorKey($key, $i), $u);
        }
        $u .= str_repeat("\x00", 16);

        $this->encKey = $key;

        return ['O' => $o, 'U' => $u, 'P' => $p, 'V' => 2, 'R' => 3, 'Length' => $n * 8];
    }

    /** The /Encrypt dictionary body for the values returned by encrypt(). */
    public function encryptDict(array $e): string
    {
        return '<< /Filter /Standard /V '.$e['V'].' /R '.$e['R'].' /Length '.$e['Length']
            .' /P '.$e['P']
            .' /O <'.bin2hex($e['O']).'>'
            .' /U <'.bin2hex($e['U']).'> >>';
    }

    /**
     * A PDF string, written as hex and encrypted if the document is encrypted.
     * Hex avoids every literal-string escaping question, and encrypted strings
     * have to be hex anyway once RC4 has produced arbitrary bytes.
     */
    public function str(string $value, int $objNum): string
    {
        if ($this->encKey !== null) {
            $value = self::rc4($this->objectKey($objNum), $value);
        }

        return '<'.bin2hex($value).'>';
    }

    public function build(int $root, ?int $info = null, ?int $encrypt = null, string $version = '1.7'): string
    {
        // A binary comment on line 2 marks the file as binary for transfer tools.
        $out = "%PDF-$version\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $max = $this->next - 1;

        for ($num = 1; $num <= $max; $num++) {
            if (! isset($this->objects[$num])) {
                continue;
            }
            $offsets[$num] = strlen($out);
            $object = $this->objects[$num];

            if ($object['stream'] === null) {
                $out .= "$num 0 obj\n".$object['body']."\nendobj\n";

                continue;
            }

            $data = $object['stream'];
            if ($this->encKey !== null && $num !== $encrypt) {
                $data = self::rc4($this->objectKey($num), $data);
            }

            $entries = trim($object['body']);
            $entries = $entries === '' ? '' : $entries.' ';
            $out .= "$num 0 obj\n<< {$entries}/Length ".strlen($data)." >>\nstream\n".$data."\nendstream\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $out .= "xref\n0 ".($max + 1)."\n";
        $out .= "0000000000 65535 f \n";
        for ($num = 1; $num <= $max; $num++) {
            $out .= isset($offsets[$num])
                ? sprintf("%010d 00000 n \n", $offsets[$num])
                : "0000000000 65535 f \n";
        }

        $hexId = bin2hex($this->id);
        $trailer = '<< /Size '.($max + 1).' /Root '.$root.' 0 R';
        if ($info !== null) {
            $trailer .= ' /Info '.$info.' 0 R';
        }
        if ($encrypt !== null) {
            $trailer .= ' /Encrypt '.$encrypt.' 0 R';
        }
        $trailer .= " /ID [<$hexId> <$hexId>] >>";

        return $out."trailer\n$trailer\nstartxref\n$xrefOffset\n%%EOF\n";
    }

    /** Algorithm 1 — the per-object key derived from the file key. */
    private function objectKey(int $num, int $gen = 0): string
    {
        $material = $this->encKey.substr(pack('V', $num), 0, 3).substr(pack('V', $gen), 0, 2);

        return substr(md5($material, true), 0, min($this->encKeyLength + 5, 16));
    }

    private function pad(string $password): string
    {
        return substr($password.self::PAD, 0, 32);
    }

    private static function xorKey(string $key, int $i): string
    {
        $out = '';
        for ($b = 0, $len = strlen($key); $b < $len; $b++) {
            $out .= chr(ord($key[$b]) ^ $i);
        }

        return $out;
    }

    /** RC4. OpenSSL 3 dropped it from the default provider, so it lives here. */
    public static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $keyLength = strlen($key);
        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLength])) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }

        $out = '';
        $i = $j = 0;
        for ($k = 0, $n = strlen($data); $k < $n; $k++) {
            $i = ($i + 1) % 256;
            $j = ($j + $s[$i]) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) % 256]);
        }

        return $out;
    }
}
