# Document fixture corpus

45 deliberately broken — and deliberately correct — documents, one failure mode
at a time, plus `manifest.json` recording what each file is expected to produce.

Nothing in this addon can be verified without them. A rule that never fires and
a rule that fires on everything both look like working code until there is a
file that says otherwise.

## Regenerating

```
php tools/build-fixtures.php     # rewrite the files and manifest.json
php tools/verify-fixtures.php    # structural check over the manifest
```

The generators live in `tools/fixtures/`. Output is deterministic — fixed ZIP
timestamps, fixed PDF `/ID` values, fixed image bytes — so building on a clean
tree leaves `git status` clean. If it does not, something has become time- or
environment-dependent and the hashes in `manifest.json` can no longer be
trusted.

Edit the generator, never the generated file.

## manifest.json

One entry per fixture:

| field | meaning |
|---|---|
| `path`, `format`, `bytes`, `sha256` | identity, and drift detection |
| `status` | expected `document_checks.status` — `pass`, `fail`, `error`, `unsupported` |
| `expect` | findings that must be produced: `rule`, `severity`, and an exact `count` |
| `expect_absent` | rules that must **not** fire on this file |
| `summary`, `notes` | what the file is, and what is easy to get wrong about it |

`expect_absent` carries as much weight as `expect`. Most of these files are
correct in every respect but one, so a rule that over-fires is caught by the
fixture for a neighbouring rule rather than by its own.

Counts are exact on purpose. `docx/image-missing-alt.docx` expects exactly two
findings from three images; an implementation that reports the described image
too, or that reports one finding for the document, is wrong in a way a boolean
assertion would not catch.

## What is covered

**PDF** (15 files) — tagged, untagged, missing title, title in XMP only, title
not displayed, missing language, image-only scan, encrypted with accessibility
extraction blocked, encrypted with it allowed, long document with and without
bookmarks, unlabelled form fields, figure without alt text, subset font without
`/ToUnicode`, truncated file.

**Word** (11) — good, image without alt, alt text that is a filename or the
object's own name, an undescribed logo in a header, no heading styles, table
without a header row, complex merged table, unhelpful link text, no title and no
language, enforced document protection, untitled content controls.

**PowerPoint** (8) — good, slide without a title, duplicate slide titles, picture
and drawn shape without alt text, alt text that is a filename or the object's own
name, shapes ordered against the layout, video without captions, default and
repeated section names.

**Excel** (6) — good, default and blank sheet names, table without a declared
header row, drawings without alt text, alt text that is a filename or the
object's own name, red-only conditional formatting.

**Legacy and mislabelled** (5) — `.doc`, `.ppt` and `.xls` as OLE2 compound
files that must be reported `unsupported` rather than parsed; a PDF renamed
`.docx`; a zero-byte `.pdf`.

## Deliberate near-misses

These exist to catch over-firing, and they are the most valuable files here:

- `pdf/encrypted-accessible.pdf` — encrypted, but bit 10 is set. "Encrypted"
  must never on its own mean "inaccessible".
- `pdf/xmp-title-only.pdf` — no `/Info /Title`, but `dc:title` in XMP. The title
  rule fails only when *both* are missing.
- `pdf/no-tounicode.pdf` — text extraction yields nothing useful, but there are
  no images, so `pdf.image_only` must stay silent.
- `pdf/untagged.pdf` — two pages, no `/Outlines`, and no bookmark finding: the
  rule only applies over 20 pages.
- `pdf/long-with-bookmarks.pdf` — 25 pages *with* bookmarks, catching the
  inverse mistake.
- `docx/complex-table.docx` — merged cells, but a correctly marked header row.
- `misc/actually-a-pdf.docx` — format detection has to read the bytes, not the
  extension.

## Known gaps

Not yet covered by a fixture, and worth adding when the matching rule is
written:

- A caption relationship captured from a real captioned deck.
  `pptx/media-without-captions.pptx` uses the Microsoft 2016 extension type
  written from documentation, so its negative control currently tests our own
  assumption rather than PowerPoint's behaviour.
- A shape marked decorative. PowerPoint records that in an extension this
  corpus does not build, and such a shape should be exempt from the alt-text
  rule rather than reported.
- A tagged PDF whose `/Alt` is a filename. The alt-text quality heuristic is
  format-neutral and the three Office formats use it; PDF does not yet, so the
  same rubbish passes there.
- A `skipped` fixture for the size cap. That threshold is a configuration
  decision, and a file large enough to exercise it does not belong in git.

## Caveats

- The fixtures are structural, not renderable artefacts. `pdf/no-tounicode.pdf`
  references a subset font whose programme is not embedded, and the tagged PDFs
  carry marked content without a full role map. They exercise the object graph,
  which is what the heuristics read.
- The encrypted PDFs use RC4 128-bit, revision 3, with an empty user password,
  so they open without prompting. Revision 3 is required: revision 2 leaves
  permission bit 10 undefined, and clearing it there would mean nothing.
