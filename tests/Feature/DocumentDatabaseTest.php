<?php

declare(strict_types=1);

use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Storage\DocumentDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('defaults to a SQLite file the addon owns, so a flat-file site needs no database', function () {
    // Most Statamic sites are flat-file. Requiring a database before somebody
    // can see whether the addon is any use is a reason not to find out.
    config(['a11y-docs.connection' => null]);

    expect(DocumentDatabase::connectionName())->toBe('a11y_docs_sqlite');

    app(DocumentDatabase::class)->defineDefaultConnection();

    $connection = config('database.connections.a11y_docs_sqlite');

    expect($connection['driver'])->toBe('sqlite')
        ->and($connection['database'])->toEndWith('a11y-docs/documents.sqlite')
        ->and(app(DocumentDatabase::class)->ownsConnection())->toBeTrue();
});

it('uses a connection the site names instead', function () {
    config(['a11y-docs.connection' => 'mysql']);

    expect(DocumentDatabase::connectionName())->toBe('mysql')
        ->and(app(DocumentDatabase::class)->ownsConnection())->toBeFalse();
});

it('does not overwrite a connection the site defined under the same name', function () {
    config(['database.connections.a11y_docs_sqlite' => ['driver' => 'mysql', 'database' => 'theirs']]);

    app(DocumentDatabase::class)->defineDefaultConnection();

    expect(config('database.connections.a11y_docs_sqlite.driver'))->toBe('mysql');
});

it('answers "not installed" rather than throwing when the file is not there yet', function () {
    // Schema::hasTable on a SQLite path that does not exist throws. For this
    // question that is the same answer as "no tables".
    config([
        'a11y-docs.connection' => 'a11y_docs_missing',
        'database.connections.a11y_docs_missing' => [
            'driver' => 'sqlite',
            'database' => sys_get_temp_dir().'/a11y-docs-not-here-'.uniqid().'.sqlite',
            'prefix' => '',
        ],
    ]);

    expect(app(DocumentDatabase::class)->isInstalled())->toBeFalse();
});

it('models read the connection at call time, not when the class was loaded', function () {
    // A $connection property would freeze whatever was configured at load.
    config(['a11y-docs.connection' => 'alpha']);
    expect((new DocumentCheck)->getConnectionName())->toBe('alpha');

    config(['a11y-docs.connection' => 'beta']);
    expect((new DocumentCheck)->getConnectionName())->toBe('beta');
});

it('applies a migration added after the tables were first created', function () {
    // docs:install returned early on "the tables exist", so a column added in a
    // later release never reached a site that installed an earlier one. The
    // withdraw action needs `revoked_by`, and it was missing everywhere.
    $file = sys_get_temp_dir().'/a11y-docs-upgrade-'.uniqid().'.sqlite';
    touch($file);

    config([
        'a11y-docs.connection' => 'a11y_docs_upgrade',
        'database.connections.a11y_docs_upgrade' => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
        ],
    ]);

    // The state an older install is in: every migration but the newest.
    foreach (['000100_create_document_checks', '000200_create_document_findings', '000300_create_document_exemptions'] as $name) {
        Artisan::call('migrate', [
            '--database' => 'a11y_docs_upgrade',
            '--path' => realpath(__DIR__.'/../../database/migrations').'/2026_09_08_'.$name.'_table.php',
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    $database = app(DocumentDatabase::class);

    expect($database->isInstalled())->toBeTrue()
        ->and(Schema::connection('a11y_docs_upgrade')->hasColumn('document_exemptions', 'revoked_by'))->toBeFalse()
        ->and($database->pendingMigrations())->toHaveCount(1);

    $this->artisan('docs:install')->assertExitCode(0);

    expect(Schema::connection('a11y_docs_upgrade')->hasColumn('document_exemptions', 'revoked_by'))->toBeTrue()
        ->and($database->pendingMigrations())->toBe([]);

    unlink($file);
});
