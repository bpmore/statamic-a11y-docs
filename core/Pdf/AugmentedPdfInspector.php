<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Pdf;

use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\Inspector;
use Bpmore\DocumentA11yCore\UncheckedRule;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfInspector;

/**
 * The heuristics, with PDF/UA validation added when veraPDF is installed.
 *
 * Spec §5 reads as though veraPDF replaces the heuristics. Running it against
 * the fixture corpus showed why it must not: PDF/UA has no requirement about
 * bookmarks in a long document and none about a scan with no text layer, so
 * `pdf.no_bookmarks` and `pdf.image_only` simply stop being reported. One of
 * those is a headline number in spec §9 — "1,240 PDFs · 890 untagged · 210
 * image-only scans" — and installing an optional engine must not make it
 * vanish.
 *
 * So both run, and the engine recorded says so.
 */
final class AugmentedPdfInspector implements Inspector
{
    public function __construct(
        private readonly Inspector $heuristics = new PdfInspector,
        private readonly Inspector $validator = new VeraPdfInspector,
    ) {}

    public function engine(): Engine
    {
        // Both versions come from the inspectors actually held, not from a
        // constant: an injected heuristics inspector reporting a different
        // version must not be misdescribed by the engine that wraps it.
        return $this->validator->isAvailable()
            ? Engine::heuristicsWithVeraPdf(
                $this->heuristics->engine()->version,
                $this->validator->engine()->version,
            )
            : $this->heuristics->engine();
    }

    public function isAvailable(): bool
    {
        return $this->heuristics->isAvailable();
    }

    public function supports(Format $format): bool
    {
        return $this->heuristics->supports($format);
    }

    public function inspect(string $path, Format $format): InspectionResult
    {
        $result = $this->heuristics->inspect($path, $format);

        // A document the heuristics could not read is not one to hand to a
        // second engine, and the engine that failed is the one to report.
        if (! $result->status->isConclusive() || ! $this->validator->isAvailable()) {
            return $result;
        }

        $validated = $this->validator->inspect($path, $format);

        if (! $validated->status->isConclusive()) {
            // veraPDF was configured and did not produce a verdict — timed out,
            // over the size cap, or could not read the file. The heuristics
            // still stand, but silently dropping the authoritative check would
            // let a site think it had validation it did not get.
            return InspectionResult::checked(
                $this->heuristics->engine(),
                $result->findings,
                [
                    ...$result->unchecked,
                    new UncheckedRule(
                        'pdf.ua_validation',
                        $validated->error ?? 'PDF/UA validation did not complete.',
                    ),
                ],
                $result->pageCount,
            );
        }

        return InspectionResult::checked(
            $this->engine(),
            [...$result->findings, ...$this->newFindings($result, $validated)],
            [...$result->unchecked, ...$validated->unchecked],
            $result->pageCount,
        );
    }

    /**
     * veraPDF findings for rules the heuristics did not already report.
     *
     * Where both engines see the same thing, the heuristic finding is kept: it
     * carries the same identifier and severity but says what the problem means
     * for a reader rather than quoting an ISO clause at them. Where only
     * veraPDF sees it, its finding is added — dropping it would lose the
     * authority the engine was installed for.
     *
     * @return list<Finding>
     */
    private function newFindings(InspectionResult $heuristics, InspectionResult $validated): array
    {
        $alreadyReported = array_map(
            static fn (Finding $finding): string => $finding->ruleId,
            $heuristics->findings,
        );

        return array_values(array_filter(
            $validated->findings,
            static fn (Finding $finding): bool => ! in_array($finding->ruleId, $alreadyReported, true),
        ));
    }
}
