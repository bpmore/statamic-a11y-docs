# core — `Bpmore\DocumentA11yCore`

Framework-agnostic document accessibility inspection for PDF, DOCX, PPTX and
XLSX. Format detection, the OOXML reader, the PDF inspector, the rule sets, the
findings normaliser and the veraPDF adapter live here. No Statamic, no Laravel.

The addon that consumes it is in `src/`. Nothing here knows that exists.

## Why it is separated at all

It is **not** a separate Composer package. It was, briefly, and that turned out
to break installation: Composer does not inherit `repositories` from a required
package, so a site running `composer require bpmore/statamic-a11y-docs` could
not resolve a sibling package declared only by the addon. Publishing core
separately would have fixed that at the cost of a release step per version, for
a boundary that a test enforces perfectly well on its own.

So the boundary is a namespace and a test, not a package.

The reason to keep it is that framework-free code is testable in milliseconds
without booting anything, which is what makes it practical to run every rule
against a 45-document corpus on every change. That property disappears the
moment one Laravel helper creeps in, so `tests/ArchTest.php` enforces it —
including a token scan that catches what Pest's arch matchers cannot.

## Where to start

`DocumentInspector` is the entry point: hand it a path, it works out what the
file is and checks it.

```php
$result = (new DocumentInspector)->inspect('/path/to/prospectus.pdf');
```

It reads the file rather than trusting its name, times the check, and routes
legacy Office formats to an inspector that reports them `unsupported` without
opening them. Everything below is what it is made of.

## The shape everything else is built on

| Type | What it is |
|---|---|
| `Format` | The seven formats, and detection that reads the file rather than the extension |
| `Inspector` | Reads one document, returns an `InspectionResult`. One per family: PDF, the three OOXML rule sets, veraPDF |
| `InspectionResult` | Everything learned about one document. Lines up with `document_checks` |
| `Finding` | One problem. Lines up with `document_findings` |
| `UncheckedRule` | A rule that could not be evaluated, and why |
| `Severity` | `critical` / `serious` / `moderate` / `minor`, ordered and comparable |
| `Status` | `pass` / `fail` / `error` / `skipped` / `unsupported` |
| `Location` | Page, slide, sheet or element |
| `Engine` | What did the checking, and at which version |

Three decisions in there are worth knowing about.

**Status is derived, not declared.** A checked document fails when it has
findings and passes when it does not, so no caller can record a pass on a
document with a critical finding in it. The other three statuses have their own
constructors and each demands a reason, because a result that says `skipped`
without saying why becomes a support ticket.

**`UncheckedRule` exists because "could not look" is not "nothing found".** The
PDF spike showed an encrypted document reads its title as noise rather than as
absent — a title check run against one would report a perfectly good title as
missing. A report that cannot tell those apart overstates itself, which is the
one thing an accessibility report must not do.

**An inspector does not throw for a bad document.** A malformed file is a result
with `Status::Error`. One broken file in a batch of 1,240 must not take the run
down with it. Exceptions are for programming errors, such as handing an
inspector a format it said it does not support.

## The PDF inspector

