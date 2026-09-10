<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Panel\GatePanelBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    foreach ([DocumentInspector::class, AssetChecker::class, DocumentScanner::class] as $binding) {
        app()->forgetInstance($binding);
    }

    Storage::fake('assets');
    AssetContainer::make('documents')->disk('assets')->save();
    Collections::make('pages')->save();

    $this->put = function (string $path, string $fixture) {
        Storage::disk('assets')->put($path, (string) file_get_contents(corpusPath($fixture)));
    };

    $this->page = fn (array $data) => Entry::make()->collection('pages')->slug('p')->data($data);
});

it('says nothing when an entry links to no documents', function () {
    expect(app(GatePanelBlock::class)(($this->page)(['title' => 'Plain'])))->toBeNull();
});

it('reports a critical document as an error, because that is what stops a publish', function () {
    // The gate reads HTML and cannot open a PDF, so without this it reports the
    // page as passing while this addon refuses the same save.
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $block = app(GatePanelBlock::class)(($this->page)(['attachments' => ['reports/handbook.pdf']]));

    expect($block['heading'])->toBe('Documents on this page')
        ->and($block['tone'])->toBe('error')
        ->and($block['link']['url'])->toContain('a11y-docs')
        ->and(implode(' ', $block['lines']))->toContain('reports/handbook.pdf')
        ->and(implode(' ', $block['lines']))->toContain('PDF · Not tagged');
});

it('reports a clean document without alarming anybody', function () {
    ($this->put)('reports/good.pptx', 'pptx/good.pptx');
    $this->artisan('docs:check --sync');

    $block = app(GatePanelBlock::class)(($this->page)(['attachments' => ['reports/good.pptx']]));

    expect($block['tone'])->toBe('default')
        ->and(implode(' ', $block['lines']))->toContain('nothing was found wrong');
});

it('says so when the documents have never been checked', function () {
    // Silence here would read as "checked, and fine".
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');

    $block = app(GatePanelBlock::class)(($this->page)(['attachments' => ['reports/handbook.pdf']]));

    expect($block['tone'])->toBe('warning')
        ->and(implode(' ', $block['lines']))->toContain('none of them checked yet');
});
