<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

/**
 * Whether a piece of alternative text is any use.
 *
 * Presence is the easy half. Microsoft's own checker looks at quality too, and
 * so does anyone who opens the document: "image1.png" read aloud is a filename,
 * and "Picture 3" is the name the software gave the object, not a description
 * of it. Both leave somebody with exactly as much information as no alt text at
 * all, while looking, to a spreadsheet of counts, like the problem was fixed.
 *
 * Kept format-neutral because all three OOXML formats need it and a tagged PDF
 * can carry the same rubbish in `/Alt`.
 */
final class AltText
{
    /** Extensions that make a string a filename rather than a description. */
    private const FILE_EXTENSIONS = 'png|jpe?g|gif|bmp|tiff?|webp|svg|emf|wmf|heic|ico|pdf|eps|ai|psd';

    /**
     * The words Office uses when it names an object for you.
     *
     * The whole string has to be one of these, optionally followed by a number,
     * so "Photograph of the library" is safe and "Picture 3" is not.
     */
    private const GENERIC_NAMES = 'picture|image|img|photo|photograph|graphic|graphics|chart|diagram'
        .'|shape|object|group|drawing|figure|rectangle|oval|rounded rectangle|text ?box|smart ?art'
        .'|content placeholder|placeholder|screenshot|screen ?shot|untitled';

    /** Absent, empty or nothing but whitespace — all the same to a screen reader. */
    public static function isMissing(?string $alt): bool
    {
        return $alt === null || trim($alt) === '';
    }

    public static function isFilename(?string $alt): bool
    {
        if (self::isMissing($alt)) {
            return false;
        }

        return preg_match('/\.('.self::FILE_EXTENSIONS.')$/i', trim((string) $alt)) === 1;
    }

    /**
     * Alt text that is the object's own name, or one of the names Office
     * invents. `$objectName` catches the case where somebody has pasted the
     * shape's name in, which no fixed list of words could.
     */
    public static function isPlaceholder(?string $alt, ?string $objectName = null): bool
    {
        if (self::isMissing($alt)) {
            return false;
        }

        $trimmed = trim((string) $alt);

        if ($objectName !== null && strcasecmp($trimmed, trim($objectName)) === 0) {
            return true;
        }

        return preg_match('/^('.self::GENERIC_NAMES.')\s*\d*$/iu', $trimmed) === 1;
    }
}
