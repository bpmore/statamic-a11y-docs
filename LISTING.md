# Marketplace listing copy

Copy for the Statamic marketplace listing.

---

## Name

**A11y Docs**

## Price

$149 per site.

## One-liner

Find the documents in your asset library that nobody can read.

## Short description (marketplace card)

Checks every PDF, Word, PowerPoint and Excel file in your asset library for
accessibility problems, and stops new ones being published. No Java, no external
services, no setup.

## Long description

Every university, hospital and agency has thousands of PDFs nobody has ever
looked at. They are the largest accessibility liability on most sites, and the
first thing an external auditor counts — because they are the easiest thing to
count.

A11y Docs inventories them.

```
1,240 PDFs · 214 DOCXs · 890 untagged · 210 image-only scans
```

**36 checks across four formats.** PDFs that have no tags at all, scans that are
pictures of words, documents with no title or language, figures nobody
described, forms with unlabelled fields, encryption that locks out a screen
reader. Word, PowerPoint and Excel rules are grounded in Microsoft's own
Accessibility Checker, so a finding matches something the author can see and fix
in the tool they wrote it in.

**Everything is written for the person doing the work.** Findings, filters and
the dashboard say *Word · Alt text is filename*, not `docx.alt_text_is_filename`.
The exported report carries both, because an auditor needs to cite the rule as
well as read it.

**Installing it does not break your site.** The backlog you already have is
reported but never blocks publishing. Only new documents are gated, only on
critical problems, and anything can be exempted with a reason. This is the
decision the product lives or dies on and it ships the safe way round.

**It re-checks only what changed.** A library of 1,240 documents is not
reprocessed nightly.

**Reports say what they are.** Every result records which engine produced it, so
nothing claims formal PDF/UA validation it did not perform. The exported report
is itself a tagged PDF that passes PDF/UA validation — which, for a report about
document accessibility, seemed like the least it could do.

Optional: install veraPDF for authoritative PDF/UA-1 validation. **Most sites
should not.** The built-in checks need nothing installed and find what is
actually wrong.

### What it does not do

**It inventories and triages. It does not remediate.** Knowing you have 890
untagged PDFs and exactly which ones is the hard part. Fixing them is a
different job, and any tool claiming to do it automatically is worth a
suspicious look.

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

## Requirements

Statamic 6, PHP 8.2+, a database.

## Suite positioning

Third of three. Same vocabulary, same voice, same four severities.

- **A11y Gate** — free. Blocks entries with accessibility problems.
- **A11y Report** — proves the site, with a conformance report.
- **A11y Docs** — covers the asset library.

All three should surface on a marketplace search for "a11y" or "accessibility".

## Keywords

accessibility, a11y, pdf, pdf/ua, section 508, documents, assets, audit
