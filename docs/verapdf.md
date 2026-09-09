# Do I need veraPDF?

**Almost certainly not.** This page exists so that the answer is "no" before
anybody has installed a Java runtime to find out.

## The short version

| | Built-in checks | veraPDF |
|---|---|---|
| Install | Nothing to do | Java runtime + veraPDF |
| Finds | The problems actually in your library | Formal PDF/UA-1 conformance |
| Authority | Well-informed | Authoritative |
| Speed | ~1s per document | ~1.5s per document, plus a JVM start |

If you need to tell somebody "these documents conform to PDF/UA-1" and expect to
be checked, install it. Otherwise do not.

## Why the built-in checks are usually enough

They find what is actually wrong with a real document library: files with no
tags at all, scans that are pictures of words, documents with no title or
language, figures nobody described, forms whose fields are unlabelled,
encryption that blocks a screen reader outright.

Those are the findings somebody acts on. PDF/UA conformance is a stricter
question, and a document can fail it on details — a missing metadata packet, a
font that is not embedded — while being perfectly readable.

## What changes if you install it

Both run. veraPDF does **not** replace the built-in checks, because PDF/UA has
no requirement about bookmarks in a long document and none about a scan with no
text layer — so replacing one with the other would delete "210 image-only scans"
from your dashboard the day you installed Java.

Instead, findings from both appear together, seven PDF/UA clauses are reported
under the rule names you already know, and everything else appears under its ISO
clause. The engine recorded against each document becomes
`heuristics+verapdf`, and every report says so.

Installing it also **re-checks the library**, because results produced by the
weaker engine should not be presented as though the stronger one had seen them.

## Installing it

veraPDF needs a Java runtime. [Installation instructions are
here](https://docs.verapdf.org/install/).

Once `verapdf` is on the `PATH`, this addon finds it. Nothing else to configure:

```
php please docs:check --force
```

To point at a binary somewhere else, or to turn it off on a server that has it:

```php
// config/a11y-docs.php
'verapdf' => [
    'enabled' => true,
    'binary' => '/opt/verapdf/verapdf',
    'timeout' => 60,
    'max_bytes' => 64 * 1024 * 1024,
],
```

## If it is not installed

Nothing happens. The absence is detected once, the built-in checks run as
normal, and every report says `heuristics` rather than claiming validation it
did not perform. **That is the intended configuration for most sites.**

## If it times out or the document is too large

The document is still checked by the built-in rules, and the result records that
PDF/UA validation did not complete — because a site that configured validation
and quietly did not get it should be told.
