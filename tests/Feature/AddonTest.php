<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Pdf\AugmentedPdfInspector;
use Bpmore\DocumentA11yCore\Pdf\PdfInspector;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\StatamicA11yDocs\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Statamic\CP\Navigation\Nav as StatamicNav;
use Statamic\Facades\Addon;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

it('registers itself with Statamic', function () {
    $addon = Addon::get('bpmore/statamic-a11y-docs');

    expect($addon)->not->toBeNull()
        // The repository is named for the platform; the product is not. Spec §1
        // asks for a11y-docs in the control panel, the config and the commands.
        ->and($addon->slug())->toBe('a11y-docs');

    // The display name is deliberately not asserted here. Testbench builds a
    // cut-down manifest for the addon under test — id, slug, version,
    // namespace, autoload, provider and nothing else — so extra.statamic.name
    // is only read on a real install. It is checked against a real install
    // instead.
});

it('publishes its configuration under the name the product uses', function () {
    expect(config('a11y-docs.verapdf.profile'))->toBe('ua1')
        ->and(config('a11y-docs.verapdf.enabled'))->toBeTrue()
        ->and(config('a11y-docs.verapdf.timeout'))->toBe(60);
});

it('creates its tables when the site migrates', function () {
    // Loaded rather than published, so a site gets them without a step somebody
    // has to remember.
    expect(Schema::hasTable('document_checks'))->toBeTrue()
        ->and(Schema::hasTable('document_findings'))->toBeTrue()
        ->and(Schema::hasTable('document_exemptions'))->toBeTrue()
        ->and(Schema::hasColumn('document_checks', 'file_hash'))->toBeTrue()
        ->and(Schema::hasColumn('document_checks', 'unchecked'))->toBeTrue();
});

it('resolves a document inspector wired from configuration', function () {
    $inspector = app(DocumentInspector::class);

    expect($inspector)->toBeInstanceOf(DocumentInspector::class)
        ->and($inspector->inspectorFor(Format::Pdf))->toBeInstanceOf(AugmentedPdfInspector::class)
        // Resolved once: working out whether veraPDF is installed costs a JVM
        // start, and doing that per document would be absurd.
        ->and(app(DocumentInspector::class))->toBe($inspector);
});

it('leaves PDF/UA validation out when it is turned off', function () {
    config()->set('a11y-docs.verapdf.enabled', false);
    app()->forgetInstance(DocumentInspector::class);

    expect(app(DocumentInspector::class)->inspectorFor(Format::Pdf))
        ->toBeInstanceOf(PdfInspector::class);
});

it('checks a real document through the container', function () {
    // The whole stack, from a path to a verdict, inside a booted Statamic.
    $result = app(DocumentInspector::class)->inspect(corpusPath('pdf/untagged.pdf'));

    expect($result->status)->toBe(Status::Fail)
        ->and($result->findingsFor('pdf.not_tagged'))->toHaveCount(1)
        ->and($result->engine)->not->toBeNull();
});

it('registers its control panel nav item against a real Nav', function () {
    // The entire control panel went down — every page, not just this addon's —
    // because bootNavigation() called NavItem::active(), which Statamic 5 had
    // and Statamic 6 does not. All 551 tests stayed green, because
    // Statamic\Testing\AddonTestCase mocks Nav::build() to return an empty
    // collection: an addon's own nav registration is unreachable from its own
    // test suite unless the mock is stepped around, as it is here.
    // CoreNav::make() asks for the current user while building the base items.
    $this->actingAs(User::make()->id('nav-tester')->email('nav@example.edu')->makeSuper()->save());

    $real = new StatamicNav;
    Nav::swap($real);

    $provider = new ServiceProvider(app());
    $bootNavigation = new ReflectionMethod($provider, 'bootNavigation');
    $bootNavigation->setAccessible(true);
    $bootNavigation->invoke($provider);          // throws if we call a method Statamic 6 dropped

    $makeBaseItems = new ReflectionMethod($real, 'makeBaseItems');
    $makeBaseItems->setAccessible(true);

    $ours = collect($makeBaseItems->invoke($real))
        ->filter(fn ($item) => str_contains((string) $item->url(), 'a11y-docs'));

    expect($ours)->not->toBeEmpty('the addon registered no control panel nav item');
    expect((string) $ours->first()->name())->not->toBeEmpty();

    // Under Tools, with the other checks, and named for what it is. It was
    // "Documents" under Content, which read as a place to manage documents
    // and sat next to Assets, where the documents actually live. Nothing on
    // these pages creates or edits a document; they check them.
    expect($ours->first()->section())->toBe('Tools')
        ->and((string) $ours->first()->display())->toBe('Document checks');
});

