<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    foreach ([DocumentInspector::class, AssetChecker::class, DocumentScanner::class] as $binding) {
        app()->forgetInstance($binding);
    }

    Storage::fake('assets');

    // The field on the container's asset blueprint, which is what puts it in
    // the browser: BrowserController::setColumns() starts from
    // $container->blueprint()->columns().
    Blueprint::make('documents')->setNamespace('assets')->setContents([
        'tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'a11y', 'field' => ['type' => 'a11y_document_status', 'display' => 'Accessibility', 'listable' => true]],
        ]]]]],
    ])->save();

    $this->container = AssetContainer::make('documents')->disk('assets')->save();

    $this->put = function (string $path, string $fixture) {
        Storage::disk('assets')->put($path, (string) file_get_contents(corpusPath($fixture)));
    };
});

it('knows which asset it is attached to', function () {
    // The question the spike turned on. A fieldtype that cannot resolve its own
    // asset cannot show a per-asset badge, and the whole approach falls over.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $asset = $this->container->asset('handbook.pdf');
    $field = $asset->blueprint()->field('a11y');

    expect($field)->not->toBeNull()
        ->and($field->parent())->not->toBeNull()
        ->and($field->parent()->id())->toBe('documents::handbook.pdf');
});

it('reports a failing document in red', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $value = $this->container->asset('handbook.pdf')->blueprint()->field('a11y')->fieldtype()->preProcess(null);

    expect($value['status'])->toBe('fail')
        ->and($value['severity'])->toBe('critical')
        ->and($value['colour'])->toBe('red')
        ->and($value['label'])->toContain('problem');
});

it('reports a clean document in green', function () {
    ($this->put)('policy.docx', 'docx/good.docx');
    $this->artisan('docs:check --sync');

    $value = $this->container->asset('policy.docx')->blueprint()->field('a11y')->fieldtype()->preProcess(null);

    expect($value['status'])->toBe('pass')
        ->and($value['colour'])->toBe('green')
        ->and($value['label'])->toBe('No problems found');
});

it('says a document has not been checked rather than saying it is fine', function () {
    // The difference matters: an unchecked document is not a passing one.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');

    $value = $this->container->asset('handbook.pdf')->blueprint()->field('a11y')->fieldtype()->preProcess(null);

    expect($value['status'])->toBeNull()
        ->and($value['colour'])->toBe('gray')
        ->and($value['label'])->toBe('Not checked');
});

it('stores nothing back to the asset', function () {
    // The database is the source of truth. Writing this into .meta.yaml would
    // mean a status that can be stale and a scan that rewrites the library.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $fieldtype = $this->container->asset('handbook.pdf')->blueprint()->field('a11y')->fieldtype();

    expect($fieldtype->process('anything at all'))->toBeNull();
});

it('ships with every row the asset browser renders', function () {
    // BrowserController builds its columns from the container blueprint, and
    // the row payload merges the blueprint's pre-processed values — so a badge
    // needs no JavaScript extension point at all.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $columns = $this->container->blueprint()->columns()->keys()->all();

    expect($columns)->toContain('a11y');
});

it('lists what is wrong and where, for the asset\'s own screen', function () {
    // Spec §9's detail panel. The location is what turns "890 untagged PDFs"
    // into something a person can go and fix.
    ($this->put)('form.pdf', 'pdf/form-unlabelled-fields.pdf');
    $this->artisan('docs:check --sync');

    $value = $this->container->asset('form.pdf')->blueprint()->field('a11y')->fieldtype()->preProcess(null);

    expect($value['problems'])->toHaveCount(2)
        ->and($value['problems'][0]['rule'])->toBe('pdf.unlabelled_form_fields')
        ->and($value['problems'][0]['where'])->toBe('page 1, field2')
        ->and($value['engine'])->toStartWith('heuristics ');
});

it('sorts what is wrong worst first', function () {
    ($this->put)('scan.pdf', 'pdf/image-only-scan.pdf');
    $this->artisan('docs:check --sync');

    $value = $this->container->asset('scan.pdf')->blueprint()->field('a11y')->fieldtype()->preProcess(null);
    $severities = array_column($value['problems'], 'severity');

    expect($severities)->toBe(['critical', 'critical', 'serious', 'serious', 'serious']);
});

it('shows what could not be checked separately from what passed', function () {
    // "We could not look" is not "we found nothing", and an asset's own screen
    // is exactly where that distinction has to survive.
    ($this->put)('locked.pdf', 'pdf/encrypted-no-extract.pdf');
    $this->artisan('docs:check --sync');

    $value = $this->container->asset('locked.pdf')->blueprint()->field('a11y')->fieldtype()->preProcess(null);

    expect(array_column($value['unchecked'], 'rule'))->toContain('pdf.no_title')
        ->and($value['unchecked'][0]['reason'])->toContain('encrypted');
});
