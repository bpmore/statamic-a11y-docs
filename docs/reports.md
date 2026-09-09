# Reports and exports

```
php please docs:report --html=report.html --pdf=report.pdf
```

## The report is held to its own standard

A report about document accessibility that is itself an untagged PDF is the
screenshot that ends up on social media. So the exported PDF is checked by this
addon's own inspector, and by veraPDF, in the test suite — it is tagged, titled,
language-tagged, and **passes PDF/UA-1 validation**.

Two things were needed to get there, both of which will bite anybody printing
HTML to PDF with a browser:

- **Chrome writes no XMP metadata packet**, so its output is a properly tagged
  PDF that still fails PDF/UA clause 7.1-8. This addon adds one afterwards as an
  incremental update.
- **`<strong>` is not a standard PDF structure type.** Chrome maps it to
  `Strong` with no role map, failing clause 7.1-5. The report template uses a
  CSS class for emphasis instead.

## What is in it

The headline count, documents by format and outcome, findings by severity with
each severity spelled out in words, what is wrong most often, the documents
needing the most work, and **which engine produced the numbers** — because a
report that does not distinguish formal PDF/UA validation from a set of
heuristics is a report that overstates itself.

It also says what it does not cover: these findings describe documents, not the
website, and they are PDF/UA and Section 508 Chapter 5 rather than WCAG success
criteria.

## The PDF renderer

Headless Chrome, Chromium or Edge, found automatically in the usual places. To
point somewhere else:

```php
// config/a11y-docs.php
'report' => [
    'chrome' => '/usr/bin/chromium',
    'timeout' => 120,
],
```

No browser installed is not an error: you get the HTML.

## Inside A11y Report

When that addon is installed, these findings can appear in its conformance
report as a clearly labelled appendix, rather than being folded into the WCAG
criteria table — document conformance is PDF/UA and Section 508 Chapter 5
territory, and merging the two is a category error an auditor would catch.