`Pdf\PdfInspector` implements the ten heuristics in spec §4. It reads a
`Pdf\PdfDocument`, which a `Pdf\PdfReader` produces — and `SmalotPdfReader` is
the only class in this package that knows a PDF library exists. That seam is
what the reader decision was made for, and a test in `ArchTest.php` enforces it
by scanning tokens (an arch expectation cannot: smalot/pdfparser autoloads via
psr-0, which Pest's dependency matchers cannot see).

Two behaviours are worth knowing before reading the rules:

**An encrypted document gets `UncheckedRule`s, not findings.** Strings and
streams stay encrypted, so a title that exists reads as ciphertext rather than
as absent, and no text can be extracted at all. The title and image-only rules
therefore report that they could not look. Presence checks — `/Lang`, `/Alt`,
`/TU` — still run, because dictionary keys are not encrypted.

**Some rules are per-occurrence and some are per-document.** Every unlabelled
form field and every undescribed figure is its own finding, because each is its
own fix. Fonts without a character map are one finding naming the fonts: a real
302-page PDF has nine of them, and that is one document to re-export, not nine
rows in a queue.

## veraPDF, and why both engines run

`VeraPdf\VeraPdfInspector` shells out to veraPDF for real PDF/UA-1 validation.
It is optional, because it is a Java program; when it is not installed,
`isAvailable()` is false and nothing else changes.

`Pdf\AugmentedPdfInspector` is what to use: the heuristics, plus PDF/UA
validation when the binary is there. **Not** one instead of the other, which is
how spec §5 reads. Running veraPDF against the fixture corpus showed why:
PDF/UA has no requirement about bookmarks in a long document and none about a
scan with no text layer, so `pdf.no_bookmarks` and `pdf.image_only` simply stop
being reported. One of those is a headline number in spec §9 — "1,240 PDFs ·
890 untagged · 210 image-only scans" — and installing an optional engine must
not delete it.

Seven PDF/UA clauses mean exactly what one of our rules means, and arrive under
our identifier, our severity and our wording. Every one of those mappings was
established by running veraPDF 1.30 against the fixture that provokes the
matching heuristic. Two more clauses that look like matches are deliberately
left unmapped — 7.1-3 and 7.2-34 are about individual pieces of content, so
both can fail on a document that *is* tagged and *does* declare a language, and
mapping them would put a flatly untrue statement in a report. Everything else
comes through as `pdf.ua_<clause>`, with severity taken from veraPDF's own tags.

`tests/fixtures/verapdf/` holds reports captured from the real binary. They are
what the parser is tested against; a test that runs the actual binary keeps the
captures honest, and skips where veraPDF is not installed.

## The OOXML reader

DOCX, PPTX and XLSX are the same container: a ZIP of XML parts wired together
by relationship files. `Ooxml\OoxmlPackage` reads that container once, and the
three rule sets sit on top of it — the decision spec §3 says to make from the
first commit, because three separate parsers would be three times the work.

It handles the parts of that container that are easy to get subtly wrong:

- **Relationship targets resolve against the directory of the part that owns
  them**, so a slide's `../slideLayouts/slideLayout1.xml` resolves against
  `ppt/slides` and not against the package root. External targets — a hyperlink
  to the web — stay external rather than becoming part names that cannot exist.
- **XPath uses this package's namespace prefixes, never the document's.** Word,
  LibreOffice and macOS's textutil all choose different prefixes for the same
  namespaces; a rule matching on prefixes works on some files and not others.
- **An absent attribute is distinguishable from an empty one.** That distinction
  is the whole of the alt-text rules: `descr=""` and no `descr` are different in
  the XML and identical to somebody using a screen reader.
- **A part that would expand out of all proportion to the file is refused.** A
  ZIP can be made to expand to gigabytes from a few kilobytes, and a queue
  worker that reads one of those into a string does not come back.
- Entity substitution and network fetches are both off, which is not theoretical
  for files uploaded by the public.

`tests/fixtures/ooxml/textutil.docx` was produced by macOS's textutil rather
than by this project, so it declares namespaces the corpus does not and binds
markup-compatibility to a different prefix. Everything else in the corpus was
generated here and so cannot surprise the reader.

## Running the tests

```
vendor/bin/pest                  # everything
vendor/bin/pest tests/Core       # only the framework-free half
```

`tests/Core` boots nothing and runs in about two seconds. Only `tests/Feature`
starts a Statamic through Testbench, which is the point of keeping the two
apart.

## The architecture boundary, and how it is actually enforced

`tests/ArchTest.php` carries two overlapping checks, on purpose.

`arch('core stays framework-agnostic')` is the form the project convention
asks for. It is worth less than it looks: Pest resolves a dependency by
matching it against the registered PSR-4 prefixes, so `toUse('Illuminate')`
matches nothing — the registered prefixes are `Illuminate\Support\` and its
siblings, and nothing is registered at `Illuminate` itself. The expectation
passes regardless of what the code does. `toUse('Statamic')` does work, because
statamic/cms registers exactly that prefix.

`it('never reaches for a framework')` reads the PHP tokens of every file in
`src/` and fails on any reference rooted at `Statamic`, `Illuminate`, `Laravel`
or `Orchestra`, ignoring comments and string literals. It does not care what is
installed or how it is autoloaded, which is what makes it hold here, where
neither framework is present at all.

The same limitation applies to the PDF library: `smalot/pdfparser` autoloads
via **psr-0**, so Pest's arch matchers cannot see it under any name. Its "only
one class may import `Smalot\`" boundary is a token scan for that reason, and
so is the matching one for `Symfony\Component\Process`.
