<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\UncheckedRule;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Bpmore\StatamicA11yDocs\PreparedDocumentCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['a11y-docs.verapdf.enabled' => false]);
    app()->forgetInstance(DocumentInspector::class);
    app()->forgetInstance(AssetChecker::class);
    Storage::fake('prepared-documents');
    $this->container = AssetContainer::make('prepared-documents')->disk('prepared-documents')->save();
    Storage::disk('prepared-documents')->put('fixture.pdf', file_get_contents(corpusPath('pdf/untagged.pdf')));
    $this->asset = $this->container->makeAsset('fixture.pdf');
});

it('prepares read only and publishes through the native checker with cache parity', function () {
    $checker = app(AssetChecker::class);
    $prepared = $checker->prepare($this->asset);
    expect(DocumentCheck::count())->toBe(0)->and(DocumentFinding::count())->toBe(0);
    $roundtrip = PreparedDocumentCheck::fromArray(json_decode(json_encode($prepared), true));
    expect($roundtrip->jsonSerialize())->toBe($prepared->jsonSerialize());
    $check = $checker->publishPrepared($this->asset, $roundtrip);
    expect($check->findings()->pluck('rule_id')->all())->toBe(['pdf.not_tagged']);
    $cached = $checker->prepare($this->asset);
    expect($cached->result)->toBeNull()->and($checker->publishPrepared($this->asset, $cached)->id)->toBe($check->id);
});

it('roundtrips every result field without truncating findings or unchecked rules', function () {
    $prepared = new PreparedDocumentCheck('documents::fixture.pdf', InspectionResult::checked(
        new Engine('combined', '1+2'), [
            new Finding('pdf.first', Severity::Critical, 'Full α message', Location::page(2, 'Figure'), 'https://example.test/help'),
            new Finding('pdf.second', Severity::Minor, 'Second message', Location::sheet('Sheet', 'Cell')),
        ], [new UncheckedRule('pdf.third', 'Not inspected')], 12,
    )->withDuration(432), str_repeat('a', 64), 123, Format::Pdf);
    expect(PreparedDocumentCheck::fromArray(json_decode(json_encode($prepared), true))->jsonSerialize())->toBe($prepared->jsonSerialize());
});

it('does not publish preparation for another asset', function () {
    $checker = app(AssetChecker::class);
    $prepared = new PreparedDocumentCheck('wrong::asset.pdf', InspectionResult::skipped('Over limit.'), null, 1, Format::Pdf);
    expect(fn () => $checker->publishPrepared($this->asset, $prepared))->toThrow(RuntimeException::class, 'another asset');
    expect(DocumentCheck::count())->toBe(0);
});

it('rejects a cached result replaced between preparation and publication', function () {
    $checker = app(AssetChecker::class);
    $check = $checker->check($this->asset);
    $prepared = $checker->prepare($this->asset);
    $check->update(['file_hash' => str_repeat('b', 64)]);
    expect(fn () => $checker->publishPrepared($this->asset, $prepared))->toThrow(RuntimeException::class, 'changed before publication');
});

it('keeps native cached reads from overwriting a concurrently replaced result', function () {
    $checker = app(AssetChecker::class);
    $check = $checker->check($this->asset);
    $once = true;
    DB::connection($check->getConnectionName())->listen(function ($event) use (&$once, $check) {
        if ($once && str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, 'document_checks')) {
            $once = false;
            $check->newQuery()->whereKey($check->id)->update(['file_hash' => str_repeat('b', 64)]);
        }
    });
    $cached = $checker->check($this->asset);
    expect($cached->id)->toBe($check->id)
        ->and($check->fresh()->file_hash)->toBe(str_repeat('b', 64))
        ->and($check->findings()->pluck('rule_id')->all())->toBe(['pdf.not_tagged']);
});
