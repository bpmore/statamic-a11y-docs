<?php

declare(strict_types=1);

/**
 * Runs the addon's migrations against an in-memory SQLite database and checks
 * the schema they produce, then rolls them back.
 *
 *     composer require illuminate/database illuminate/events    # in a scratch dir
 *     php tools/verify-migrations.php --autoload=/path/to/vendor/autoload.php
 *
 * The addon itself is not scaffolded yet — that is Phase 4 — so there is no
 * Testbench to run these under and no addon test suite to put them in. This
 * stands in until there is, and should be replaced by a real migration test
 * when the addon is scaffolded.
 *
 * Column expectations below are deliberately written out by hand rather than
 * derived from the migrations: a check that reads the same source it is
 * checking proves nothing.
 */
use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

// Imports live up here rather than next to their first use because `use` is
// positional: an alias only applies to code below it, so the guard further down
// would resolve Capsule::class to a global class that does not exist and report
// a working install as missing.

$autoload = null;
$showSql = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--autoload=')) {
        $autoload = substr($argument, 11);
    }
    if ($argument === '--sql') {
        $showSql = true;
    }
}

$autoload ??= dirname(__DIR__).'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "No autoloader at $autoload. Pass --autoload=/path/to/vendor/autoload.php\n");
    exit(1);
}
require $autoload;

if (! class_exists(Capsule::class)) {
    fwrite(STDERR, "illuminate/database is not installed in that autoloader.\n");
    exit(1);
}

$container = new Container;
Container::setInstance($container);

$capsule = new Capsule($container);
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => '',
    'foreign_key_constraints' => true,
]);
// Never connected to. Used only to render the DDL a real site would get, since
// SQLite is forgiving about things MySQL is not.
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'database' => 'unused',
    'username' => 'unused',
    'password' => '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
], 'mysql');
$capsule->setEventDispatcher(new Dispatcher($container));
$capsule->setAsGlobal();
$capsule->bootEloquent();

// The migrations use the Schema facade, as Laravel migrations do. Point it at
// the capsule rather than at an application that does not exist here.
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);

$schema = $capsule->getConnection()->getSchemaBuilder();

/** What each table must end up looking like. */
$expected = [
    'document_checks' => [
        'columns' => [
            'id', 'asset_id', 'container', 'path', 'format',
            'file_hash', 'file_size', 'page_count',
            'engine', 'engine_version', 'status',
            'checked_at', 'duration_ms', 'error', 'unchecked',
            'created_at', 'updated_at',
        ],
        'nullable' => ['file_hash', 'page_count', 'engine', 'engine_version', 'duration_ms', 'error', 'unchecked', 'created_at', 'updated_at'],
        'required' => ['asset_id', 'container', 'path', 'format', 'file_size', 'status', 'checked_at'],
    ],
    'document_findings' => [
        'columns' => ['id', 'check_id', 'rule_id', 'severity', 'message', 'help_url', 'location'],
        'nullable' => ['help_url', 'location'],
        'required' => ['check_id', 'rule_id', 'severity', 'message'],
    ],
    'document_exemptions' => [
        'columns' => [
            'id', 'asset_id', 'reason', 'granted_by',
            'granted_at', 'expires_at', 'revoked_at', 'created_at', 'updated_at',
        ],
        'nullable' => ['granted_by', 'expires_at', 'revoked_at', 'created_at', 'updated_at'],
        'required' => ['asset_id', 'reason', 'granted_at'],
    ],
];

$problems = [];
$migrations = glob(dirname(__DIR__).'/database/migrations/*.php');
sort($migrations);

if ($migrations === []) {
    fwrite(STDERR, "No migrations found in database/migrations.\n");
    exit(1);
}

$instances = [];
foreach ($migrations as $file) {
    $migration = require $file;
    $instances[basename($file)] = $migration;
    $migration->up();
}

foreach ($expected as $table => $shape) {
    if (! $schema->hasTable($table)) {
        $problems[] = "$table: was not created";

        continue;
    }

    $actual = $schema->getColumnListing($table);
    sort($actual);
    $wanted = $shape['columns'];
    sort($wanted);

    foreach (array_diff($wanted, $actual) as $missing) {
        $problems[] = "$table.$missing: missing";
    }
    foreach (array_diff($actual, $wanted) as $unexpected) {
        $problems[] = "$table.$unexpected: unexpected column";
    }

    foreach ($schema->getColumns($table) as $column) {
        $name = $column['name'];
        if (in_array($name, $shape['required'], true) && $column['nullable']) {
            $problems[] = "$table.$name: should be NOT NULL";
        }
        if (in_array($name, $shape['nullable'], true) && ! $column['nullable']) {
            $problems[] = "$table.$name: should be nullable";
        }
    }
}

// The vocabularies the string columns carry have to be the ones core emits.
$checked = 0;
foreach ([
    'status' => [Status::class, 16],
    'format' => [Format::class, 8],
] as $column => [$enum, $length]) {
    foreach ($enum::cases() as $case) {
        $checked++;
        if (strlen($case->value) > $length) {
            $problems[] = "document_checks.$column: '{$case->value}' is longer than $length characters";
        }
    }
}
foreach (Severity::cases() as $case) {
    $checked++;
    if (strlen($case->value) > 16) {
        $problems[] = "document_findings.severity: '{$case->value}' is longer than 16 characters";
    }
}
echo "$checked enum values fit their columns.\n";