it('ships a Vite manifest where the control panel looks for it', function () {
    // Statamic's AddonServiceProvider::registerVite publishes
    // <publicDirectory>/<buildDirectory>/ and points Laravel's Vite at
    // vendor/<package>/<buildDirectory>. Laravel then reads
    // <buildDirectory>/manifest.json. Vite 6 writes .vite/manifest.json unless
    // told otherwise, and the mismatch throws "Vite manifest not found" on
    // every control panel page.
    $build = repoPath('resources/dist/build');

    expect(is_file($build.'/manifest.json'))
        ->toBeTrue('no manifest.json in resources/dist/build — run `npm run build`');

    $manifest = json_decode((string) file_get_contents($build.'/manifest.json'), true);

    expect($manifest)->toBeArray()->not->toBeEmpty();

    foreach ($manifest as $entry) {
        if (isset($entry['file'])) {
            expect(is_file($build.'/'.$entry['file']))
                ->toBeTrue("manifest names {$entry['file']}, which is not in the published build");
        }
    }
});

it('uses an icon that exists, and not the one Assets already uses', function () {
    // A missing icon name renders as nothing at all — a blank square in the
    // sidebar, no error anywhere. And 'assets' is what Statamic's own Assets
    // item uses, so picking it put the same picture in the nav twice.
    //
    // The names are pulled out of the calls rather than searched for in the
    // file: the first version of this test looked for the string anywhere in
    // the source and passed against a deliberately misspelt icon, because the
    // comment above the call still contained the correct spelling.
    $icons = dirname((new ReflectionClass(Statamic\Statamic::class))->getFileName(), 2).'/resources/svg/icons';
    expect(is_dir($icons))->toBeTrue("Statamic's icon set is not where it was expected: $icons");

    $used = [];
    foreach ([
        'src/ServiceProvider.php' => '/->icon\(\s*[\'"]([a-z0-9-]+)[\'"]\s*\)/',
        'resources/js/pages/Dashboard.vue' => '/\bicon="([a-z0-9-]+)"/',
        'resources/js/pages/Queue.vue' => '/\bicon="([a-z0-9-]+)"/',
    ] as $file => $pattern) {
        $source = (string) file_get_contents(repoPath($file));
        expect($source)->not->toBe('');

        expect(preg_match_all($pattern, $source, $matches))->toBeGreaterThan(0, "no icon call found in $file");

        foreach ($matches[1] as $icon) {
            $used[] = [$file, $icon];
        }
    }

    foreach ($used as [$file, $icon]) {
        expect(is_file($icons."/$icon.svg"))
            ->toBeTrue("$file uses '$icon', which is not in Statamic's icon set — it renders blank");
        expect($icon)->not->toBe('assets', "$file is back on the icon Statamic's own Assets item uses");
    }
});

it('pads every table cell to line up with its panel heading', function () {
    // Statamic's PanelHeader is px-4.5 (18px) and its table cells have none, so
    // rows sat 18px left of the heading above them. The first fix used a
    // px-4.5 class and did nothing: Statamic sets cell padding via a descendant
    // selector ([&_td]:px-N td, specificity 0,1,1) which outranks a utility on
    // the td itself (0,1,0) — and this addon's templates are not in Statamic's
    // Tailwind scan, so a class we choose may not exist in the built CSS at all.
    //
    // Hence an inline style, and hence this test: a cell added later without it
    // would silently sit out of line again.
    foreach (['resources/js/pages/Dashboard.vue', 'resources/js/pages/Queue.vue'] as $file) {
        $source = (string) file_get_contents(repoPath($file));
        expect($source)->not->toBe('');

        expect(str_contains($source, "paddingInline: '1.125rem'"))
            ->toBeTrue("$file does not define the cell padding");

        $cells = preg_match_all('/<TableCell\b([^>]*)>/', $source, $matches);
        expect($cells)->toBeGreaterThan(0, "no table cells found in $file");

        foreach ($matches[1] as $index => $attributes) {
            expect(str_contains($attributes, ':style="cellPadding"'))
                ->toBeTrue("TableCell #{$index} in $file is not padded, so it will not line up");
        }
    }
});

