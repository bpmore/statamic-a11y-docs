<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Gate\PublishGate;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentExemption;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    foreach ([DocumentInspector::class, AssetChecker::class, DocumentScanner::class, PublishGate::class] as $binding) {
        app()->forgetInstance($binding);
    }

    Storage::fake('assets');
    AssetContainer::make('documents')->disk('assets')->save();
    Collections::make('pages')->save();

    $this->put = function (string $path, string $fixture) {
        Storage::disk('assets')->put($path, (string) file_get_contents(corpusPath($fixture)));
    };

    // Everything in these tests is "new", so grandfathering never hides a
    // result by accident. The grandfather tests set the date explicitly.
    $this->ungrandfathered = function () {
        config()->set('a11y-docs.gate.grandfather', false);
        app()->forgetInstance(PublishGate::class);
    };

    $this->page = function (array $data, bool $published = true) {
        return Entry::make()->collection('pages')->slug('policies')->published($published)->data($data);
    };
});

it('finds a document referenced by an assets field', function () {
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    $blockers = app(PublishGate::class)->blockers(($this->page)(['attachments' => ['reports/handbook.pdf']]));

    expect($blockers)->toHaveCount(1)
        ->and($blockers->first()->path)->toBe('reports/handbook.pdf');
});

it('finds a document linked from prose', function () {
    // Bard, markdown, redactor — a link in a paragraph is how most documents
    // reach a page, and a gate that only looked at assets fields would miss
    // nearly all of them.
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    $entry = ($this->page)([
        'body' => 'Read the <a href="/assets/documents/reports/handbook.pdf">handbook</a> before you start.',
    ]);

    expect(app(PublishGate::class)->blockers($entry))->toHaveCount(1);
});

it('lets an entry through when its documents are fine', function () {
    ($this->put)('reports/policy.docx', 'docx/good.docx');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    expect(app(PublishGate::class)->allows(($this->page)(['attachments' => ['reports/policy.docx']])))
        ->toBeTrue();
});

