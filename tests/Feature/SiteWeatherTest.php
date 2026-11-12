<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\SiteWeather\Contracts\WeatherContributor;
use Bpmore\SiteWeather\Contributors;
use Bpmore\SiteWeather\Forecast;
use Bpmore\SiteWeather\Reading;
use Bpmore\SiteWeather\State;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Bpmore\StatamicA11yDocs\Storage\DocumentDatabase;
use Bpmore\StatamicA11yDocs\Weather\DocumentsContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;

/**
 * The Documents band on Site Weather's tile. Site Weather is a dev dependency
 * here so the real contract is what is tested, not a stand-in.
 */
uses(RefreshDatabase::class);

/** One stored result, with at most one finding: the shape the weather is decided from. */
function seedCheck(Status $status, ?Severity $severity = null, string $checkedAt = '2026-09-11 09:00:00'): DocumentCheck
{
    static $n = 0;
    $n++;

    $check = DocumentCheck::create([
        'asset_id' => "documents::file-$n.pdf",
        'container' => 'documents',
        'path' => "file-$n.pdf",
        'format' => 'pdf',
        'file_size' => 1000,
        'status' => $status,
        'checked_at' => $checkedAt,
    ]);

    if ($severity !== null) {
        DocumentFinding::create([
            'check_id' => $check->id,
            'rule_id' => 'pdf.not_tagged',
            'severity' => $severity,
            'message' => 'Seeded finding.',
        ]);
    }

    return $check;
}

function seedMany(int $count, Status $status, ?Severity $severity = null): void
{
    for ($i = 0; $i < $count; $i++) {
        seedCheck($status, $severity);
    }
}

function documentsBand(): Reading
{
    return app(DocumentsContributor::class)->reading();
}

it('is tagged for Site Weather to find, under the documents key', function () {
    $tagged = collect(app()->tagged('site-weather.contributors'));

    expect($tagged)->toHaveCount(1)
        ->and($tagged->first())->toBeInstanceOf(DocumentsContributor::class)
        ->and($tagged->first())->toBeInstanceOf(WeatherContributor::class)
        ->and($tagged->first()->key())->toBe('documents')
        ->and($tagged->first()->label())->toBe('Documents');
});

it('reads unknown with the next step when the database was never installed', function () {
    // The real condition, not a mock: no document_checks table on the
    // connection. RefreshDatabase rolls the rename back with the test.
    Schema::connection(DocumentDatabase::connectionName())->rename('document_checks', 'document_checks_absent');
    expect(app(DocumentDatabase::class)->isInstalled())->toBeFalse();

    $reading = documentsBand();

    expect($reading->state)->toBe(State::Unknown)
        ->and($reading->headline)->toBe('Not set up yet: run php please docs:install')
        ->and($reading->url)->toBe(cp_route('a11y-docs.dashboard'));
});

it('reads unknown, not clear, before anything has been checked', function () {
    $reading = documentsBand();

    expect($reading->state)->toBe(State::Unknown)
        ->and($reading->headline)->toBe('No documents have been checked yet')
        ->and($reading->url)->toBe(cp_route('a11y-docs.dashboard'))
        ->and($reading->computedAt)->toBeNull();
});

it('reads unknown when nothing could actually be checked', function () {
    seedMany(3, Status::Unsupported);
    seedMany(2, Status::Error);

    $reading = documentsBand();

    expect($reading->state)->toBe(State::Unknown)
        ->and($reading->headline)->toBe('5 documents could not be checked');
});

it('is clear when every checked document passes, dated to the latest check', function () {
    seedCheck(Status::Pass, null, '2026-09-10 08:00:00');
    seedCheck(Status::Pass, null, '2026-09-11 09:30:00');
    seedCheck(Status::Pass, null, '2026-09-09 07:00:00');

    $reading = documentsBand();

    expect($reading->state)->toBe(State::Clear)
        ->and($reading->headline)->toBe('All 3 documents pass')
        ->and($reading->computedAt?->toDateTimeString())->toBe('2026-09-11 09:30:00');
});

it('is fair for a few minor or moderate failures', function () {
    seedMany(9, Status::Pass);
    seedCheck(Status::Fail, Severity::Minor);

    expect(documentsBand()->state)->toBe(State::Fair)
        ->and(documentsBand()->headline)->toBe('1 of 10 documents fails');
});

it('is overcast for a serious barrier, or for widespread lesser failures', function () {
    seedMany(9, Status::Pass);
    seedCheck(Status::Fail, Severity::Serious);
    expect(documentsBand()->state)->toBe(State::Overcast);

    DocumentFinding::query()->delete();
    DocumentCheck::query()->delete();

    seedMany(7, Status::Pass);
    seedMany(3, Status::Fail, Severity::Moderate); // 30% failing, nothing serious
    expect(documentsBand()->state)->toBe(State::Overcast);
});

it('rains for any critical failure, or for widespread serious ones', function () {
    seedMany(19, Status::Pass);
    seedCheck(Status::Fail, Severity::Critical); // one in twenty: under the storm line
    expect(documentsBand()->state)->toBe(State::Rain)
        ->and(documentsBand()->headline)->toBe('1 of 20 documents fails, 1 with critical problems');

    DocumentFinding::query()->delete();
    DocumentCheck::query()->delete();

    seedMany(7, Status::Pass);
    seedMany(3, Status::Fail, Severity::Serious); // 30% with serious barriers
    expect(documentsBand()->state)->toBe(State::Rain);
});

it('storms when critical problems reach a tenth of the library', function () {
    seedMany(8, Status::Pass);
    seedMany(2, Status::Fail, Severity::Critical);

    $reading = documentsBand();

    expect($reading->state)->toBe(State::Storm)
        ->and($reading->headline)->toBe('2 of 10 documents fail, 2 with critical problems');
});

it('measures proportions against checked documents, not the whole shelf', function () {
    seedMany(50, Status::Unsupported); // legacy files the addon cannot open
    seedCheck(Status::Pass);
    seedCheck(Status::Fail, Severity::Critical); // one of two checked: half the library

    $reading = documentsBand();

    expect($reading->state)->toBe(State::Storm)
        ->and($reading->headline)->toBe('1 of 2 documents fails, 1 with critical problems');
});

it('reaches the tile end to end from a real check', function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    foreach ([DocumentInspector::class, AssetChecker::class, DocumentScanner::class] as $binding) {
        app()->forgetInstance($binding);
    }
    Storage::fake('assets');
    AssetContainer::make('documents')->disk('assets')->save();
    Storage::disk('assets')->put('untagged.pdf', (string) file_get_contents(corpusPath('pdf/untagged.pdf')));
    $this->artisan('docs:check --sync');

    $forecast = Forecast::from(app(Contributors::class));

    expect($forecast->bands)->toHaveCount(1)
        ->and($forecast->bands[0]->key)->toBe('documents')
        ->and($forecast->bands[0]->label)->toBe('Documents')
        ->and($forecast->overall())->toBe(State::Storm) // one untagged PDF in a library of one
        ->and($forecast->worstBand()?->reading->computedAt)->not->toBeNull()
        ->and($forecast->summary())->toBe('Overall: storm. Documents: storm, 1 of 1 document fails, 1 with critical problems.');
});
