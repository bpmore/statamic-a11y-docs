<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Storage;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where this addon keeps its tables, and how they get there.
 *
 * One connection name, read from config, that every model and migration asks
 * this class for rather than reading config itself. The default is a SQLite
 * file the addon owns outright under storage/, because most Statamic sites are
 * flat-file and have no database at all. A site that runs one points
 * `a11y-docs.connection` at it and gets the same tables there.
 *
 * The migrations are deliberately NOT registered with `loadMigrationsFrom`.
 * Doing so would make `php please migrate` run them against the app's default
 * connection while `docs:install` runs them against this one, and the two would
 * then keep separate records of what has run. One path, one record.
 */
final class DocumentDatabase
{
    public const DEFAULT_CONNECTION = 'a11y_docs_sqlite';

    public function __construct(private readonly Application $app) {}

    public static function connectionName(): string
    {
        return (string) (config('a11y-docs.connection') ?: self::DEFAULT_CONNECTION);
    }

    /**
     * Whether the tables live in the addon's own file rather than a database
     * the site administers. Only then is creating them without being asked
     * safe: nobody else's schema is being touched.
     */
    public function ownsConnection(): bool
    {
        return self::connectionName() === self::DEFAULT_CONNECTION;
    }

    /**
     * Define the addon's own SQLite connection, unless the site has defined one
     * under that name itself. Called at boot, after the config is merged.
     */
    public function defineDefaultConnection(): void
    {
        $key = 'database.connections.'.self::DEFAULT_CONNECTION;

        if (config($key) !== null) {
            return;
        }

        config([$key => [
            'driver' => 'sqlite',
            'database' => $this->app->storagePath('a11y-docs/documents.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    public function isInstalled(): bool
    {
        try {
            return Schema::connection(self::connectionName())->hasTable('document_checks');
        } catch (\Throwable) {
            // A SQLite file that does not exist yet throws rather than saying
            // "no tables", and for this question those are the same answer.
            return false;
        }
    }

    /**
     * Migration files this addon ships that have not run on its connection.
     *
     * Asked before deciding an existing install needs nothing. A version that
     * adds a column ships a migration, and a command that returns early on
     * "the tables exist" would leave that column missing on every site that
     * installed an earlier version.
     *
     * @return list<string> basenames, in the order they would run
     */
    public function pendingMigrations(): array
    {
        $files = array_map('basename', glob($this->migrationPath().'/*.php') ?: []);

        try {
            $ran = Schema::connection(self::connectionName())->hasTable('migrations')
                ? DB::connection(self::connectionName())->table('migrations')->pluck('migration')->all()
                : [];
        } catch (\Throwable) {
            return $files;
        }

        return array_values(array_diff(
            array_map(fn (string $file): string => substr($file, 0, -4), $files),
            $ran,
        ));
    }

    /**
     * Create the tables, and whatever the batch runner needs for its own
     * records, on the connections they belong to.
     *
     * @return list<string> what was done, one line each, for a command to print
     */
    public function install(): array
    {
        $done = [];
        $connection = self::connectionName();

        if ($this->createSqliteFileIfMissing($connection)) {
            $done[] = "Created the database file for [{$connection}].";
        }

        Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => $this->migrationPath(),
            '--realpath' => true,
            '--force' => true,
        ]);

        $done[] = "Document tables are in place on [{$connection}].";

        // A scan runs as a job batch, and Laravel keeps batch state in a table
        // of its own on whatever connection `queue.batching.database` names. It
        // does not create that table itself. A flat-file site has usually never
        // run a migration, so the table is usually missing, and a scan that
        // died on the first line with "no such table: job_batches" is not zero
        // setup.
        $batching = (string) (config('queue.batching.database') ?: config('database.default'));
        $table = (string) config('queue.batching.table', 'job_batches');

        if ($this->createSqliteFileIfMissing($batching)) {
            $done[] = "Created the database file for [{$batching}], which the queue keeps batch records in.";
        }

        if (Schema::connection($batching)->hasTable($table)) {
            return $done;
        }

        // Prefer the site's own migration where it has one and the batching
        // connection is the default that migration writes to, so a later
        // `php artisan migrate` does not meet a job_batches it has no record of
        // and stop with "table already exists".
        $migration = $this->appMigrationCreating($table);

        if ($migration !== null && $batching === (string) config('database.default')) {
            Artisan::call('migrate', [
                '--path' => $migration,
                '--realpath' => true,
                '--force' => true,
            ]);

            $done[] = "Ran the site's own ".basename($migration)." to create [{$table}] on [{$batching}].";

            return $done;
        }

        Schema::connection($batching)->create($table, function ($t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->integer('total_jobs');
            $t->integer('pending_jobs');
            $t->integer('failed_jobs');
            $t->longText('failed_job_ids');
            $t->mediumText('options')->nullable();
            $t->integer('cancelled_at')->nullable();
            $t->integer('created_at');
            $t->integer('finished_at')->nullable();
        });

        $done[] = "Created the [{$table}] table on [{$batching}] for the queue's batch records.";

        return $done;
    }

    private function migrationPath(): string
    {
        return (string) realpath(__DIR__.'/../../database/migrations');
    }

    /**
     * The site's own migration that creates the given table, if it has one.
     * Laravel's stock jobs migration creates `job_batches` alongside `jobs`, so
     * the file is found by reading rather than by name.
     */
    private function appMigrationCreating(string $table): ?string
    {
        $directory = $this->app->databasePath('migrations');

        if (! is_dir($directory)) {
            return null;
        }

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), "Schema::create('{$table}'")) {
                return $file;
            }
        }

        return null;
    }

    private function createSqliteFileIfMissing(string $connection): bool
    {
        $config = (array) config("database.connections.{$connection}", []);

        if (($config['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        $path = (string) ($config['database'] ?? '');

        if ($path === '' || $path === ':memory:' || file_exists($path)) {
            return false;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);

            // A directory this addon created is this addon's to keep out of the
            // site's repository. Without this, `git status` on a site that has
            // run a scan lists storage/a11y-docs/ as untracked, and the next
            // `git add -A` commits the results database. Written only when the
            // directory did not exist: one that was already there belongs to
            // the site, and its ignore rules are the site's business.
            file_put_contents(dirname($path).'/.gitignore', "*\n!.gitignore\n");
        }

        touch($path);

        return true;
    }
}
