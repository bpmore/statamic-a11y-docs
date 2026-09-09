<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Gate;

use Bpmore\DocumentA11yCore\Format;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset as Assets;
use Statamic\Facades\AssetContainer;

/**
 * The documents an entry links to or embeds.
 *
 * Deliberately not blueprint-aware. Walking a blueprint to find every assets
 * field means following replicators into sets into bard into grids, and getting
 * that wrong means silently missing a document — which is worse than the
 * alternative. Instead every value in the entry is walked, and anything that
 * looks like it names a document is resolved three ways: as an asset id, as a
 * URL, and as a path inside each container.
 *
 * The extension filter is what keeps that cheap. A site has a handful of
 * containers and an entry has a handful of values that end in `.pdf`.
 */
final class DocumentReferences
{
    /** @return Collection<int, Asset> keyed by asset id */
    public function in(Entry $entry): Collection
    {
        $candidates = collect();

        $this->walk($entry->data()->all(), $candidates);
        $this->walk([$entry->get('content')], $candidates);

        return $candidates
            ->unique()
            ->map(fn (string $candidate): ?Asset => $this->resolve($candidate))
            ->filter()
            ->keyBy(static fn (Asset $asset): string => $asset->id())
            ->values();
    }

    /**
     * Collect anything in the entry's values that names a document.
     *
     * @param  Collection<int, string>  $candidates
     */
    private function walk(mixed $value, Collection $candidates): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->walk($item, $candidates);
            }

            return;
        }

        if ($value instanceof Arrayable) {
            $this->walk($value->toArray(), $candidates);

            return;
        }

        if (! is_string($value) || $value === '') {
            return;
        }

        // A whole value that is a document reference: an assets field stores
        // one path per item, and a link field stores a URL.
        if ($this->looksLikeDocument($value)) {
            $candidates->push($value);
        }

        // And references buried in prose: bard, markdown, redactor. Anything
        // ending in a format we check, inside quotes, brackets or on its own.
        preg_match_all(
            '#[^\s"\'()\[\]<>]+\.(?:'.implode('|', $this->extensions()).')#i',
            $value,
            $matches,
        );

        foreach ($matches[0] as $match) {
            $candidates->push($match);
        }
    }

    private function looksLikeDocument(string $value): bool
    {
        return in_array(
            strtolower(pathinfo(parse_url($value, PHP_URL_PATH) ?? $value, PATHINFO_EXTENSION)),
            $this->extensions(),
            true,
        );
    }

    /** @return list<string> */
    private function extensions(): array
    {
        return array_map(static fn (Format $format): string => $format->value, Format::cases());
    }

    private function resolve(string $candidate): ?Asset
    {
        // An asset id, as an assets field stores it when it is configured with
        // more than one container.
        if (str_contains($candidate, '::') && ($asset = Assets::find($candidate))) {
            return $asset;
        }

        // A URL, as a link field or a bard anchor stores it.
        if (str_contains($candidate, '/') || str_starts_with($candidate, 'http')) {
            if ($asset = Assets::findByUrl($candidate)) {
                return $asset;
            }
        }

        // A path within a container, which is what a single-container assets
        // field stores — and what is left of a URL once the container's own
        // prefix is stripped, which is how a link in a paragraph arrives.
        //
        // Tried against each container, and against each suffix of the path,
        // because "/assets/documents/reports/handbook.pdf" has to end up as
        // "reports/handbook.pdf" without this needing to know how the container
        // publishes its URLs. Bounded work: a handful of containers times the
        // depth of one path.
        $path = ltrim((string) (parse_url($candidate, PHP_URL_PATH) ?: $candidate), '/');
        $segments = explode('/', $path);

        foreach (AssetContainer::all() as $container) {
            for ($skip = 0; $skip < count($segments); $skip++) {
                $suffix = implode('/', array_slice($segments, $skip));

                if ($asset = $container->asset($suffix)) {
                    return $asset;
                }
            }
        }

        return null;
    }
}
