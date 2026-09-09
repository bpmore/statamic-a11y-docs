# A11y Docs

**Document accessibility checking for the Statamic asset library.** PDF, Word,
PowerPoint and Excel.

Every university, hospital and agency has thousands of PDFs and Office documents
in its asset library, and no record of which of them are accessible. It is the
first thing an external auditor counts, because it is the easiest thing to
count. This tells you what is in there.

```
php please docs:check
```

```
1,240 PDFs · 214 DOCX · 890 untagged · 210 image-only scans
```

**It inventories and triages. It does not remediate.** Knowing that you have 890
untagged PDFs and exactly which ones is the hard part; fixing them is a
different job.

## What it does

- **Checks 36 rules** across PDF, Word, PowerPoint and Excel — see
  [what it checks](docs/rules.md).
- **Re-checks only what changed.** A library of 1,240 documents is not
  reprocessed nightly.
- **Stops new bad documents being published**, without blocking the ones that
  were already there. See [the publish gate](docs/the-gate.md).
- **Reports**, on screen, on the command line, and as an accessible HTML or
  tagged PDF export — which [passes PDF/UA validation itself](docs/reports.md).

## Installing

```
composer require bpmore/statamic-a11y-docs
php please migrate
php please docs:check
```

That is the whole installation. There is no Java to install, no binary to
configure, and nothing to sign up for.

To show a status badge in the asset browser, add one field to the container's
asset blueprint — [how](docs/installation.md#showing-status-in-the-asset-browser).

## Do I need veraPDF?

**Almost certainly not.** [The long answer](docs/verapdf.md), the short one:

The built-in checks are pure PHP, run everywhere, and find the problems that are
actually in your library — untagged files, scans with no text layer, missing
titles and languages, undescribed figures. veraPDF is a Java program that
performs formal PDF/UA validation. It is more authoritative and it is more
work to install.

Install it if you have to certify PDF/UA conformance to somebody who will check.
Otherwise skip it. Every report says which engine produced it, so nothing
overstates itself either way.

## The rest of the line

- **A11y Gate** — free, blocks entries with accessibility problems.
- **A11y Report** — proves the site, with a conformance report.
- **A11y Docs** — this one, covers the asset library.

When A11y Report is installed too, document findings appear in its conformance
report **as their own appendix** — never folded into the WCAG table, because
document conformance is PDF/UA and Section 508 Chapter 5 territory and
conflating the two is a category error an auditor will catch.

## Documentation

| | |
|---|---|
| [Installing and configuring](docs/installation.md) | Including the asset-browser badge |
| [What it checks](docs/rules.md) | All 36 rules, generated from the code |
| [Commands](docs/commands.md) | `docs:check`, `docs:report`, `docs:prune` |
| [The publish gate](docs/the-gate.md) | Grandfathering, thresholds, exemptions |
| [Reports and exports](docs/reports.md) | HTML and tagged PDF |
| [Do I need veraPDF?](docs/verapdf.md) | Short answer: no |

## Requirements

PHP 8.2+, Statamic 6, and a database. veraPDF and a browser (for PDF exports)
are both optional and both degrade to a clear message rather than a failure.

## Licence

Proprietary. One licence per production site; local development and CI are free.
The source is published so it can be installed with Composer and inspected by
the people who rely on it. See [LICENCE](LICENSE.md) — in particular what it
says about the difference between a heuristic finding and PDF/UA validation.
