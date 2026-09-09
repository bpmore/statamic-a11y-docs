<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Ooxml\Excel\XlsxRule;
use Bpmore\DocumentA11yCore\Ooxml\PowerPoint\PptxRule;
use Bpmore\DocumentA11yCore\Ooxml\Word\DocxRule;
use Bpmore\DocumentA11yCore\Pdf\PdfRule;
use Bpmore\DocumentA11yCore\Version;
use Bpmore\StatamicA11yDocs\Actions\ExemptDocument;
use Bpmore\StatamicA11yDocs\Actions\RecheckDocument;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Bpmore\StatamicA11yDocs\DocumentScanner;
use Bpmore\StatamicA11yDocs\Gate\PublishGate;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentExemption;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Bpmore\StatamicA11yDocs\Reporting\LibrarySummary;
use Bpmore\StatamicA11yDocs\RuleLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    foreach ([DocumentInspector::class, AssetChecker::class, DocumentScanner::class, PublishGate::class] as $binding) {
        app()->forgetInstance($binding);
    }

    Storage::fake('assets');
    $this->container = AssetContainer::make('documents')->disk('assets')->save();

    $this->put = function (string $path, string $fixture) {
        Storage::disk('assets')->put($path, (string) file_get_contents(corpusPath($fixture)));
    };

    $this->user = User::make()->id('tester')->email('tester@example.edu')->makeSuper()->save();
});

it('shows the dashboard', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('scan.pdf', 'pdf/image-only-scan.pdf');
    ($this->put)('policy.docx', 'docx/good.docx');
    $this->artisan('docs:check --sync');

    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('a11y-docs/Dashboard')
            ->where('total', 3)
            ->where('statuses.fail', 2)
            ->where('statuses.pass', 1)
            ->where('formats.pdf.total', 2)
            ->where('rules.0.rule', 'pdf.not_tagged')
            ->where('rules.0.documents', 2)
            ->where('engines.0.engine', 'heuristics '.Version::CURRENT)
        );
});

it('says plainly that the backlog is not blocking anything', function () {
    // Spec §8: "We found 890 existing issues. Publishing isn't blocked for
    // these — here's your remediation queue." A site that opens this and sees
    // 890 problems with no context assumes it is now broken.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('grandfathered', 1)
            ->where('gate.grandfathering', true)
            ->where('gate.threshold', 'critical')
        );
});

it('counts nothing as grandfathered when grandfathering is off', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    config()->set('a11y-docs.gate.grandfather', false);

    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.dashboard'))
        ->assertInertia(fn ($page) => $page->where('grandfathered', 0));
});

it('lists the queue worst first', function () {
    // Findings, not documents: the unit of work is "this figure has no
    // alternative text", not "this file has eleven problems".
    ($this->put)('scan.pdf', 'pdf/image-only-scan.pdf');
    $this->artisan('docs:check --sync');

    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.queue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('a11y-docs/Queue')
            ->where('findings.data.0.severity', 'critical')
            ->has('options.severities')
            ->has('options.rules')
        );
});

it('filters the queue by rule, so fifty documents can be fixed in an afternoon', function () {
    ($this->put)('scan.pdf', 'pdf/image-only-scan.pdf');
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.queue', ['rule' => 'pdf.not_tagged']))
        ->assertInertia(fn ($page) => $page
            ->where('findings.total', 2)
            ->where('filters.rule', 'pdf.not_tagged')
        );
});

it('filters the queue by severity', function () {
    ($this->put)('scan.pdf', 'pdf/image-only-scan.pdf');
    $this->artisan('docs:check --sync');

    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.queue', ['severity' => 'serious']))
        ->assertInertia(fn ($page) => $page->where('findings.total', 3));
});

it('re-checks a document somebody has just fixed', function () {
    // Forced: the hash has not changed if they fixed it somewhere else, and
    // "nothing happened" is not the answer somebody clicking this wants.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $checkedAt = DocumentCheck::first()->checked_at;

    $result = (new RecheckDocument)->run(
        collect([$this->container->asset('handbook.pdf')]),
        [],
    );

    expect($result)->toContain('still need')
        ->and(DocumentCheck::first()->checked_at->greaterThanOrEqualTo($checkedAt))->toBeTrue();
});

