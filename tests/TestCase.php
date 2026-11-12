<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Tests;

use Bpmore\StatamicA11yDocs\ServiceProvider;
use Statamic\Facades\Blueprint;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * Boots a real Statamic through Orchestra Testbench.
 *
 * Slower than tests/Core, which is exactly why the rules live there and not
 * here: this is for the things that genuinely need a framework.
 *
 * PreventsSavingStacheItemsToDisk is not optional. Without it an asset
 * container created in one test is written to disk and still there in the
 * next — which surfaces as a later test failing on a disk it never configured,
 * a long way from the test that actually caused it.
 */
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * The addon defaults to its own SQLite file so a flat-file site needs no
     * database. Tests want Testbench's in-memory one instead, which is what a
     * site with a real database gets by naming its own connection.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['a11y-docs.connection' => config('database.default')]);

        // Blueprints are not Stache items, so the trait above does not catch
        // them: a blueprint saved in one test is written into Testbench's
        // skeleton and is still there on the next run, where a test that
        // expects no panel on the blueprint finds one. Point them at the same
        // throwaway directory, which is emptied between tests.
        Blueprint::setDirectory($this->fakeStacheDirectory.'/blueprints');
    }

    /**
     * The addon does not loadMigrationsFrom - see Storage\DocumentDatabase for
     * why - so RefreshDatabase has to be told where they are.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