// The DDL a MySQL site would actually get. Rendered, never executed: SQLite
// accepts things MySQL rejects, and an index key over 3072 bytes at utf8mb4 is
// the classic way a schema passes locally and fails on install.
$mysql = $capsule->getDatabaseManager()->connection('mysql');
$statements = $mysql->pretend(function () use ($container, $capsule, $instances): void {
    $container->instance('db.schema', $capsule->getDatabaseManager()->connection('mysql')->getSchemaBuilder());
    foreach ($instances as $migration) {
        $migration->up();
    }
});
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());

foreach ($statements as $statement) {
    // varchar(n) at utf8mb4 costs 4n bytes in an index key.
    if (preg_match('/^alter table .*? add (unique|index) .*?\((.+?)\)$/i', $statement['query'], $match) !== 1) {
        if ($showSql) {
            echo '  '.$statement['query'].";\n";
        }

        continue;
    }
    if ($showSql) {
        echo '  '.$statement['query'].";\n";
    }
}

if ($showSql) {
    echo "\n";
}

$columnWidths = mysqlColumnWidths($statements);

// The code declares how wide an engine string may be; the schema has to
// accommodate it. Checked in that direction on purpose: MySQL's strict mode
// rejects an overlong value rather than truncating it, so a narrowed column
// would lose whole check rows rather than shorten a name. Read off the MySQL
// DDL because SQLite discards varchar lengths entirely.
foreach ([
    'document_checks.engine' => Engine::MAX_NAME_LENGTH,
    'document_checks.engine_version' => Engine::MAX_VERSION_LENGTH,
] as $column => $declared) {
    $actual = $columnWidths[$column] ?? null;
    if ($actual === null || $actual === 0) {
        $problems[] = "$column: no varchar width found in the generated schema";

        continue;
    }
    if ($actual < $declared) {
        $problems[] = "$column: column holds $actual characters, Engine allows $declared";
    }
}
printf(
    "engine columns hold %d and %d characters.\n",
    $columnWidths['document_checks.engine'] ?? 0,
    $columnWidths['document_checks.engine_version'] ?? 0,
);

$keyBytes = mysqlIndexKeyBytes($statements);
foreach ($keyBytes as $index => $bytes) {
    if ($bytes > 3072) {
        $problems[] = "$index: index key is $bytes bytes at utf8mb4, over InnoDB's 3072-byte limit";
    }
}
printf("%d MySQL index keys checked, widest %d bytes of 3072.\n", count($keyBytes), $keyBytes === [] ? 0 : max($keyBytes));

// Every migration has to be reversible.
foreach (array_reverse($instances) as $name => $migration) {
    $migration->down();
}
foreach (array_keys($expected) as $table) {
    if ($schema->hasTable($table)) {
        $problems[] = "$table: still present after down()";
    }
}

if ($problems !== []) {
    fwrite(STDERR, count($problems)." problem(s):\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "  - $problem\n");
    }
    exit(1);
}

printf("%d migrations applied, verified and rolled back.\n", count($instances));

/**
 * Declared varchar widths from the rendered MySQL DDL, keyed "table.column".
 * Non-character columns are 0.
 *
 * @param  list<array{query: string, bindings: array}>  $statements
 * @return array<string, int>
 */
function mysqlColumnWidths(array $statements): array
{
    $widths = [];
    foreach ($statements as $statement) {
        if (preg_match('/^create table `(\w+)` \((.*)\)/is', $statement['query'], $create) !== 1) {
            continue;
        }
        foreach (explode(', `', $create[2]) as $definition) {
            if (preg_match('/^`?(\w+)` (\w+)(?:\((\d+)\))?/', $definition, $column) !== 1) {
                continue;
            }
            $widths[$create[1].'.'.$column[1]] = in_array($column[2], ['varchar', 'char'], true)
                ? (int) ($column[3] ?? 255)
                : 0;
        }
    }

    return $widths;
}

/**
 * Index key sizes in bytes. A character column costs four bytes per character
 * at utf8mb4; everything else is close enough to its fixed width for a limit
 * check.
 *
 * @param  list<array{query: string, bindings: array}>  $statements
 * @return array<string, int>
 */
function mysqlIndexKeyBytes(array $statements): array
{
    $widths = [];
    foreach (mysqlColumnWidths($statements) as $column => $characters) {
        $widths[$column] = $characters > 0 ? $characters * 4 : 8;
    }

    $keys = [];
    foreach ($statements as $statement) {
        if (preg_match('/^alter table `(\w+)` add (?:unique|index) `(\w+)`\((.+)\)$/i', $statement['query'], $index) !== 1) {
            continue;
        }
        $bytes = 0;
        foreach (explode(', ', $index[3]) as $column) {
            $bytes += $widths[$index[1].'.'.trim($column, '`')] ?? 8;
        }
        $keys[$index[2]] = $bytes;
    }

    return $keys;
}
