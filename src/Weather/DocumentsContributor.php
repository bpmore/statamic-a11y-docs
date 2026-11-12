<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Weather;

use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\SiteWeather\Contracts\WeatherContributor;
use Bpmore\SiteWeather\Reading;
use Bpmore\SiteWeather\State;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Bpmore\StatamicA11yDocs\Reporting\LibrarySummary;
use Bpmore\StatamicA11yDocs\Storage\DocumentDatabase;
use Carbon\CarbonImmutable;

/**
 * The Documents band on Site Weather's tile.
 *
 * Site Weather is an optional companion, not a dependency: this class is
 * tagged by name in the service provider and only ever loaded when Site
 * Weather resolves the tag, so the interface it implements need not exist on
 * a site that has not installed it.
 *
 * It reads. Three aggregate queries over stored results - the same numbers
 * the dashboard and `docs:report` draw - and never opens a document. Not
 * installed, or nothing checked yet, reads as unknown, never as clear.
 *
 * The weather, decided here because this addon knows its own data:
 *
 *   clear     nothing fails
 *   fair      failures, but only moderate or minor ones, in under a quarter
 *             of the library
 *   overcast  a serious barrier somewhere, or a quarter of the library failing
 *   rain      a document somebody cannot use (critical), or serious barriers
 *             across a quarter of the library
 *   storm     critical problems across a tenth of the library or more
 *
 * Proportions are of documents actually checked - pass or fail - so a shelf of
 * legacy files the addon cannot open neither inflates nor hides the weather.
 */
final class DocumentsContributor implements WeatherContributor
{
    /** Critical findings across this share of the library, or more, is a storm. */
    private const STORM_CRITICAL_SHARE = 0.10;

    /** This share of the library failing moves the weather one step worse. */
    private const WIDESPREAD_SHARE = 0.25;

    public function __construct(
        private LibrarySummary $summary,
        private DocumentDatabase $database,
    ) {}

    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return 'Documents';
    }

    public function reading(): Reading
    {
        $url = cp_route('a11y-docs.dashboard');

        // Installed but never set up - no database yet. Asking first costs one
        // schema query and turns a caught exception into an answer.
        if (! $this->database->isInstalled()) {
            return Reading::unknown('Not set up yet: run php please docs:install', $url);
        }

        $statuses = $this->summary->statuses();
        $total = array_sum($statuses);

        if ($total === 0) {
            return Reading::unknown('No documents have been checked yet', $url);
        }

        $failing = $statuses[Status::Fail->value];
        $checked = $statuses[Status::Pass->value] + $failing;

        if ($checked === 0) {
            return Reading::unknown(sprintf('%s could not be checked', self::documents($total)), $url);
        }

        $computedAt = $this->latestCheck();

        if ($failing === 0) {
            return new Reading(State::Clear, sprintf('All %s pass', self::documents($checked)), $url, $computedAt);
        }

        $worst = $this->worstSeverity();
        $share = $failing / $checked;
        $critical = $this->documentsWithCritical();

        $state = match (true) {
            $worst === Severity::Critical && $critical / $checked >= self::STORM_CRITICAL_SHARE => State::Storm,
            $worst === Severity::Critical => State::Rain,
            $worst === Severity::Serious && $share >= self::WIDESPREAD_SHARE => State::Rain,
            $worst === Severity::Serious => State::Overcast,
            $share >= self::WIDESPREAD_SHARE => State::Overcast,
            default => State::Fair,
        };

        $headline = sprintf('%s of %s %s', number_format($failing), self::documents($checked), $failing === 1 ? 'fails' : 'fail');

        if ($critical > 0) {
            $headline .= sprintf(', %s with critical problems', number_format($critical));
        }

        return new Reading($state, $headline, $url, $computedAt);
    }

    private function worstSeverity(): ?Severity
    {
        foreach ($this->summary->findingsBySeverity() as $severity => $count) {
            if ($count > 0) {
                return Severity::from($severity);
            }
        }

        return null;
    }

    /** Documents, not findings: one untagged PDF with forty findings is one document. */
    private function documentsWithCritical(): int
    {
        return (int) DocumentFinding::query()
            ->where('severity', Severity::Critical->value)
            ->distinct()
            ->count('check_id');
    }

    private function latestCheck(): ?CarbonImmutable
    {
        $latest = DocumentCheck::query()->max('checked_at');

        return $latest === null ? null : CarbonImmutable::parse($latest);
    }

    private static function documents(int $count): string
    {
        return number_format($count).' '.($count === 1 ? 'document' : 'documents');
    }
}
