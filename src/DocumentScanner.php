<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs;

use Bpmore\DocumentA11yCore\Format;
use Bpmore\StatamicA11yDocs\Jobs\CheckDocuments;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\LazyCollection;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\AssetContainer;

/**
 * Finds every document in the asset library and gets it checked.
 *
 * Enumerate, chunk, batch, finalise — the pattern spec §7 asks for. The
 * enumeration is deliberately cheap: it reads file *paths* off each container
 * and filters by extension, without hydrating an asset or touching a byte,
 * because on a library of thousands the listing is the part that has to stay
 * fast.
 */
final class DocumentScanner
{
    public function __construct(
        private readonly AssetChecker $checker,
        private readonly int $chunkSize = 25,
        private readonly int $concurrency = 3,
        private readonly ?string $queue = null,
    ) {}

    /**
     * The asset ids worth checking.
     *
     * Filtered by extension, which is a different question from what the file
     * turns out to be: a PDF saved as .docx is enumerated as a Word document
     * and then correctly checked as a PDF. Something saved as .txt is missed
     * here, which is the price of not opening every file in the library to find
     * out what it is.
     *
     * @param  list<string>|null  $containers  null for every container
     * @return LazyCollection<int, string>
     */
    public function documents(?array $containers = null): LazyCollection
    {
        $extensions = array_map(
            static fn (Format $format): string => $format->value,
            Format::cases(),
        );

        return LazyCollection::make(function () use ($containers, $extensions): iterable {
            foreach (AssetContainer::all() as $container) {
                if ($containers !== null && ! in_array($container->handle(), $containers, true)) {
                    continue;
                }

                foreach ($container->files() as $path) {
                    if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
                        yield $container->handle().'::'.$path;
                    }
                }
            }
        });
    }

    /**
     * Queue the whole library.
     *
     * The batch allows failures so that a chunk which somehow dies takes only
     * itself down; per-file isolation means it should not happen, and a run of
     * 1,240 documents is not the place to find out we were wrong about that.
     *
     * @param  list<string>|null  $containers
     */
    public function queue(?array $containers = null, bool $force = false): QueuedScan
    {
        $documents = 0;

        $jobs = $this->documents($containers)
            ->chunk($this->chunkSize)
            ->map(function (LazyCollection $chunk) use ($force, &$documents): CheckDocuments {
                $ids = array_values($chunk->all());
                $documents += count($ids);

                return new CheckDocuments($ids, $force, $this->concurrency);
            })
            ->all();

        $batch = Bus::batch($jobs)
            ->name('A11y Docs scan')
            ->allowFailures();

        if ($this->queue !== null) {
            $batch->onQueue($this->queue);
        }

        return new QueuedScan($batch->dispatch(), $documents, count($jobs));
    }

    /**
     * Check the whole library here and now, without a queue.
     *
     * What `docs:check --sync` and a CI run use. `$onChecked` is called after
     * each document so a command can draw a progress bar; a scan of a thousand
     * files with no output is a scan people kill.
     *
     * @param  list<string>|null  $containers
     * @param  (callable(DocumentCheck, string): void)|null  $onChecked
     * @return int how many documents were looked at
     */
    public function run(?array $containers = null, bool $force = false, ?callable $onChecked = null): int
    {
        $checked = 0;

        foreach ($this->documents($containers) as $id) {
            $asset = \Statamic\Facades\Asset::find($id);

            if (! $asset instanceof Asset) {
                continue;
            }

            $check = $this->checker->check($asset, $force);
            $checked++;

            if ($onChecked !== null) {
                $onChecked($check, $id);
            }
        }

        return $checked;
    }
}
