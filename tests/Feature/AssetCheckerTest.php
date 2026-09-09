<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;

uses(RefreshDatabase::class);

beforeEach(function () {
    // These tests are about caching, not about which findings. Pinning the
    // engine keeps them saying the same thing on a machine with veraPDF
    // installed and one without — otherwise the suite passes here and fails on
    // anybody else's laptop.
    config()->set('a11y-docs.verapdf.enabled', false);
    app()->forgetInstance(DocumentInspector::class);
    app()->forgetInstance(AssetChecker::class);

    Storage::fake('assets');

    $this->container = AssetContainer::make('documents')->disk('assets')->save();

    $this->putAsset = function (string $path, string $fixture) {
        Storage::disk('assets')->put($path, (string) file_get_contents(corpusPath($fixture)));

        return $this->container->makeAsset($path);
    };
});

it('checks a document and stores the result', function () {
    $asset = ($this->putAsset)('reports/handbook.pdf', 'pdf/untagged.pdf');

    $check = app(AssetChecker::class)->check($asset);

    expect($check->status)->toBe(Status::Fail)
        ->and($check->asset_id)->toBe('documents::reports/handbook.pdf')
        ->and($check->container)->toBe('documents')
        ->and($check->path)->toBe('reports/handbook.pdf')
        ->and($check->format->value)->toBe('pdf')
        ->and($check->file_hash)->toHaveLength(64)
        ->and($check->file_size)->toBeGreaterThan(0)
        ->and($check->engine)->not->toBeNull()
        ->and($check->checked_at)->not->toBeNull()
        ->and($check->findings)->toHaveCount(1)
        ->and($check->findings->first()->rule_id)->toBe('pdf.not_tagged');
});

it('does no work at all when the file has not changed', function () {
    // Spec §7: a library of 1,240 PDFs must not be reprocessed nightly. At
    // roughly a second each that is not a matter of tidiness.
    $asset = ($this->putAsset)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $checker = app(AssetChecker::class);

    $first = $checker->check($asset);
    $firstCheckedAt = $first->checked_at;

    expect($checker->isUpToDate($asset))->toBeTrue();

    $second = $checker->check($asset);

    expect($second->id)->toBe($first->id)
        // Untouched, not rewritten with the same values.
        ->and($second->checked_at->equalTo($firstCheckedAt))->toBeTrue()
        ->and(DocumentCheck::count())->toBe(1)
        ->and(DocumentFinding::count())->toBe(1);
});

it('re-checks when the file changes underneath it', function () {
    $asset = ($this->putAsset)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $checker = app(AssetChecker::class);

    $before = $checker->check($asset);

    // Same path, different document: two findings instead of one.
    Storage::disk('assets')->put('reports/handbook.pdf', (string) file_get_contents(corpusPath('pdf/no-lang.pdf')));
    $asset = $this->container->makeAsset('reports/handbook.pdf');

    expect($checker->isUpToDate($asset))->toBeFalse();

    $after = $checker->check($asset);

    expect($after->id)->toBe($before->id)
        ->and($after->file_hash)->not->toBe($before->file_hash)
        ->and($after->findings()->pluck('rule_id')->all())->toBe(['pdf.no_lang'])
        // One row per asset, and the old findings are gone rather than added to.
        ->and(DocumentCheck::count())->toBe(1)
        ->and(DocumentFinding::count())->toBe(1);
});

it('re-checks when the engine changes, not only when the file does', function () {
    // A site that installs veraPDF has a library of results produced by
    // something less authoritative. Leaving them alone would mean a report
    // claiming PDF/UA validation it never had.
    $asset = ($this->putAsset)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $checker = app(AssetChecker::class);

    $check = $checker->check($asset);
    $check->update(['engine_version' => '0.0.1-old']);

    expect($checker->isUpToDate($this->container->makeAsset('reports/handbook.pdf')))->toBeFalse();
});

it('re-checks anything when told to', function () {
    $asset = ($this->putAsset)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $checker = app(AssetChecker::class);

    $first = $checker->check($asset);
    $second = $checker->check($asset, force: true);

    expect($second->checked_at->greaterThanOrEqualTo($first->checked_at))->toBeTrue()
        ->and(DocumentCheck::count())->toBe(1);
});

it('records a document over the size limit as skipped, with the reason', function () {
    // Not silently ignored, and not downloaded to find out how big it is.
    $asset = ($this->putAsset)('reports/enormous.pdf', 'pdf/untagged.pdf');

    $checker = new AssetChecker(app(DocumentInspector::class), maxBytes: 10);
    $check = $checker->check($asset);

    expect($check->status)->toBe(Status::Skipped)
        ->and($check->error)->toContain('over the')
        ->and($check->engine)->toBeNull()
        // Nothing was read, so there is nothing to hash, and it will be
        // re-evaluated next run — which costs one size lookup.
        ->and($check->file_hash)->toBeNull()
        ->and($checker->isUpToDate($asset))->toBeFalse();
});

it('reports a legacy document as unsupported without opening it', function () {
    $asset = ($this->putAsset)('reports/old.doc', 'legacy/handbook.doc');

    $check = app(AssetChecker::class)->check($asset);

    expect($check->status)->toBe(Status::Unsupported)
        ->and($check->error)->toContain('save it as .docx')
        ->and($check->findings)->toBeEmpty()
        ->and($check->engine)->toBeNull();
});

it('stores what it could not check as well as what it found', function () {
    $asset = ($this->putAsset)('reports/locked.pdf', 'pdf/encrypted-no-extract.pdf');

    $check = app(AssetChecker::class)->check($asset);

    expect($check->unchecked)->not->toBeNull()
        ->and(array_column($check->unchecked, 'rule_id'))->toContain('pdf.no_title')
        ->and($check->findings()->pluck('rule_id')->all())->toContain('pdf.extraction_blocked');
});

it('stores where a finding is so somebody can act on it', function () {
    $asset = ($this->putAsset)('reports/form.pdf', 'pdf/form-unlabelled-fields.pdf');

    $check = app(AssetChecker::class)->check($asset);
    $finding = $check->findings->first();

    expect($finding->locatedAt())->not->toBeNull()
        ->and($finding->locatedAt()->describe())->toBe('page 1, field2');
});

it('checks a document by what is in it, not what it is called', function () {
    $asset = ($this->putAsset)('reports/renamed.docx', 'misc/actually-a-pdf.docx');

    $check = app(AssetChecker::class)->check($asset);

    expect($check->format->value)->toBe('pdf')
        ->and($check->findings()->pluck('rule_id')->all())->toBe(['pdf.not_tagged']);
});
