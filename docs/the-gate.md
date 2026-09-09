# The publish gate

An entry that links to a document nobody can read can be stopped from being
published.

**The thing to understand about the defaults** is that a site installing this
addon already has a backlog — often hundreds of bad PDFs uploaded years ago. A
gate that blocks all of it on day one makes the site unpublishable, and that is
not a bug report, it is an uninstall.

So three protections ship on, and together they mean **installing this addon
changes nothing about what you can publish today**:

## 1. The existing library is left alone

Documents whose file predates the first scan are reported everywhere and block
nothing. The line is drawn at the moment the addon first checked anything, or
wherever you set it:

```php
'gate' => [
    'grandfather' => true,
    'grandfather_before' => null,   // or '2026-09-01'
],
```

Replacing a grandfathered document with a new one gates it. The reprieve is for
the backlog, not for the filename.

## 2. Only critical problems block

```php
'threshold' => 'critical',
```

Critical means somebody genuinely cannot read the document — no tags at all, a
scan with no text layer, encryption that blocks assistive technology. A document
missing a language declaration is *serious* and does not block.

## 3. Exemptions

Any document can be exempted, with a **required reason** and an optional expiry.
An exemption without a reason is a way of turning the addon off one file at a
time, so the reason is required and has to say something.

Exempt from the asset browser or the remediation queue, singly or in bulk.
Exemptions are append-only: withdrawing one records that it was withdrawn rather
than deleting the record, because that is the difference between an audit trail
and a list.

## What a blocked publish looks like

The save is refused with the reason attached — which documents, what is wrong
with them, and the way out:

> This entry links to 2 documents that people using a screen reader cannot read:
> **reports/handbook.pdf** — This PDF has no tags. A screen reader cannot tell a
> heading from body text…
> Fix them, or exempt them with a reason, then publish.

"Blocked" with no route forward is how a gate becomes a thing people disable.

**Drafts are never gated.** Somebody's work in progress is not what this
protects.

## Turning it off

```php
'gate' => ['enabled' => false],
```

The dashboard and the queue carry on working. The gate protects the future; the
dashboard describes the past.
