<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml;

use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\Inspector;
use Bpmore\DocumentA11yCore\Ooxml\Excel\ExcelRules;
use Bpmore\DocumentA11yCore\Ooxml\PowerPoint\PowerPointRules;
use Bpmore\DocumentA11yCore\Ooxml\Word\WordRules;
use Bpmore\DocumentA11yCore\Version;
use InvalidArgumentException;
use Throwable;

/**
 * Checks DOCX, PPTX and XLSX documents.
 *
 * One inspector for all three, because they are one container. The rule sets
 * are what differ, and each is registered here.
 */
final class OoxmlInspector implements Inspector
{
    /** @var list<RuleSet> */
    private readonly array $ruleSets;

    /** @param  list<RuleSet>|null  $ruleSets */
    public function __construct(?array $ruleSets = null)
    {
        $this->ruleSets = $ruleSets ?? [new WordRules, new PowerPointRules, new ExcelRules];
    }

    public function engine(): Engine
    {
        return Engine::heuristics(Version::CURRENT);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supports(Format $format): bool
    {
        return $this->ruleSetFor($format) !== null;
    }

    public function inspect(string $path, Format $format): InspectionResult
    {
        $rules = $this->ruleSetFor($format);

        if ($rules === null) {
            throw new InvalidArgumentException(
                "OoxmlInspector was handed a {$format->value} file. Check supports() first."
            );
        }

        $package = null;

        try {
            $package = OoxmlPackage::open($path);
            $findings = $rules->check($package);
        } catch (Throwable $exception) {
            // A document that cannot be opened is a result, not an exception:
            // one bad file in a library of 1,240 must not stop the run.
            return InspectionResult::error(
                $this->engine(),
                'This document could not be read: '.rtrim($exception->getMessage(), '.').'.'
            );
        } finally {
            $package?->close();
        }

        return InspectionResult::checked($this->engine(), $findings);
    }

    private function ruleSetFor(Format $format): ?RuleSet
    {
        foreach ($this->ruleSets as $ruleSet) {
            if ($ruleSet->format() === $format) {
                return $ruleSet;
            }
        }

        return null;
    }
}
