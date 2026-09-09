<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Tests;

use Bpmore\StatamicA11yDocs\ServiceProvider;
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
}
