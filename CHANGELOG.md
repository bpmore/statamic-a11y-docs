# Changelog

What changed in each release, and what you have to do about it.

**Whether upgrading re-reads your library** is stated on every entry, because a
scan of a large library is not free. The answer depends on the inspection
version in `core/Version.php`, which is part of a check's cache validity and is
not the package version: it moves only when a rule would find something
different. Most releases here do not move it, and upgrading those re-checks
nothing.

Versions are `MAJOR.MINOR.PATCH`.

## 1.4.0 - 2026-09-16

### Added, for the dashboard

**A Documents band on Site Weather's tile**, when the free Site Weather addon
is installed. The band reads the numbers the dashboard already draws and turns
them into weather: clear when nothing fails, through fair, overcast and rain,
to storm when a tenth of the library or more has critical problems. Installed
but never set up, or nothing checked yet, reads as *unknown* — never as clear.
Clicking the band opens this addon's dashboard.

Site Weather is suggested, not required; without it nothing changes. Upgrading
re-reads nothing.

### Fixed, in the control panel

**The remediation queue can reach page two.** It has always been fifty
findings a page, and a library with 890 untagged PDFs has far more than
fifty, but the screen drew no way to the rest: the only route to page two was
typing `?page=2` into the address bar. Statamic's own pagination now sits
under the table, and the filters survive the page change.

**Every table has a header row.** The queue and the four dashboard tables had
none, so a screen reader read four unlabelled columns, on the one screen in
the control panel that exists to report exactly that. Each column is now a
proper `<th scope="col">`, as the exported report's tables already were.

**The queue's filters carry visible labels.** "Any severity" said what a filter
was until something was chosen, and then "critical" sat in a box with nothing
to say it was the severity.

**The dashboard is titled "Document checks"**, the same words as the nav item
that got somebody there. It kept saying "Documents" after 1.3.2 renamed the
item.

Upgrading re-checks nothing.

## 1.3.2 - 2026-09-15

### Changed, in the control panel only

**The nav item is "Document checks", under Tools.** It was "Documents" under
Content, which read as a place to manage documents and sat next to Assets,
where the documents actually live. Nothing on these pages creates or edits a
document; they check them, so they sit with the other checks. The permission
group in the role editor is renamed to match. Permissions themselves are
unchanged, so nobody loses access.

Upgrading re-checks nothing.

## 1.3.1 - 2026-09-14

### Fixed, where a save fell over

**Saving an entry that links to another entry no longer fails with a
`TypeError`.** A Bard link mark, a Link field or a `related` field stores
`statamic://entry::<id>`, and `parse_url()` calls that malformed, returning
`false` rather than `null`. The gate's document check only guarded against
`null`, so `false` reached `pathinfo()` and every save of a page with an
internal link was a 500 while the gate was on. Found by the greenhouse seeder.

The entry link is not a document and never was; it is simply skipped now, and
a document linked in the same paragraph is still found.

Upgrading re-checks nothing.

## 1.3.0 - 2026-09-10

### Fixed, where the refusal was in the wrong place

**A refused publish is now drawn in the gate's panel, not under the title.**
Statamic renders a validation error next to the blueprint field it is keyed to
and drops it silently otherwise, so the refusal had to name a field, and "this
entry links to 1 document that people using a screen reader cannot read" then
appeared under the Title input and read as a fault in the title. Reported from a
live site.

A11y Gate 0.9 lets a panel block name a `refusalKey` and draws whatever it finds
under it, so the refusal now appears in the block that already lists the
documents on that page. It falls back to keying a field in two cases, because a
refusal nobody sees is worse than one in the wrong place: an A11y Gate older
than 0.9, asked through `PanelExtensions::supports()`, and a blueprint without
the panel field on it.

Needs A11y Gate 0.9 or newer for the new placement. Nothing else changes, and
the gate is optional as before. Upgrading re-checks nothing.

## 1.2.1 - 2026-09-10