it('exempts a document, with the reason and who granted it', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    $action = new RecheckDocument;
    expect($action->visibleTo($this->container->asset('handbook.pdf')))->toBeTrue();

    $this->actingAs($this->user);

    (new ExemptDocument)->run(
        collect([$this->container->asset('handbook.pdf')]),
        ['reason' => 'Superseded by the 2027 edition; kept for the archive.', 'expires_at' => null],
    );

    $exemption = DocumentExemption::first();

    expect($exemption->asset_id)->toBe('documents::handbook.pdf')
        ->and($exemption->reason)->toContain('Superseded')
        ->and($exemption->granted_by)->toBe('tester')
        ->and($exemption->granted_at)->not->toBeNull()
        ->and($exemption->isActive())->toBeTrue();
});

it('requires a reason that says something', function () {
    // An exemption nobody has to justify is a way of turning the addon off one
    // file at a time.
    $fields = (new ExemptDocument)->fields();

    expect($fields->get('reason')->get('validate'))->toBe('required|min:10');
});

it('exempts several documents at once', function () {
    ($this->put)('a.pdf', 'pdf/untagged.pdf');
    ($this->put)('b.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');
    $this->actingAs($this->user);

    (new ExemptDocument)->run(
        collect([$this->container->asset('a.pdf'), $this->container->asset('b.pdf')]),
        ['reason' => 'Both are being remediated by the comms team this quarter.'],
    );

    expect(DocumentExemption::count())->toBe(2);
});

it('writes a headline in words, never a rule id', function () {
    // The headline is the most prominent string in the product, and a rule id is
    // not English. It shipped reading `13 with "ua 7 21 4 1 1"` because
    // describeRule() prettified any id it did not recognise.
    //
    // veraPDF is off in tests, so its clause rules are seeded directly here:
    // without them the fallback is never reached and this test proves nothing.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('scan.pdf', 'pdf/image-only-scan.pdf');
    ($this->put)('deck.pptx', 'pptx/shape-missing-alt.pptx');
    $this->artisan('docs:check --sync');

    foreach (DocumentCheck::all() as $check) {
        foreach (['pdf.ua_7_21_4_1_1', 'pdf.ua_7_1_8'] as $ruleId) {
            DocumentFinding::create([
                'check_id' => $check->id,
                'rule_id' => $ruleId,
                'severity' => 'serious',
                'message' => 'Seeded veraPDF clause finding.',
            ]);
        }
    }

    $summary = new LibrarySummary;

    // The clause rules are genuinely the most common, so a headline that ranks
    // purely by count would reach for them first.
    expect(array_key_first($summary->topRules(5)))->toStartWith('pdf.ua_');

    $headline = $summary->headline();

    expect(str_contains($headline, '_'))
        ->toBeFalse("an underscore means a rule id leaked in: $headline");
    expect(preg_match('/\bua[ _]?\d/i', $headline) === 1)
        ->toBeFalse("headline names a PDF/UA clause number: $headline");
    expect(preg_match('/\b(pdf|docx|pptx|xlsx)\./i', $headline) === 1)
        ->toBeFalse("headline carries a dotted rule id: $headline");
    expect(str_contains($headline, '"'))
        ->toBeFalse("headline quotes a raw rule name: $headline");

    // And it still says something useful rather than going quiet.
    expect($headline)->toContain('untagged');
});

it('leads the headline with the format there is most of', function () {
    // Spec §9 wants "1,240 PDFs · 890 untagged · 210 image-only scans" — the
    // biggest liability first, not an alphabetical parade of every format.
    ($this->put)('a.pdf', 'pdf/untagged.pdf');
    ($this->put)('b.pdf', 'pdf/no-lang.pdf');
    ($this->put)('c.pdf', 'pdf/no-title.pdf');
    ($this->put)('one.docx', 'docx/good.docx');
    $this->artisan('docs:check --sync');

    expect((new LibrarySummary)->headline())->toStartWith('3 PDFs');
});

it('folds a long tail of formats up rather than listing every one', function () {
    ($this->put)('a.pdf', 'pdf/untagged.pdf');
    ($this->put)('b.docx', 'docx/no-headings.docx');
    ($this->put)('c.pptx', 'pptx/slide-missing-title.pptx');
    ($this->put)('d.xlsx', 'xlsx/default-sheet-names.xlsx');
    ($this->put)('e.doc', 'legacy/handbook.doc');
    $this->artisan('docs:check --sync');

    $headline = (new LibrarySummary)->headline();

    // Three formats named, the remainder counted rather than listed.
    expect(str_contains($headline, 'other format'))
        ->toBeTrue("expected a folded remainder: $headline");
});

