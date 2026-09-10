# A11y Docs

Document accessibility checking for the Statamic asset library: PDF, Word, PowerPoint and Excel.

It inventories and triages. It does not remediate.

## Installing

Require the package, create the tables, and take a first look:

- `composer require bpmore/statamic-a11y-docs`
- `php please docs:install`
- `php please vendor:publish --tag=a11y-docs`
- `php please docs:check`

`docs:install` creates three tables: `document_checks`, `document_findings` and `document_exemptions`. By default they go in a SQLite file the addon creates for itself under `storage/a11y-docs/`, so a flat-file site needs nothing else. To put them in a database the site already runs, set `A11Y_DOCS_CONNECTION` to one of its connection names, or set `connection` in the config, and run the command again.

Run `docs:install` again after upgrading the package. It reports "already installed" when there is nothing to do, and applies any table changes a newer version needs.

The first scan reads every document in every asset container. Subsequent scans read only what changed, because the check is a hash comparison.

## Configuration

Publish the config with `php please vendor:publish --tag=a11y-docs-config`. Everything has a working default; these are the settings worth knowing about.

**`max_file_size`** - 100 MB by default. Above this a document is recorded as *skipped*, with the reason visible, rather than silently ignored.

**Scanning**, under `scan`:

- `chunk_size` - documents per queued job. 25 by default.
- `concurrency` - chunks running at once across all workers. 3 by default.
- `queue` - which queue to dispatch onto. `null` for the default.
- `containers` - which asset containers to scan. `null` for all of them.

Set the concurrency low if veraPDF is installed. Each validation starts a JVM, and fifty at once will take a server down more reliably than any document could.

**The publish gate**, under `gate`:

- `enabled` - on by default.
- `threshold` - block on this severity and worse. `critical` by default.
- `grandfather` - leave the existing library alone. `true` by default.
- `grandfather_before` - where to draw that line. `null` means the moment of the first scan.

## Showing status in the asset browser

Add one field to the asset container's blueprint. The addon does not do this for you. It does not edit your blueprint on install.

In the control panel: **Assets** > your container > **Edit blueprint**, then add a field of type **Document accessibility**. Mark it listable to show it as a column in the browser.

In YAML, the field's handle is yours to choose and its type is `a11y_document_status`. Set `display` to *Accessibility* and `listable` to `true`.

The field stores nothing. It reads the last check result, so what is on screen is what was actually found rather than what somebody last remembered to save.

Four states appear in that column:

- **Green** - *No problems found*
- **Amber** - problems worth fixing, with a count and the worst severity
- **Red** - critical problems, meaning somebody cannot read the document at all
- **Grey** - *Format not checked* for legacy formats, or *Could not be read* for an encrypted or corrupt file

Grey never means "fine". It means nothing was looked at.

## Permissions

Three, under **Documents** in the role editor:

- **View document accessibility results** - see the dashboard, the queue, and each document's findings
- **Run document scans** - re-check a document after fixing it
- **Manage document exemptions** - decide a document will not be fixed, and say why

The second and third are children of the first. Running a scan without being able to see the results is a button with no way to know what it did.

## Commands

### docs:check

Checks every document in the asset library.

- `php please docs:check` - queue it
- `php please docs:check --sync` - check now, printing each result as it goes
- `php please docs:check --force` - re-check everything, changed or not
- `php please docs:check --container=documents` - one container; repeatable
- `php please docs:check --sync --fail-on=critical` - exit non-zero on a critical finding

By default it queues the work as a batch and returns immediately. Chunks run with a concurrency limit, and one unreadable document never takes down the run. `--sync` does the work in the foreground and ends by reporting how many documents were unchanged since the last run.

**The CI exit code.** `--fail-on=<severity>` is the only thing that makes this command exit non-zero. Without it the command always exits 0, deliberately: a site installing this addon has a backlog of hundreds of existing problems, and failing the build on day one is why the flag is opt-in. Turn it on once the backlog is under control.

