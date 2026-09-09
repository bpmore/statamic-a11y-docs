<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Gate;

use Bpmore\DocumentA11yCore\Severity;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentExemption;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;

/**
 * Decides whether an entry may be published.
 *
 * Spec §8 is unambiguous about the way this goes wrong: installing on a site
 * with 1,240 pre-existing bad PDFs must not make it impossible to publish
 * anything, because that is an instant uninstall. Three things stop that, and
 * all three are on by default.
 *
 * The gate protects the future. The dashboard describes the past.
 */
final class PublishGate
{
    public function __construct(
        private readonly DocumentReferences $references,
        private readonly bool $enabled = true,
        /** Only documents at or above this are ever blocked. Critical by default. */
        private readonly Severity $threshold = Severity::Critical,
        /** Documents already in the library when this was installed are not gated. */
        private readonly bool $grandfathering = true,
        private readonly ?CarbonInterface $grandfatherBefore = null,
    ) {}

    /** @return Collection<int, DocumentCheck> the documents standing in the way, if any */
    public function blockers(Entry $entry): Collection
    {
        if (! $this->enabled) {
            return collect();
        }

        $assets = $this->references->in($entry)
            ->reject(fn (Asset $asset): bool => $this->isGrandfathered($asset));

        if ($assets->isEmpty()) {
            return collect();
        }

        $exempt = DocumentExemption::query()
            ->active()
            ->whereIn('asset_id', $assets->map->id()->all())
            ->pluck('asset_id')
            ->flip();

        return DocumentCheck::query()
            ->with('findings')
            ->whereIn('asset_id', $assets->map->id()->all())
            ->whereNotIn('asset_id', $exempt->keys())
            ->get()
            ->filter(fn (DocumentCheck $check): bool => $this->failsThreshold($check))
            ->values();
    }

    public function allows(Entry $entry): bool
    {
        return $this->blockers($entry)->isEmpty();
    }

    /**
     * A document that was already here when the addon arrived.
     *
     * Judged on the file's own timestamp, so replacing a grandfathered document
     * with a new one gates it — which is the point: the exemption is for the
     * backlog, not for the filename.
     */
    public function isGrandfathered(Asset $asset): bool
    {
        if (! $this->grandfathering) {
            return false;
        }

        $since = $this->grandfatherDate();

        // At or before, not strictly before: a document uploaded in the same
        // second the addon was installed is part of the backlog. Every tie here
        // is broken towards not blocking, which is the whole spirit of §8.
        return $since !== null && $asset->lastModified()->lessThanOrEqualTo($since);
    }

    /**
     * When this library started being gated.
     *
     * Configured explicitly, or taken as the moment the addon first checked
     * anything — meaning everything that existed when we first looked is the
     * backlog, and everything since is new. Null when nothing has ever been
     * checked, in which case nothing is grandfathered and nothing is blocked
     * either, because there are no results to block on.
     */
    public function grandfatherDate(): ?CarbonInterface
    {
        if ($this->grandfatherBefore !== null) {
            return $this->grandfatherBefore;
        }

        $first = DocumentCheck::query()->min('created_at');

        return $first === null ? null : Carbon::parse($first);
    }

    private function failsThreshold(DocumentCheck $check): bool
    {
        return $check->findings->contains(
            fn ($finding): bool => $finding->severity->isAtLeast($this->threshold)
        );
    }

    /**
     * What to tell the person who just tried to publish.
     *
     * Plain words, and it names the way out. "Blocked" with no route forward is
     * how a gate becomes a thing people disable.
     *
     * @param  Collection<int, DocumentCheck>  $blockers
     * @return list<string>
     */
    public function explain(Collection $blockers): array
    {
        $lines = [];

        foreach ($blockers as $check) {
            $findings = $check->findings
                ->filter(fn ($finding): bool => $finding->severity->isAtLeast($this->threshold))
                ->take(2);

            // Sentences, not a run-on. Joining on a bare space produced
            // "...navigate the document at all PDF/UA 7.1: Content shall be..."
            // — two findings welded into one unreadable clause, in the one
            // message whose whole job is to be read.
            $lines[] = sprintf(
                '%s — %s.',
                $check->path,
                $findings
                    ->map(fn ($finding): string => rtrim(trim($finding->message), '.'))
                    ->filter()
                    ->implode('. ')
            );
        }

        $lines[] = sprintf(
            'Fix %s, or exempt %s with a reason, then publish.',
            $blockers->count() === 1 ? 'it' : 'them',
            $blockers->count() === 1 ? 'it' : 'them',
        );

        return $lines;
    }
}
