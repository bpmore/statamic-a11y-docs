# Commands

## `docs:check`

Checks every document in the asset library.

```
php please docs:check                        # queue it
php please docs:check --sync                 # check now, printing as it goes
php please docs:check --force                # re-check everything, changed or not
php please docs:check --container=documents  # one container; repeatable
php please docs:check --sync --fail-on=critical
```

By default it queues the work as a batch and returns immediately. Chunks run
with a concurrency limit, and one unreadable document never takes down the run.

`--sync` does the work in the foreground and prints each result, ending with how
many documents were unchanged since the last run.

### The CI exit code

`--fail-on=<severity>` is the only thing that makes this command exit non-zero.

```
php please docs:check --sync --fail-on=critical
```

**Without it, the command always exits 0**, deliberately. A site installing this
addon has a backlog of hundreds of existing problems, and a command that reddens
their build on day one is a command they turn off on day one. Opt in when the
backlog is under control.

## `docs:report`

Shows what the last scan found. Runs nothing.

```
php please docs:report                       # tables
php please docs:report --json                # for scripting
php please docs:report --html=report.html    # accessible HTML
php please docs:report --pdf=report.pdf      # tagged PDF
```

The PDF needs a browser installed. Without one you get the HTML and a sentence
explaining why, not a failure. See [reports](reports.md).

## `docs:prune`

Removes stored results for documents that are no longer in the library.

```
php please docs:prune
php please docs:prune --dry-run
```

Each candidate is confirmed against the disk before anything is deleted, because
a stale container listing would otherwise remove the results for documents that
are still perfectly well there.

**Exemptions are never pruned**, only counted and reported. A trail that deletes
itself when the document goes is not an audit trail.
