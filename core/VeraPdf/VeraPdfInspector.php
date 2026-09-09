<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\VeraPdf;

use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\Inspector;
use Bpmore\DocumentA11yCore\Pdf\AugmentedPdfInspector;
use Bpmore\DocumentA11yCore\Pdf\PdfRule;
use Bpmore\DocumentA11yCore\Severity;
use InvalidArgumentException;
use Throwable;

/**
 * PDF/UA-1 validation through the external veraPDF binary.
 *
 * Authoritative where the heuristics are only well-informed, and optional
 * because it is a Java program. On its own it is not a replacement for the
 * heuristics — see {@see AugmentedPdfInspector},
 * which is how it is meant to be used.
 */
final class VeraPdfInspector implements Inspector
{
    /**
     * PDF/UA clauses that mean exactly the same thing as one of our own rules.
     *
     * Every entry was confirmed by running veraPDF 1.30 against the fixture
     * that provokes the matching heuristic, not read off a specification.
     *
     * Two clauses are deliberately absent. 7.1-3 ("content shall be marked as
     * Artifact or tagged as real content") and 7.2-34 ("natural language for
     * text in page content shall be determined") are about individual pieces of
     * content, so both can fail on a document that is tagged and does declare a
     * language. Mapping them onto pdf.not_tagged and pdf.no_lang would put a
     * flatly untrue statement in a report. They come through under their own
     * clause identifiers instead.
     */
    private const CLAUSE_RULES = [
        '6.2-1' => PdfRule::NotTagged,
        '7.1-11' => PdfRule::NotTagged,
        '7.1-10' => PdfRule::TitleNotDisplayed,
        '7.3-1' => PdfRule::FigureMissingAlt,
        '7.16-1' => PdfRule::ExtractionBlocked,
        '7.18.1-3' => PdfRule::UnlabelledFormFields,
        '7.21.7-1' => PdfRule::NoToUnicode,
    ];

    /**
     * veraPDF does not grade its rules, so severity comes from the categories
     * it tags them with. Worst matching tag wins; anything unrecognised is
     * Serious, which is the honest default for a conformance failure whose
     * effect on a reader we have not characterised.
     */
    private const TAG_SEVERITY = [
        'structure' => Severity::Critical,
        'artifact' => Severity::Critical,
        'alt-text' => Severity::Critical,
        'figure' => Severity::Critical,
        'annotation' => Severity::Serious,
        'lang' => Severity::Serious,
        'metadata' => Severity::Serious,
        'syntax' => Severity::Serious,
        'text' => Severity::Serious,
        'font' => Severity::Moderate,
        'page' => Severity::Moderate,
    ];

    public function __construct(
        private readonly VeraPdfRunner $runner = new ProcessVeraPdfRunner,
        private readonly VeraPdfOptions $options = new VeraPdfOptions,
    ) {}

    public function engine(): Engine
    {
        return Engine::veraPdf($this->runner->version() ?? 'unavailable');
    }

    public function isAvailable(): bool
    {
        return $this->runner->version() !== null;
    }

    public function supports(Format $format): bool
    {
        return $format === Format::Pdf;
    }

    public function inspect(string $path, Format $format): InspectionResult
    {
        if (! $this->supports($format)) {
            throw new InvalidArgumentException(
                "VeraPdfInspector was handed a {$format->value} file. Check supports() first."
            );
        }

        if (! $this->isAvailable()) {
            return InspectionResult::skipped('veraPDF is not installed, so PDF/UA validation did not run.');
        }

        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size > $this->options->maxBytes) {
            return InspectionResult::skipped(sprintf(
                'This document is %s, over the %s cap for PDF/UA validation.',
                self::megabytes($size),
                self::megabytes($this->options->maxBytes),
            ));
        }

        try {
            $json = $this->runner->validate($path);
            $report = VeraPdfReport::fromJson($json);
        } catch (Throwable $exception) {
            return InspectionResult::error($this->engine(), 'PDF/UA validation failed: '.$exception->getMessage());
        }

        if ($report->parseFailure !== null) {
            return InspectionResult::error($this->engine(), 'veraPDF could not read this PDF: '.$report->parseFailure);
        }

        return InspectionResult::checked($this->engine(), $this->findings($report));
    }

    /** @return list<Finding> */
    private function findings(VeraPdfReport $report): array
    {
        $findings = [];

        foreach ($report->rules as $rule) {
            $mapped = self::CLAUSE_RULES[$rule->id()] ?? null;

            if ($mapped !== null) {
                // Our identifier, our severity, our wording — so the dashboard
                // and the publish gate behave the same whether or not Java
                // happens to be installed on this server.
                $findings[] = $mapped->finding($this->message($rule));

                continue;
            }

            $findings[] = new Finding(
                $rule->ruleId(),
                $this->severity($rule),
                $this->message($rule),
            );
        }

        return $findings;
    }

    private function message(VeraPdfRule $rule): string
    {
        $description = rtrim($rule->description, '.');
        $checks = $rule->failedChecks > 1 ? " Failed {$rule->failedChecks} times." : '';

        return "PDF/UA {$rule->clause}: {$description}.{$checks}";
    }

    private function severity(VeraPdfRule $rule): Severity
    {
        $severities = array_values(array_filter(array_map(
            static fn (string $tag): ?Severity => self::TAG_SEVERITY[$tag] ?? null,
            $rule->tags,
        )));

        return Severity::worst($severities) ?? Severity::Serious;
    }

    private static function megabytes(int $bytes): string
    {
        return round($bytes / 1_048_576, 1).' MB';
    }
}