it('gives every badge a colour Statamic actually understands', function () {
    // Badge's prop is `color`, and it sets inheritAttrs: false. Every badge in
    // this addon passed `variant` — Statamic's Button prop, not Badge's — so it
    // was dropped without a word and the whole control panel rendered in
    // default grey. The red/amber/green traffic light is the feature the
    // listing leads on, and it had never once worked.
    //
    // The valid names are read out of Statamic's own bundle rather than copied
    // here, so this notices if the palette changes under us.
    $bundle = collect(glob(dirname((new ReflectionClass(Statamic\Statamic::class))->getFileName(), 2).'/resources/dist/build/assets/ui-*.js'))->first();
    expect($bundle)->not->toBeNull('could not find Statamic\'s ui bundle');

    $ui = (string) file_get_contents($bundle);
    $start = strpos($ui, 'Badge');
    expect($start)->not->toBeFalse();

    preg_match('/color:\{(?:[a-z]+:`bg-[^`]*`,?){3,}/', $ui, $palette);
    expect($palette)->not->toBeEmpty('could not read Badge\'s colour palette');

    preg_match_all('/([a-z]+):`bg-/', $palette[0], $found);
    $valid = array_merge($found[1], ['default']);
    // toContain() takes needles, not a message — a message passed as a second
    // argument becomes another needle and the assertion fails obscurely.
    foreach (['red', 'amber', 'green'] as $required) {
        expect(in_array($required, $valid, true))->toBeTrue("Badge no longer defines '$required'");
    }

    foreach (['pages/Dashboard.vue', 'pages/Queue.vue', 'fieldtypes/DocumentStatusIndex.vue', 'fieldtypes/DocumentStatusField.vue'] as $file) {
        $source = (string) file_get_contents(repoPath("resources/js/$file"));
        expect($source)->not->toBe('');

        // No Badge may carry `variant`: it is silently discarded.
        preg_match_all('/<Badge\b([^>]*)>/', $source, $badges);
        foreach ($badges[1] as $attributes) {
            expect(str_contains($attributes, 'variant'))
                ->toBeFalse("a Badge in $file still passes variant, which Badge ignores");
        }

        // Every literal colour handed to a Badge has to be one Badge knows.
        preg_match_all('/\bcolor="([a-z]+)"/', $source, $literal);
        foreach ($literal[1] as $colour) {
            if ($colour === 'color') {
                continue;   // a binding, checked through the maps below
            }
            expect(in_array($colour, $valid, true))
                ->toBeTrue("$file uses colour '$colour', which Badge does not define");
        }
    }

    // And the severity maps, which are what the tables bind to.
    foreach (['pages/Dashboard.vue', 'pages/Queue.vue', 'fieldtypes/DocumentStatusField.vue'] as $file) {
        $source = (string) file_get_contents(repoPath("resources/js/$file"));
        preg_match('/severityColor = \{(.*?)\}/s', $source, $map);
        expect($map)->not->toBeEmpty("$file has no severityColor map");

        preg_match_all("/'([a-z]+)'/", $map[1], $colours);
        foreach ($colours[1] as $colour) {
            expect(in_array($colour, $valid, true))
                ->toBeTrue("$file maps a severity to '$colour', which Badge does not define");
        }
    }
});

it('leaves the nav alone when its own route is missing', function () {
    // A route cache built before this addon was installed does not contain its
    // CP routes, and Nav::extend runs on every control panel request including
    // login. Unguarded, that took the whole control panel down with
    // "Route [statamic.cp.a11y-docs.dashboard] not defined" - the addon
    // breaking pages that have nothing to do with it. Seen on a Forge deploy.
    $this->actingAs(User::make()->id('nav-guard')->email('guard@example.edu')->makeSuper()->save());

    // Only this addon's route goes; Statamic's own must stay, or CoreNav fails
    // for an unrelated reason and the test proves nothing.
    $without = new RouteCollection;
    foreach (Route::getRoutes() as $route) {
        if ($route->getName() !== 'statamic.cp.a11y-docs.dashboard') {
            $without->add($route);
        }
    }
    Route::setRoutes($without);

    expect(Route::has('statamic.cp.a11y-docs.dashboard'))->toBeFalse()
        ->and(Route::has('statamic.cp.dashboard'))->toBeTrue();

    $real = new StatamicNav;
    Nav::swap($real);

    $provider = new ServiceProvider(app());
    $boot = new ReflectionMethod($provider, 'bootNavigation');
    $boot->setAccessible(true);
    $boot->invoke($provider);

    $makeBaseItems = new ReflectionMethod($real, 'makeBaseItems');
    $makeBaseItems->setAccessible(true);

    // The point is that this does not throw. Our item is simply absent.
    $ours = collect($makeBaseItems->invoke($real))
        ->filter(fn ($item) => str_contains((string) $item->url(), 'a11y-docs'));

    expect($ours)->toBeEmpty();
});