it('only blocks on findings at or above the threshold', function () {
    // Critical by default: a document nobody can read, not one that could be
    // tidier. no-lang.pdf is serious and nothing more.
    ($this->put)('reports/no-lang.pdf', 'pdf/no-lang.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    $entry = ($this->page)(['attachments' => ['reports/no-lang.pdf']]);

    expect(app(PublishGate::class)->allows($entry))->toBeTrue();

    config()->set('a11y-docs.gate.threshold', 'serious');
    app()->forgetInstance(PublishGate::class);

    expect(app(PublishGate::class)->allows($entry))->toBeFalse();
});

it('leaves the existing library alone', function () {
    // Spec §8, the decision the product lives or dies on: installing this on a
    // site with 1,240 bad PDFs must not make it impossible to publish anything.
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    // Grandfathering is on by default, and the document predates the scan.
    $entry = ($this->page)(['attachments' => ['reports/handbook.pdf']]);

    expect(app(PublishGate::class)->allows($entry))->toBeTrue();
});

it('gates a document uploaded after the line was drawn', function () {
    ($this->put)('reports/old.pdf', 'pdf/untagged.pdf');
    // Backdated, because a real backlog predates the install by years and a
    // test that writes both files in the same second is not testing anything.
    touch(Storage::disk('assets')->path('reports/old.pdf'), now()->subYear()->getTimestamp());

    ($this->put)('reports/new.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    config()->set('a11y-docs.gate.grandfather_before', now()->subMonth()->toIso8601String());
    app()->forgetInstance(PublishGate::class);

    expect(app(PublishGate::class)->allows(($this->page)(['attachments' => ['reports/old.pdf']])))->toBeTrue()
        ->and(app(PublishGate::class)->allows(($this->page)(['attachments' => ['reports/new.pdf']])))->toBeFalse();
});

it('does not block on a document somebody has exempted', function () {
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    $entry = ($this->page)(['attachments' => ['reports/handbook.pdf']]);

    expect(app(PublishGate::class)->allows($entry))->toBeFalse();

    DocumentExemption::create([
        'asset_id' => 'documents::reports/handbook.pdf',
        'reason' => 'Superseded by the 2027 edition; kept online for the archive only.',
        'granted_by' => 'user-1',
        'granted_at' => now(),
    ]);

    expect(app(PublishGate::class)->allows($entry))->toBeTrue();
});

it('starts blocking again when an exemption expires', function () {
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    DocumentExemption::create([
        'asset_id' => 'documents::reports/handbook.pdf',
        'reason' => 'Being remediated this quarter.',
        'granted_at' => now()->subMonths(4),
        'expires_at' => now()->subDay(),
    ]);

    expect(app(PublishGate::class)->allows(($this->page)(['attachments' => ['reports/handbook.pdf']])))
        ->toBeFalse();
});

it('says what is wrong and how to get past it', function () {
    // "Blocked" with no route forward is how a gate becomes a thing people
    // disable.
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    $gate = app(PublishGate::class);
    $lines = $gate->explain($gate->blockers(($this->page)(['attachments' => ['reports/handbook.pdf']])));

    expect($lines[0])->toContain('reports/handbook.pdf')
        ->and($lines[0])->toContain('no tags')
        ->and(end($lines))->toContain('exempt it with a reason');
});

it('blocks the save, with the reason attached', function () {
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    $entry = ($this->page)(['attachments' => ['reports/handbook.pdf']]);

    try {
        $entry->save();
        $this->fail('the entry should not have saved');
    } catch (ValidationException $exception) {
        $key = array_key_first($exception->errors());

        expect($exception->errors()[$key][0])->toContain('cannot read');
    }
});

it('attaches the refusal to a field the control panel will actually show', function () {
    // Statamic renders a validation error next to the blueprint field it is
    // keyed to, and drops it silently otherwise. Keyed to 'published' — which
    // is a toggle, not a field — the whole thing vanished: clicking Save &
    // Publish did nothing and said nothing, with no console error and no toast,
    // which is precisely what this listener's docblock says is worse than
    // having no gate at all. Confirmed in a browser, not deduced.
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    $entry = ($this->page)(['attachments' => ['reports/handbook.pdf']]);

    try {
        $entry->save();
        $this->fail('the entry should not have saved');
    } catch (ValidationException $exception) {
        $keys = array_keys($exception->errors());
        expect($keys)->toHaveCount(1);

        $fields = $entry->blueprint()->fields()->all();
        expect($fields->has($keys[0]))
            ->toBeTrue("the gate keys its error to '{$keys[0]}', which is not a field in the blueprint, so the control panel will not display it");
    }
});

it('does not stand in the way of a draft', function () {
    // Somebody's work in progress. Blocking that would be officious, and the
    // gate is about publication.
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    $draft = ($this->page)(['attachments' => ['reports/handbook.pdf']], published: false);

    expect($draft->save())->toBeTrue();
});

it('can be turned off entirely', function () {
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    config()->set('a11y-docs.gate.grandfather', false);
    config()->set('a11y-docs.gate.enabled', false);
    app()->forgetInstance(PublishGate::class);

    expect(app(PublishGate::class)->allows(($this->page)(['attachments' => ['reports/handbook.pdf']])))
        ->toBeTrue();
});

it('writes two findings as two sentences, not one run-on', function () {
    // With veraPDF off, untagged.pdf yields a single critical finding, so the
    // join between findings is never exercised and the existing tests could not
    // see it. On a veraPDF-enabled site the message read
    // "...navigate the document at all PDF/UA 7.1: Content shall be..." — two
    // findings welded into one clause, in the one message whose whole job is to
    // be read.
    ($this->put)('reports/handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    ($this->ungrandfathered)();

    DocumentFinding::create([
        'check_id' => DocumentCheck::where('path', 'reports/handbook.pdf')->firstOrFail()->id,
        'rule_id' => 'pdf.ua_7_1',
        'severity' => 'critical',
        'message' => 'PDF/UA 7.1: Content shall be marked as Artifact or tagged as real content.',
    ]);

    $gate = app(PublishGate::class);
    $line = $gate->explain($gate->blockers(($this->page)(['attachments' => ['reports/handbook.pdf']])))[0];

    expect(str_contains($line, 'at all PDF/UA'))
        ->toBeFalse("two findings ran together with no full stop: $line");
    expect(str_contains($line, '. PDF/UA 7.1:'))
        ->toBeTrue("expected a sentence break before the second finding: $line");
    expect(str_ends_with($line, '.'))
        ->toBeTrue("the line does not end in a full stop: $line");
});
