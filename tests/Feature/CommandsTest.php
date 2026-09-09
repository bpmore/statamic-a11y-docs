<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentExemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    foreach ([DocumentInspector::class, AssetChecker::class, DocumentScanner::class] as $binding) {
        app()->forgetInstance($binding);
    }

    Storage::fake('assets');
    AssetContainer::make('documents')->disk('assets')->save();

    $this->put = function (string $path, string $fixture) {
        Storage::disk('assets')->put($path, (string) file_get_contents(corpusPath($fixture)));
    };
});

it('queues a scan by default', function () {
    Bus::fake();
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');

    $this->artisan('docs:check')
        ->expectsOutputToContain('Queued')
        ->assertExitCode(0);

    Bus::assertBatchCount(1);
});

it('checks the library there and then with --sync', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('policy.docx', 'docx/good.docx');

    $this->artisan('docs:check --sync')
        ->expectsOutputToContain('handbook.pdf')
        ->expectsOutputToContain('Checked 2 documents')
        ->assertExitCode(0);

    expect(DocumentCheck::count())->toBe(2);
});

it('exits zero on a failing library unless asked to care', function () {
    // A site installing this has 890 existing problems. Failing the build by
    // default would make the command unusable on day one, which is the same
    // mistake spec §8 warns about for the publish gate.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');

    $this->artisan('docs:check --sync')->assertExitCode(0);
});

it('fails the build when told what to fail on', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');

    $this->artisan('docs:check --sync --fail-on=critical')
        ->expectsOutputToContain('at or above critical')
        ->assertExitCode(1);
});

it('passes the build when nothing meets the threshold', function () {
    // untagged.pdf is critical and nothing else, so a serious-and-above
    // threshold catches it but a critical-only one on a clean library does not.
    ($this->put)('policy.docx', 'docx/good.docx');

    $this->artisan('docs:check --sync --fail-on=critical')
        ->expectsOutputToContain('Nothing at or above critical')
        ->assertExitCode(0);
});

it('refuses a severity it does not recognise', function () {
    $this->artisan('docs:check --sync --fail-on=catastrophic')
        ->expectsOutputToContain('Unknown severity')
        ->assertExitCode(2);
});

it('says how much of the library it did not have to re-check', function () {
    // Spec §7: a 1,240-PDF library must not be reprocessed nightly, so the
    // command says how much of it was not.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');

    $this->artisan('docs:check --sync')->assertExitCode(0);
    $this->artisan('docs:check --sync')
        ->expectsOutputToContain('unchanged since the last run')
        ->assertExitCode(0);
});

it('reports what the last scan found', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('scan.pdf', 'pdf/image-only-scan.pdf');
    ($this->put)('policy.docx', 'docx/good.docx');
    $this->artisan('docs:check --sync');

    $this->artisan('docs:report')
        ->expectsOutputToContain('2 PDFs')
        ->expectsOutputToContain('pdf.not_tagged')
        ->expectsOutputToContain('Checked by heuristics')
        ->assertExitCode(0);
});

it('reports as JSON for anything that has to read it', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $this->artisan('docs:report --json')->assertExitCode(0);
});

it('says so when there is nothing to report', function () {
    $this->artisan('docs:report')
        ->expectsOutputToContain('No documents have been checked yet')
        ->assertExitCode(0);
});

it('prunes results for documents that are gone', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('policy.docx', 'docx/good.docx');
    $this->artisan('docs:check --sync');

    // Deleted the way a person deletes one, so Statamic's own listing knows.
    Asset::find('documents::handbook.pdf')->delete();

    $this->artisan('docs:prune')
        ->expectsOutputToContain('documents::handbook.pdf')
        ->expectsOutputToContain('Removed 1 result')
        ->assertExitCode(0);

    expect(DocumentCheck::pluck('asset_id')->all())->toBe(['documents::policy.docx']);
});

it('shows what it would prune without pruning it', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    Asset::find('documents::handbook.pdf')->delete();

    $this->artisan('docs:prune --dry-run')
        ->expectsOutputToContain('would be removed')
        ->assertExitCode(0);

    expect(DocumentCheck::count())->toBe(1);
});

it('keeps exemptions for documents that are gone, and says so', function () {
    // Spec §8 asks for an audit trail. A trail that deletes itself when the
    // document goes is not one: "who exempted the thing that is no longer
    // there, and why" is exactly the question an auditor asks.
    DocumentExemption::create([
        'asset_id' => 'documents::vanished.pdf',
        'reason' => 'Superseded by the 2027 edition.',
        'granted_by' => 'user-1',
        'granted_at' => now(),
    ]);

    $this->artisan('docs:prune')
        ->expectsOutputToContain('refers to a document that no longer exists')
        ->expectsOutputToContain('Kept on purpose: they are the audit trail')
        ->assertExitCode(0);

    expect(DocumentExemption::count())->toBe(1);
});

it('says how many documents it queued, not how many jobs', function () {
    // The batch knows about jobs and nothing about documents, and "queued 1
    // job" is not the number anybody wants to read.
    Bus::fake();
    foreach (range(1, 4) as $n) {
        ($this->put)("report-$n.pdf", 'pdf/untagged.pdf');
    }

    $this->artisan('docs:check')
        ->expectsOutputToContain('Queued 4 documents in 1 job.')
        ->assertExitCode(0);
});

it('says so when there is nothing to queue', function () {
    Bus::fake();

    $this->artisan('docs:check')
        ->expectsOutputToContain('No documents found')
        ->assertExitCode(0);
});