### docs:report

Shows what the last scan found. Runs nothing.

- `php please docs:report` - printed summary
- `php please docs:report --json` - for scripting
- `php please docs:report --html=report.html` - accessible HTML
- `php please docs:report --pdf=report.pdf` - tagged PDF

The PDF needs a browser installed. Without one you get the HTML and a sentence explaining why, not a failure.
**A snap-confined browser cannot write outside your home directory.** Ubuntu ships Chromium as a snap, which is what Laravel Forge installs. It exits cleanly and reports the bytes it wrote, to a private path nothing else can see, so `--pdf=/tmp/report.pdf` produces no file and no obvious error. Write to a path inside the site instead:

- `php please docs:report --pdf=storage/report.pdf`


### docs:prune

Removes stored results for documents that are no longer in the library.

- `php please docs:prune`
- `php please docs:prune --dry-run`

Each candidate is confirmed against the disk before anything is deleted, because a stale container listing would otherwise remove the results for documents that are still present.

**Exemptions are never pruned**, only counted and reported. A trail that deletes itself when the document goes is not an audit trail.

## The publish gate

An entry that links to a document with critical accessibility problems can be stopped from being published.

The thing to understand about the defaults is that a site installing this addon already has a backlog - often hundreds of bad PDFs uploaded years ago. A gate that blocks all of it on day one makes the site unpublishable.

So three protections ship on, and together they mean **installing this addon changes nothing about what you can publish today**.

**1. The existing library is left alone.** Documents whose file predates the first scan are reported everywhere and block nothing. Replacing a grandfathered document with a new one gates it - the reprieve is for the backlog, not for the filename.

**2. Only critical problems block.** Critical means somebody genuinely cannot read the document: no tags at all, a scan with no text layer, encryption that blocks assistive technology. A document missing a language declaration is *serious*, and does not block.

**3. Exemptions.** Any document can be exempted, with a required reason and an optional expiry. An exemption without a reason is a way of turning the addon off one file at a time, so the reason is required and has to say something. Exempt from the asset browser or the remediation queue, singly or in bulk, with **Exempt from accessibility checks**. To end one, use **Withdraw accessibility exemption** on the same document; it appears only where there is an exemption to withdraw, and the document is gated again from that point. Exemptions are append-only: withdrawing one records who ended it and when rather than deleting the record, because that is the difference between an audit trail and a list.

**What a blocked publish looks like.** The save is refused with the reason attached - which documents, what is wrong with them, and the way out:

> This entry links to 2 documents that people using a screen reader cannot read. **reports/handbook.pdf** - This PDF has no tags. A screen reader cannot tell a heading from body text... Fix them, or exempt them with a reason, then publish.

"Blocked" with no route forward is how a gate becomes a thing people disable.

**Drafts are never gated.** Somebody's work in progress is not what this protects.

Turn the gate off entirely by setting `gate.enabled` to `false`. The dashboard and the queue carry on working. The gate protects the future; the dashboard describes the past.

## Reports and exports

**The report is held to its own standard.** The exported PDF is checked by this addon's own inspector, and by veraPDF, in the test suite. It is tagged, titled, language-tagged, and passes PDF/UA-1 validation.

Two things were needed to get there, both of which will bite anybody printing HTML to PDF with a browser:

- **Chrome writes no XMP metadata packet**, so its output is a properly tagged PDF that still fails PDF/UA clause 7.1-8. This addon adds one afterwards as an incremental update.
- **`<strong>` is not a standard PDF structure type.** Chrome maps it to *Strong* with no role map, failing clause 7.1-5. The report template uses a CSS class for emphasis instead.

**What is in it:** the headline count, documents by format and outcome, findings by severity with each severity spelled out in words, what is wrong most often, the documents needing the most work, and which engine produced the numbers - because a report that does not distinguish formal PDF/UA validation from a set of heuristics is a report that overstates itself.

