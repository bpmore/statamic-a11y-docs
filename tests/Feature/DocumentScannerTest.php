<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Jobs\CheckDocuments;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    app()->forgetInstance(DocumentInspector::class);
    app()->forgetInstance(AssetChecker::class);
    app()->forgetInstance(DocumentScanner::class);

    Storage::fake('assets');
    Storage::fake('images');

    $this->documents = AssetContainer::make('documents')->disk('assets')->save();
    $this->images = AssetContainer::make('images')->disk('images')->save();

    $this->put = function (string $disk, string $path, string $fixture) {
        Storage::disk($disk)->put($path, (string) file_get_contents(corpusPath($fixture)));
    };
});

it('finds documents anywhere in a container, and nothing else', function () {
    ($this->put)('assets', 'reports/handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('assets', 'reports/2026/policy.docx', 'docx/good.docx');
    ($this->put)('assets', 'decks/review.pptx', 'pptx/good.pptx');
    ($this->put)('assets', 'legacy/old.doc', 'legacy/handbook.doc');
    Storage::disk('assets')->put('notes/readme.txt', 'not a document');
    Storage::disk('assets')->put('photos/quad.jpg', 'not a document either');

    $found = app(DocumentScanner::class)->documents()->all();
    sort($found);

    expect($found)->toBe([
        'documents::decks/review.pptx',
        'documents::legacy/old.doc',
        'documents::reports/2026/policy.docx',
        'documents::reports/handbook.pdf',
    ]);
});

it('enumerates without opening a single file', function () {
    // On a library of thousands the listing is the part that has to stay fast,
    // so it reads paths and filters by extension. A PDF saved as .docx is
    // enumerated as a Word document here and correctly checked as a PDF later.
    ($this->put)('assets', 'reports/renamed.docx', 'misc/actually-a-pdf.docx');

    expect(app(DocumentScanner::class)->documents()->all())
        ->toBe(['documents::reports/renamed.docx']);

    $check = app(DocumentScanner::class)->run() > 0
        ? DocumentCheck::first()
        : null;

    expect($check?->format->value)->toBe('pdf');
});

it('scans every container unless told otherwise', function () {
    ($this->put)('assets', 'handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('images', 'brochure.pdf', 'pdf/no-lang.pdf');

    expect(app(DocumentScanner::class)->documents())->toHaveCount(2)
        ->and(app(DocumentScanner::class)->documents(['images'])->all())
        ->toBe(['images::brochure.pdf']);
});

it('queues the library as one batch of chunks', function () {
    Bus::fake();

    foreach (range(1, 7) as $n) {
        ($this->put)('assets', "reports/report-$n.pdf", 'pdf/untagged.pdf');
    }

    $scanner = new DocumentScanner(app(AssetChecker::class), chunkSize: 3, concurrency: 2);
    $scanner->queue();

    Bus::assertBatched(function ($batch) {
        // Seven documents in chunks of three: 3 + 3 + 1.
        return $batch->name === 'A11y Docs scan'
            && $batch->jobs->count() === 3
            && $batch->jobs->first()->assetIds === [
                'documents::reports/report-1.pdf',
                'documents::reports/report-2.pdf',
                'documents::reports/report-3.pdf',
            ];
    });
});

it('runs the whole library synchronously when asked', function () {
    ($this->put)('assets', 'reports/handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('assets', 'reports/policy.docx', 'docx/good.docx');

    $seen = [];
    $checked = app(DocumentScanner::class)->run(onChecked: function ($check, $id) use (&$seen) {
        $seen[$id] = $check->status;
    });

    expect($checked)->toBe(2)
        ->and($seen)->toBe([
            'documents::reports/handbook.pdf' => Status::Fail,
            'documents::reports/policy.docx' => Status::Pass,
        ])
        ->and(DocumentCheck::count())->toBe(2);
});

it('carries on when one document in a chunk cannot be checked at all', function () {
    // Per-file isolation, spec §7. One bad file must not take down a run over
    // 1,240 documents.
    ($this->put)('assets', 'a.pdf', 'pdf/untagged.pdf');
    ($this->put)('assets', 'b.pdf', 'pdf/corrupt-truncated.pdf');
    ($this->put)('assets', 'c.pdf', 'pdf/no-lang.pdf');

    $checked = app(DocumentScanner::class)->run();

    expect($checked)->toBe(3)
        ->and(DocumentCheck::where('status', Status::Error->value)->count())->toBe(1)
        ->and(DocumentCheck::where('status', Status::Fail->value)->count())->toBe(2);
});

it('skips a document that was deleted while the scan was running', function () {
    // A scan of a live library takes minutes and people keep working. Not an
    // error, and not a reason to fail the chunk.
    ($this->put)('assets', 'gone.pdf', 'pdf/untagged.pdf');

    $job = new CheckDocuments(['documents::gone.pdf', 'documents::never-existed.pdf']);
    Storage::disk('assets')->delete('gone.pdf');

    $job->handle(app(AssetChecker::class));

    expect(DocumentCheck::count())->toBe(0);
});

it('checks the documents in a chunk', function () {
    ($this->put)('assets', 'a.pdf', 'pdf/untagged.pdf');
    ($this->put)('assets', 'b.pdf', 'pdf/no-lang.pdf');

    (new CheckDocuments(['documents::a.pdf', 'documents::b.pdf']))->handle(app(AssetChecker::class));

    expect(DocumentCheck::count())->toBe(2)
        ->and(DocumentCheck::pluck('status')->all())->toBe([Status::Fail, Status::Fail]);
});
