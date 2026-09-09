<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Size limit
    |--------------------------------------------------------------------------
    |
    | Documents larger than this are recorded as skipped, with the reason shown,
    | rather than quietly ignored. They are not downloaded to be measured: the
    | size comes from the asset's own metadata, so a 400 MB scan on S3 costs
    | nothing.
    |
    */

    'max_file_size' => (int) env('A11Y_DOCS_MAX_FILE_SIZE', 100 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Report export
    |--------------------------------------------------------------------------
    |
    | `php please docs:report --html=… --pdf=…` writes the report out. The PDF
    | is printed by headless Chrome, which produces a genuinely tagged PDF; an
    | XMP packet is added afterwards so the result passes PDF/UA validation.
    |
    | A report about document accessibility that is itself an untagged PDF is
    | not a report anybody should send anyone.
    |
    */

    'report' => [

        // Null to look in the usual places for Chrome, Chromium or Edge.
        'chrome' => env('A11Y_DOCS_CHROME'),

        'timeout' => (int) env('A11Y_DOCS_CHROME_TIMEOUT', 120),

        // How many known issues to list in the A11y Report appendix. Matches
        // that addon's own appendix cap, so the two read alike; whatever is
        // left out is counted and said so.
        'appendix_limit' => (int) env('A11Y_DOCS_APPENDIX_LIMIT', 1000),

    ],

    /*
    |--------------------------------------------------------------------------
    | The publish gate
    |--------------------------------------------------------------------------
    |
    | An entry that links to a document nobody can read can be stopped from
    | being published. The thing to understand about these defaults is that a
    | site installing this addon already has a backlog — often hundreds of bad
    | PDFs uploaded years ago — and a gate that blocks all of it on day one is
    | a gate that gets switched off on day one.
    |
    | So the gate protects the future, and the dashboard describes the past.
    |
    */

    'gate' => [

        'enabled' => env('A11Y_DOCS_GATE', true),

        // Only block on findings at or above this. Critical means a document
        // somebody genuinely cannot read, not one that could be tidier.
        'threshold' => env('A11Y_DOCS_GATE_THRESHOLD', 'critical'),

        // Leave the existing library alone. Documents already here when the
        // addon first ran are reported but never block anything.
        'grandfather' => env('A11Y_DOCS_GATE_GRANDFATHER', true),

        // The line between "already here" and "new". Null means the moment of
        // the first scan, which is almost always what you want. Set a date to
        // move it — after a remediation push, for instance.
        'grandfather_before' => env('A11Y_DOCS_GATE_GRANDFATHER_BEFORE'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Scanning
    |--------------------------------------------------------------------------
    |
    | A scan enumerates every document in the asset library, chunks it, and
    | queues the chunks as a batch.
    |
    */

    'scan' => [

        // Documents per queued job. Larger means less queue overhead; smaller
        // means finer-grained progress and retries.
        'chunk_size' => (int) env('A11Y_DOCS_CHUNK_SIZE', 25),

        // How many chunks may run at once, across every worker. Each veraPDF
        // call starts a JVM, and fifty at once will take a server down more
        // reliably than any document ever could. Set to 0 for no limit.
        'concurrency' => (int) env('A11Y_DOCS_CONCURRENCY', 3),

        // Which queue to dispatch onto. Null for the default.
        'queue' => env('A11Y_DOCS_QUEUE'),

        // Which asset containers to scan. Null for all of them.
        'containers' => null,

    ],

    /*
    |--------------------------------------------------------------------------
    | veraPDF
    |--------------------------------------------------------------------------
    |
    | Optional. veraPDF is a Java program that performs real PDF/UA validation,
    | and it is authoritative where the built-in checks are only well-informed.
    | You probably do not need it: without it, every PDF is still checked by the
    | PHP heuristics, and every report says which engine produced it.
    |
    | When it is installed the two run together rather than one instead of the
    | other, because PDF/UA has no requirement about bookmarks in a long
    | document or about a scan with no text layer, and those findings should not
    | disappear the day somebody installs Java.
    |
    */

    'verapdf' => [

        // Turn this off to skip validation even where the binary is present.
        // Leaving it on costs nothing when veraPDF is not installed: the
        // absence is detected once and the heuristics carry on alone.
        'enabled' => env('A11Y_DOCS_VERAPDF_ENABLED', true),

        // A path, or a bare name to be found on PATH.
        'binary' => env('A11Y_DOCS_VERAPDF_BINARY', 'verapdf'),

        // ua1 is PDF/UA-1. ua2 and the PDF/A profiles also exist.
        'profile' => env('A11Y_DOCS_VERAPDF_PROFILE', 'ua1'),

        // Seconds. A 400 MB PDF must not hold a queue worker open indefinitely.
        'timeout' => (int) env('A11Y_DOCS_VERAPDF_TIMEOUT', 60),

        // Bytes. Above this, validation is skipped and the heuristics stand
        // alone — the document is still checked, just not by the engine that
        // would take minutes over it.
        'max_bytes' => (int) env('A11Y_DOCS_VERAPDF_MAX_BYTES', 64 * 1024 * 1024),

    ],

];
