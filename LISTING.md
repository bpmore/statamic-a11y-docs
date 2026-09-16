# Marketplace listing copy

Copy for the Statamic marketplace listing.

---

## Name

**A11y Docs**

Marketplace listing name, following the pattern the other two use
(`A11y <Product>: <descriptor> for Statamic`):

> **A11y Docs: PDF and Document Accessibility for Statamic**

The subtitle carries "PDF" deliberately. It is the word people search, it is the
liability that sells this, and it is the one term that separates this listing
from the other two — neither of which mentions it. "Docs" on its own does not
tell anyone this handles PDFs.

## Price

$99 per site.

## One-liner

Find the documents in your asset library with accessibility problems.

## Summary (marketplace card)

Finds every PDF, Word, PowerPoint and Excel file with accessibility problems,
and blocks new ones.

96 characters. A11y Report and A11y Gate run 69 and 74, so a card summary is one
short sentence; the earlier 176-character version would have truncated.

Not "nobody can read", which is false for most findings - a missing title is
serious, not unreadable. Not "is not accessible" either: the licence is explicit
that this cannot judge a document accessible, only report problems it finds.

## Long description

Every university, hospital and agency has thousands of PDFs and Office documents
in its asset library, and no record of which of them are accessible. They are
the largest accessibility liability on most sites, and the first thing an
external auditor counts — because they are the easiest thing to count.

A11y Docs inventories them.

```
1,240 PDFs · 214 DOCXs · 890 untagged · 210 image-only scans
```

**36 checks across four formats.** PDFs that have no tags at all, scans that are
pictures of words, documents with no title or language, figures with no
alternative text, forms with unlabelled fields, encryption that locks out a
screen reader. Word, PowerPoint and Excel rules are grounded in Microsoft's own
Accessibility Checker, so a finding matches something the author can see and fix
in the tool they wrote it in.

**Everything is written for the person doing the work.** Findings, filters and
the dashboard say *Word · Alt text is filename*, not `docx.alt_text_is_filename`.
The exported report carries both, because an auditor needs to cite the rule as
well as read it.

**Installing it does not break your site.** The backlog you already have is
reported but never blocks publishing. Only new documents are gated, only on
critical problems, and anything can be exempted with a reason. Installing this
changes nothing about what you can publish today.

**It re-checks only what changed.** A library of 1,240 documents is not
reprocessed nightly.

**Reports say what they are.** Every result records which engine produced it, so
nothing claims formal PDF/UA validation it did not perform. The exported report
is itself a tagged PDF that passes PDF/UA validation.

**It shows on the dashboard.** With the free Site Weather addon installed, the
library's state is one band on the dashboard tile, worst first, from clear to
storm, and clicks through to the remediation queue.

Optional: install veraPDF for authoritative PDF/UA-1 validation. **Most sites
should not.** The built-in checks need nothing installed and find the problems
that are actually in your library.

### What it does not do

**It inventories and triages. It does not remediate.** Knowing you have 890
untagged PDFs and exactly which ones is the hard part. Fixing them is a
separate job, done in Acrobat or in the tool the document was written in.

## Features list

- 36 checks across PDF, DOCX, PPTX and XLSX
- A status column in the asset browser: green for *No problems found*, amber and
  red for what needs work, grey for *Format not checked* and for files that
  could not be read
- Dashboard and remediation queue, filterable by rule, severity, format and
  container — rules named in plain English throughout, so a filter reads
  *Word · Alt text is filename* rather than an id
- Publish gate — critical only, existing library grandfathered, exemptions with
  reasons. A blocked entry is told which document is at fault, what is wrong
  with it, and ends *"Fix it, or exempt it with a reason, then publish."*
- `docs:check`, `docs:report`, `docs:prune`, with a CI exit code
- Accessible HTML and tagged PDF exports
- Queued scanning with concurrency limits
- Optional PDF/UA validation via veraPDF
- Feeds A11y Report's conformance report as its own appendix
- Feeds the free Site Weather dashboard tile with a Documents band, clear
  through storm, read from the same numbers as the dashboard

## Requirements

Statamic 6, PHP 8.2+, a database.

## Suite positioning

Third of three. Same vocabulary, same voice, same four severities.

- **A11y Gate** — free. Blocks entries with accessibility problems.
- **A11y Report** — proves the site, with a conformance report.
- **A11y Docs** — covers the asset library.

All three should surface on a marketplace search for "a11y" or "accessibility".

**Site Weather** is a free companion, not a fourth product: one dashboard tile
that shows a band per addon that reports to it. Docs reports a Documents band.
The listing mentions it once, in the description and the features, and links
to its own listing once that exists.

## Categories

In order; the marketplace treats the first as primary.

| | |
|---|---|
| **Utility** | Primary, matching A11y Gate and A11y Report. All three lead with it, so browsing Utility shows the suite together. |
| **Assets** | The one category that separates this from the other two: Gate and Report work on entries and pages, this works on the asset library. Neither sibling can claim it. |
| **CLI** | `docs:check`, `docs:report` and `docs:prune`, with a CI exit code. Both siblings use it too. |
| **Fieldtype** | The addon registers one — `a11y_document_status` — and installation has the user add it to the asset blueprint by hand, so they meet it as a fieldtype. Same as Gate. |

Deliberately not **Analytics**, which A11y Report uses: on a CMS marketplace that
category means traffic and behaviour tools, and someone arriving from it wants
Fathom, not a document inventory. Not **Widget** or **Tag** either — this addon
registers neither.

There is no Accessibility category on the marketplace. The suite is held
together by the shared "A11y" name and the keywords below, not by a category.

## Keywords

accessibility, a11y, pdf, pdf/ua, section 508, documents, assets, audit

## Screenshots

Six on the listing. After 1.4.0, retake any that show:

- the **dashboard**: titled "Document checks", and every table has a header row;
- the **remediation queue**: labelled filters, header row, pagination under the
  table.

Add one of the **Site Weather tile** with the Documents band, since it is the
release's headline feature.