it('has plain words for every rule it can report', function () {
    // A rule with no phrase is silently dropped from the headline, which is the
    // right behaviour for veraPDF clause ids but wrong for our own rules: those
    // would just quietly stop appearing. Every one of them must have words.
    $rules = [];
    foreach ([
        PdfRule::cases(),
        DocxRule::cases(),
        PptxRule::cases(),
        XlsxRule::cases(),
    ] as $family) {
        foreach ($family as $rule) {
            $rules[] = $rule->value;
        }
    }

    $describe = new ReflectionMethod(LibrarySummary::class, 'describeRule');
    $describe->setAccessible(true);

    $missing = array_values(array_filter(
        $rules,
        fn (string $id): bool => $describe->invoke(null, $id) === null,
    ));

    expect($missing)->toBe([], 'rules with no plain-words phrase: '.implode(', ', $missing));
});

it('sends filter options in the shape Statamic\'s Select can render', function () {
    // Statamic's Select reads `label` and `value` off each option object. Given
    // bare strings it renders a listbox of blank rows: on screen the filters
    // were an empty white panel covering the table, with no text in it at all.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('policy.docx', 'docx/no-headings.docx');
    $this->artisan('docs:check --sync');

    $options = null;
    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.queue'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$options) {
            $options = $page->toArray()['props']['options'];
        });

    foreach (['severities', 'statuses', 'rules', 'formats', 'containers'] as $set) {
        expect($options[$set])->not->toBeEmpty("no $set to filter by");

        foreach ($options[$set] as $option) {
            expect($option)->toBeArray()->toHaveKeys(['value', 'label'], "$set is not option objects");
            expect((string) $option['label'])->not->toBe('', "an option in $set has no label");
            expect((string) $option['value'])->not->toBe('', "an option in $set has no value");
        }
    }
});

it('labels a PDF/UA clause as a citation rather than a mangled id', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    $this->artisan('docs:check --sync');

    DocumentFinding::create([
        'check_id' => DocumentCheck::first()->id,
        'rule_id' => 'pdf.ua_7_21_4_1_1',
        'severity' => 'moderate',
        'message' => 'Seeded veraPDF clause finding.',
    ]);

    $rules = null;
    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.queue'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$rules) {
            $rules = collect($page->toArray()['props']['options']['rules'])->keyBy('value');
        });

    expect($rules['pdf.ua_7_21_4_1_1']['label'])->toBe('PDF/UA 7.21.4.1.1');
    expect($rules['pdf.not_tagged']['label'])->toBe('PDF · Not tagged');
});

it('labels a rule the same way on the dashboard as in the queue', function () {
    // These drifted once: the queue filter read "PDF · Not tagged" while the
    // dashboard printed `pdf.not_tagged` beside it. Both now go through
    // RuleLabel, and this fails if either stops.
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('policy.docx', 'docx/image-missing-alt.docx');
    $this->artisan('docs:check --sync');

    DocumentFinding::create([
        'check_id' => DocumentCheck::first()->id,
        'rule_id' => 'pdf.ua_7_1_8',
        'severity' => 'serious',
        'message' => 'Seeded veraPDF clause finding.',
    ]);

    $dashboard = null;
    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$dashboard) {
            $dashboard = collect($page->toArray()['props']['rules'])->pluck('label', 'rule');
        });

    $queue = null;
    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.queue'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$queue) {
            $queue = collect($page->toArray()['props']['options']['rules'])->pluck('label', 'value');
        });

    expect($dashboard)->not->toBeEmpty();

    foreach ($dashboard as $rule => $label) {
        expect((string) $label)->not->toBe($rule, "the dashboard is still printing the raw id for $rule");
        expect($queue[$rule] ?? null)->toBe($label, "the two screens disagree about $rule");
    }
});

it('shows the rule in words in the queue table, not the id', function () {
    ($this->put)('handbook.pdf', 'pdf/untagged.pdf');
    ($this->put)('policy.docx', 'docx/no-headings.docx');
    $this->artisan('docs:check --sync');

    $rows = null;
    $this->actingAs($this->user)
        ->get(cp_route('a11y-docs.queue'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$rows) {
            $rows = $page->toArray()['props']['findings']['data'];
        });

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        // The id is still there — it is what the filter and the URL address.
        expect($row)->toHaveKeys(['rule_id', 'rule_label']);
        expect($row['rule_label'])->toBe(RuleLabel::for($row['rule_id']));
        expect($row['rule_label'])->not->toBe($row['rule_id']);
        expect(str_contains((string) $row['rule_label'], '_'))
            ->toBeFalse("the table is still showing an id: {$row['rule_label']}");
    }
});