### Added, because the documentation promised it

**Withdraw accessibility exemption**, on an asset, one or many. The
documentation described withdrawal from the first release and there was no way
to do it: the column existed, `active()` filtered on it, and nothing in the
control panel ever set it. An exemption was permanent once granted.

The row is not deleted. It records who ended it and when, and an
already-withdrawn exemption is left alone rather than re-stamped, so the time
in the record is the time somebody decided. The action appears only where there
is an active exemption to withdraw.

### Fixed, where an upgrade did not arrive

**`docs:install` now applies migrations added after the tables were created.**
It returned early on "the tables exist", so a column added in a later release
never reached a site that installed an earlier one. Run it after upgrading; it
reports "already installed" when there is nothing to do.

Upgrading re-checks nothing.

## 1.2.0 - 2026-09-10

### Added

**Linked documents are reported inside A11y Gate's entry panel.** The gate reads
a page's rendered HTML and cannot open a linked PDF, so an entry linking to an
untagged document read as passing there while this addon refused the same save.
Two addons giving an author opposite answers on one screen is worse than either
being absent.

The block lists the documents an entry links to, names what is wrong in the same
words the dashboard and the queue use, and links to the remediation queue. Its
tone is error only when a finding is critical, so its colour means what the
gate's does. It says so when the documents have never been checked, because
silence there reads as "checked, and fine".

Registered only when A11y Gate is installed. Upgrading re-checks nothing.

## 1.1.4 - 2026-09-10

### Fixed, where the addon was saying something untrue

**`docs:report --pdf` explains why a snap-confined browser produces no file.**
Ubuntu ships Chromium as a snap, which is what Laravel Forge installs, and a
snap gets a private `/tmp` and cannot write outside the user's home directory.
It exits cleanly and reports the bytes it wrote, to a path nothing else can see,
so the export failed with an error that read as though the browser had crashed.

Measured on a Forge server: `--pdf=/tmp/report.pdf` produced no file, and the
same run with a destination inside the site produced a valid 17728-byte tagged
PDF.

The error now says the browser exited cleanly but wrote nothing to that path,
and where the binary looks snap-confined and the destination is outside the home
directory it names one that works. Write to `storage/report.pdf` rather than
`/tmp`.

Upgrading re-checks nothing.

## 1.1.3 - 2026-09-10

### Fixed

**`docs:report --pdf` no longer reports failure while the file is being
written.** The check clears PHP's stat cache and retries for up to a second
before giving up, and includes the process exit code so a real failure is still
diagnosable.

This did not find the cause. 1.1.4 did.

Upgrading re-checks nothing.

## 1.1.2 - 2026-09-10

### Fixed, where the documentation could not be followed

**The Document accessibility fieldtype is selectable.** It shipped hidden from
the field picker, so the install the documentation describes could not be
completed: it says to add a field of type "Document accessibility" to the asset
blueprint, and that option was not there. The status column could only be turned
on by editing YAML by hand.

The fieldtype now carries a title, an icon and a category. It stays out of a
form's field picker, where it has no meaning.

Found by installing on a real site and following the instructions as written.
Upgrading re-checks nothing.

## 1.1.1 - 2026-09-09

### Fixed, and it took the control panel down

**A 500 on the control panel login page.** Forge and similar deploys cache
routes. A cache built before this addon was installed contains none of its
control panel routes, and the navigation item is registered on every control
panel request including login, so it asked for a route that was not there:

```
production.ERROR: Route [statamic.cp.a11y-docs.dashboard] not defined.
```

The whole control panel returned 500, and removing the addon did not clear it
because the route cache still had to be rebuilt.

The nav item is now skipped when its route is missing. A missing menu entry is
visible and harmless; a 500 on login is neither. If you hit this, run `php
artisan optimize:clear` after installing.

Upgrading re-checks nothing.

## 1.1.0 - 2026-09-09

### Changed, and the install steps are different

