<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs;

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Inspector;
use Bpmore\DocumentA11yCore\LegacyOfficeInspector;
use Bpmore\DocumentA11yCore\Ooxml\OoxmlInspector;
use Bpmore\DocumentA11yCore\Pdf\AugmentedPdfInspector;
use Bpmore\DocumentA11yCore\Pdf\PdfInspector;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\VeraPdf\ProcessVeraPdfRunner;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfInspector;
use Bpmore\DocumentA11yCore\VeraPdf\VeraPdfOptions;
use Bpmore\StatamicA11yDocs\Gate\DocumentReferences;
use Bpmore\StatamicA11yDocs\Gate\PublishGate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Statamic\Events\EntrySaving;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $listen = [
        EntrySaving::class => [
            Listeners\GateEntryPublishing::class,
        ],
    ];

    protected $actions = [
        Actions\RecheckDocument::class,
        Actions\ExemptDocument::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => ['resources/js/cp.js'],
        'publicDirectory' => 'resources/dist',
    ];

    // Spec §1: the product is a11y-docs everywhere a person sees it, never the
    // repository name.
    protected $viewNamespace = 'a11y-docs';

    protected $fieldtypes = [
        Fieldtypes\DocumentStatus::class,
    ];

    protected $commands = [
        Commands\CheckDocuments::class,
        Commands\InstallDocuments::class,
        Commands\PruneDocuments::class,
        Commands\ReportDocuments::class,
    ];

    public function bootAddon(): void
    {
        $this->bootPermissions();
        $this->bootNavigation();

        // The addon's own SQLite connection, unless the site named one of its
        // own. Defined here rather than in register() because it reads the
        // merged config.
        $this->app->make(Storage\DocumentDatabase::class)->defineDefaultConnection();

        // Deliberately not loadMigrationsFrom: that would run these against the
        // app's default connection under `php please migrate` while
        // `docs:install` runs them against the configured one, and the two
        // would keep separate records of what has run. One path, one record.

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'a11y-docs-migrations');
    }

    /**
     * Three permissions, spec §9.
     *
     * Nested rather than flat: running a scan or granting an exemption without
     * being able to see the results would be granting somebody a button and no
     * way to know what it did.
     */
    private function bootPermissions(): void
    {
        Permission::group('a11y-docs', __('Documents'), function (): void {
            Permission::register('view document checks')
                ->label(__('View document accessibility results'))
                ->description(__('See the documents dashboard, the remediation queue, and each document\'s findings.'))
                ->children([
                    Permission::make('run document scans')
                        ->label(__('Run document scans'))
                        ->description(__('Check documents again after fixing them.')),

                    Permission::make('manage document exemptions')
                        ->label(__('Manage document exemptions'))
                        ->description(__('Decide that a document will not be fixed, and say why. Exempt documents stop blocking publication.')),
                ]);
        });
    }

    /** One item in the control panel, under Content, where the assets live. */
    private function bootNavigation(): void
    {
        Nav::extend(function ($nav): void {
            // A cached route table built before this addon was installed does
            // not contain its CP routes, and Nav::extend runs on every control
            // panel request including the login page. Without this guard a
            // stale cache takes the whole control panel down with
            // "Route [statamic.cp.a11y-docs.dashboard] not defined" - the
            // addon's own absence breaking pages that have nothing to do with
            // it. Measured on a Forge deploy.
            if (! Route::has('statamic.cp.a11y-docs.dashboard')) {
                return;
            }

            // No ->active() here: Statamic 5 took an explicit pattern, Statamic 6
            // removed the method and works the active state out from the URL
            // itself. Calling it is a fatal error that takes down the whole
            // control panel, not just this addon's screens.
            // Not 'assets': that is the icon Statamic's own Assets item uses, so
            // the sidebar showed the same picture twice. 'file-content-list' is
            // a page with its lines checked off, which is what this does.
            $nav->content(__('Documents'))
                ->route('a11y-docs.dashboard')
                ->icon('file-content-list');
        });
    }

    public function register(): void
    {
        parent::register();

        // The one place the framework meets the framework-agnostic package.
        // Everything the addon does with a document goes through this.
        $this->app->singleton(DocumentInspector::class, function ($app): DocumentInspector {
            return new DocumentInspector([
                $this->pdfInspector($app['config']),
                new OoxmlInspector,
                new LegacyOfficeInspector,
            ]);
        });

        $this->app->singleton(AssetChecker::class, fn ($app): AssetChecker => new AssetChecker(
            $app->make(DocumentInspector::class),
            (int) $app['config']->get('a11y-docs.max_file_size', 104_857_600),
        ));

        $this->app->bind(Reporting\PdfRenderer::class, fn (): Reporting\PdfRenderer => new Reporting\ChromePdfRenderer(
            $this->app['config']->get('a11y-docs.report.chrome'),
            (int) $this->app['config']->get('a11y-docs.report.timeout', 120),
        ));

        $this->app->singleton(PublishGate::class, function ($app): PublishGate {
            $config = $app['config'];
            $before = $config->get('a11y-docs.gate.grandfather_before');

            return new PublishGate(
                new DocumentReferences,
                (bool) $config->get('a11y-docs.gate.enabled', true),
                Severity::tryFrom((string) $config->get('a11y-docs.gate.threshold', 'critical'))
                    ?? Severity::Critical,
                (bool) $config->get('a11y-docs.gate.grandfather', true),
                $before === null || $before === '' ? null : Carbon::parse($before),
            );
        });

        $this->app->singleton(DocumentScanner::class, fn ($app): DocumentScanner => new DocumentScanner(
            $app->make(AssetChecker::class),
            (int) $app['config']->get('a11y-docs.scan.chunk_size', 25),
            (int) $app['config']->get('a11y-docs.scan.concurrency', 3),
            $app['config']->get('a11y-docs.scan.queue'),
        ));
    }

    /**
     * PDF/UA validation alongside the heuristics when it is configured and
     * installed; the heuristics alone otherwise. Its absence is not an error —
     * a site with no Java still gets every PDF checked.
     */
    private function pdfInspector(Repository $config): Inspector
    {
        if (! $config->get('a11y-docs.verapdf.enabled', true)) {
            return new PdfInspector;
        }

        $options = new VeraPdfOptions(
            binary: (string) $config->get('a11y-docs.verapdf.binary', 'verapdf'),
            profile: (string) $config->get('a11y-docs.verapdf.profile', 'ua1'),
            timeoutSeconds: (int) $config->get('a11y-docs.verapdf.timeout', 60),
            maxBytes: (int) $config->get('a11y-docs.verapdf.max_bytes', 67_108_864),
        );

        return new AugmentedPdfInspector(
            new PdfInspector,
            new VeraPdfInspector(new ProcessVeraPdfRunner($options), $options),
        );
    }
}
