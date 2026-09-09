# Installing and configuring

```
composer require bpmore/statamic-a11y-docs
php please docs:install
php please vendor:publish --tag=a11y-docs
php please docs:check
```

`docs:install` creates three tables: `document_checks`, `document_findings` and
`document_exemptions`. By default they go in a SQLite file the addon creates for
itself under `storage/a11y-docs/`, so a flat-file site needs nothing else — most
Statamic sites have no database, and requiring one to try an addon is a reason
not to try it.

To keep them in a database the site already runs, name one of its connections:

```
A11Y_DOCS_CONNECTION=mysql
```

The command asks before creating tables on a connection the site administers.
`--force` skips the question for scripted installs.

`vendor:publish` copies the control panel assets. Without it the CP reports
"Vite manifest not found".

The first scan reads every document in every asset container; subsequent scans
read only what changed.

## Configuration

Publish the config if you want to change anything:

```
php please vendor:publish --tag=a11y-docs-config
```

Everything has a working default. The ones worth knowing about:

```php
'max_file_size' => 100 * 1024 * 1024,   // Above this, recorded as skipped

'scan' => [
    'chunk_size'  => 25,   // Documents per queued job
    'concurrency' => 3,    // Chunks running at once, across all workers
    'queue'       => null, // Which queue to dispatch onto
    'containers'  => null, // Which asset containers to scan; null for all
],

'gate' => [
    'enabled'            => true,
    'threshold'          => 'critical',  // Block on this and worse
    'grandfather'        => true,        // Leave the existing library alone
    'grandfather_before' => null,        // null = the moment of the first scan
],
```

Set the concurrency low if veraPDF is installed: each validation starts a JVM,
and fifty at once will take a server down more reliably than any document could.

## Showing status in the asset browser

Add one field to the asset container's blueprint. The addon does not do this for
you, because silently editing your blueprint on install is not a thing software
should do.

**Control panel** → **Assets** → your container → **Edit blueprint**, then add a
field of type **Document accessibility**. Mark it listable to show it as a
column in the browser.

Or in `resources/blueprints/assets/documents.yaml`:

```yaml
tabs:
  main:
    sections:
      -
        fields:
          -
            handle: accessibility
            field:
              type: a11y_document_status
              display: Accessibility
              listable: true
```

The field stores nothing. It reads the last check result, so what is on screen
is what was actually found rather than what somebody last remembered to save.

## Permissions

Three, under **Documents** in the role editor:

| Permission | Lets somebody |
|---|---|
| View document accessibility results | See the dashboard, the queue and each document's findings |
| Run document scans | Re-check a document after fixing it |
| Manage document exemptions | Decide a document will not be fixed, and say why |

The second and third are children of the first: running a scan without being
able to see the results is a button with no way to know what it did.

## Keeping it current

Queue a scan on a schedule:

```php
// routes/console.php
Schedule::command('docs:check')->daily();
```

Unchanged documents cost nothing — the check is a hash comparison — so a nightly
scan of a large library does very little work most nights.

Run `docs:prune` occasionally to drop results for documents that have been
deleted.