**Results go in the addon's own SQLite file by default, so a flat-file Statamic
site needs no database.** Most Statamic sites are flat-file, and requiring one
before somebody can try an addon is a reason not to try it.

Install is now:

```
composer require bpmore/statamic-a11y-docs
php please docs:install
php please vendor:publish --tag=a11y-docs
php please docs:check
```

`docs:install` replaces `php please migrate`. It creates the three tables on the
configured connection and provisions `job_batches`, which Laravel needs for a
batched scan and does not create itself.

To keep the tables in a database the site already runs, set
`A11Y_DOCS_CONNECTION` to one of its connection names, or set `connection` in
the config, and run the command again. The command asks before creating tables
on a connection the site administers.

Upgrading re-checks nothing, but results already stored in a site's own database
stay there. Point `connection` at it to keep reading them.

## 1.0.5 - 2026-09-09

### Fixed, in the package rather than the code

The 1.0.4 tag on the public repository was cut at its first commit, before the
licence and package metadata existed, so Packagist was serving a release with no
LICENSE.md, no homepage or support keys, and a `wcag` keyword the rest of the
project contradicts. This release carries all of it.

No code changed. Upgrading re-checks nothing.

## 1.0.4 - 2026-09-09

### Fixed, both found by looking at the screen

**Every badge in the addon rendered grey**, including "No problems found" and
"2 problems · critical". `Badge`'s prop is `color`; the addon passed `variant`,
which is `Button`'s, and `Badge` sets `inheritAttrs: false`, so it was discarded
with no error.

**The publish gate blocked silently.** Clicking Save & Publish on an entry
linking to a failing document did nothing visible: no message, no toast, no
console error. The gate worked, and Statamic only renders a validation error
next to the blueprint field it is keyed to. The message was keyed to
`published`, a sidebar toggle. It is now keyed to a field the control panel will
show.

Upgrading re-checks nothing.

## 1.0.3 - 2026-09-09

### Fixed, in the control panel

**Table rows sat 18px left of the heading above them**, in every panel on both
pages. Statamic's `PanelHeader` is `px-4.5` and its table cells carry no
horizontal padding. The cells now carry it as an inline style: a utility class
loses to the descendant selector Statamic styles cells with, and a class this
addon invents may not be in the built CSS at all, since Tailwind 4 generates
utilities by scanning source and Statamic scans its own.

**The navigation icon was the one Statamic's own Assets item uses**, so the
sidebar showed the same picture twice. It is now `file-content-list`.

Upgrading re-checks nothing.

## 1.0.2 - 2026-09-09

### Fixed, in the listing rather than the product

The marketplace copy quoted the product in five places and was wrong in two, and
claimed three badge states where there are four. A test now derives every quoted
string from the code that produces it, so the listing cannot drift again without
failing.

Documentation only. Upgrading re-checks nothing.

## 1.0.1 - 2026-09-09

### Fixed, in the two places a person actually reads

**The publish gate ran two findings together into one unreadable clause**, in
the one message whose whole job is to be read. It now reads as sentences.

**The exported report printed raw rule ids.** It shows the rule in words with
the id beside it, so a reader knows what the problem is and an auditor can still
cite and trace it. A11y Report receives `rule_label` alongside `rule`.

Upgrading re-checks nothing.

## 1.0.0 - 2026-09-09

First release.

Inventories and triages accessibility problems in a Statamic asset library: PDF,
Word, PowerPoint and Excel. 36 rules, grounded in PDF/UA and in Microsoft's own
Accessibility Checker, so a finding matches something the author can see and fix
in the tool they wrote the document in. veraPDF is optional and authoritative
when present; the engine that produced every result is recorded and shown.

It inventories and triages. It does not remediate.

The publish gate grandfathers existing documents by default, so installing on a
library with a thousand pre-existing problems does not stop anybody publishing
today.

The first `docs:check` reads the whole library. Later ones read only what
changed.
