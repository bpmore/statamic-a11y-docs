<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml;

use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;

/**
 * The rules for one OOXML format.
 *
 * Thin by design. Everything about reading the container lives in
 * {@see OoxmlPackage}; a rule set only knows which parts matter in its format
 * and what is wrong with them.
 */
interface RuleSet
{
    public function format(): Format;

    /** @return list<Finding> */
    public function check(OoxmlPackage $package): array;
}