It also says what it does not cover: these findings describe documents, not the website, and they are PDF/UA and Section 508 Chapter 5 rather than WCAG success criteria.

**The PDF renderer** is headless Chrome, Chromium or Edge, found automatically in the usual places. Point somewhere else with `report.chrome`, and adjust `report.timeout` if needed. No browser installed is not an error: you get the HTML.

## Do I need veraPDF?

**Almost certainly not.** This section exists so that the answer is "no" before anybody has installed a Java runtime to find out.

The built-in checks need nothing installed, take about a second per document, and find the problems actually in a real library: files with no tags at all, scans that are pictures of words, documents with no title or language, figures with no alternative text, forms whose fields are unlabelled, encryption that blocks a screen reader outright.

veraPDF needs a Java runtime, takes about a second and a half per document plus a JVM start, and answers a stricter question: formal PDF/UA-1 conformance. A document can fail that on details - a missing metadata packet, a font that is not embedded - while being perfectly readable.

**Install it if** you need to tell somebody "these documents conform to PDF/UA-1" and expect to be checked. Otherwise do not.

**What changes if you install it.** Both engines run. veraPDF does not replace the built-in checks, because PDF/UA has no requirement about bookmarks in a long document and none about a scan with no text layer - so replacing one with the other would delete "210 image-only scans" from your dashboard the day you installed Java. Findings from both appear together, seven PDF/UA clauses are reported under the rule names you already know, and everything else appears under its ISO clause. The engine recorded against each document becomes `heuristics+verapdf`, and every report says so. Installing it also re-checks the library, because results produced by the weaker engine should not be presented as though the stronger one had seen them.

Once `verapdf` is on the `PATH`, this addon finds it, and `php please docs:check --force` re-reads the library. To point at a binary elsewhere, or to turn it off on a server that has it, use the `verapdf` settings: `enabled`, `binary`, `timeout` and `max_bytes`.

**If it is not installed, nothing happens.** The absence is detected once, the built-in checks run as normal, and every report says `heuristics` rather than claiming validation it did not perform. That is the intended configuration for most sites.

**If it times out, or the document is too large**, the document is still checked by the built-in rules and the result records that PDF/UA validation did not complete - because a site that configured validation and quietly did not get it should be told.

## What it checks

36 rules across four formats. PDF rules come from PDF/UA and from what actually goes wrong in a real library. Word, PowerPoint and Excel rules are grounded in Microsoft's own Accessibility Checker, so a finding matches something the author can see and fix in the tool they wrote the document in.

Four severities, the same four A11y Report uses:

- **Critical** - somebody cannot read the document at all
- **Serious** - a real barrier, though it can be worked around
- **Moderate** - makes the document harder to use than it needs to be
- **Minor** - worth fixing; blocks nobody

The full reference, with every rule, its severity, what Microsoft calls it and an example file, is generated from the rule definitions themselves and lives at [docs/rules.md](https://github.com/bpmore/statamic-a11y-docs/blob/main/docs/rules.md).

## Keeping it current

Schedule a scan in `routes/console.php` with `Schedule::command('docs:check')->daily()`. Unchanged documents cost nothing, so a nightly scan of a large library does very little work most nights.

Run `docs:prune` occasionally to drop results for documents that have been deleted.

## Alongside A11y Gate

The gate reads the page's rendered HTML. It cannot open a linked PDF, so an entry that links to an untagged document reads as passing there while this addon refuses the same save.

When A11y Gate is installed, this addon adds a block to its entry panel listing the documents that page links to and what is wrong with them, so both addons say the same thing on the same screen. Nothing is registered when the gate is absent.

## Alongside A11y Report

When that addon is installed, these findings can appear in its conformance report as a clearly labelled appendix, rather than being folded into the WCAG criteria table - document conformance is PDF/UA and Section 508 Chapter 5 territory, and merging the two is a category error an auditor would catch.
