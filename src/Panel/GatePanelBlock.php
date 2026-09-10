<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Panel;

use Bpmore\StatamicA11yDocs\Gate\DocumentReferences;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\RuleLabel;
use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry;

/**
 * What this addon has to say about an entry, inside A11y Gate's panel.
 *
 * The gate reads the rendered HTML. It cannot open a linked PDF, so an entry
 * that links to an untagged document reads as passing there while failing here.
 * Two addons giving an author opposite answers on the same screen is worse than
 * either being absent, and this closes it: the gate's verdict stays about the
 * page, and the documents it links to are reported underneath.
 *
 * Registered only when the gate is installed. Nothing here runs otherwise.
 */
final class GatePanelBlock
{
    public function __construct(private readonly DocumentReferences $references) {}

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(Entry $entry): ?array
    {
        $assets = $this->references->in($entry);

        if ($assets->isEmpty()) {
            return null;
        }

        $checks = DocumentCheck::query()
            ->whereIn('asset_id', $assets->map->id()->all())
            ->with('findings')
            ->get();

        if ($checks->isEmpty()) {
            return [
                'heading' => 'Documents on this page',
                'lines' => [
                    $this->count($assets->count()).' linked from this entry, none of them checked yet.',
                    'Run `php please docs:check` to read them.',
                ],
                'tone' => 'warning',
            ];
        }

        $withProblems = $checks->filter(fn (DocumentCheck $c): bool => $c->findings->isNotEmpty());

        if ($withProblems->isEmpty()) {
            return [
                'heading' => 'Documents on this page',
                'lines' => [$this->count($checks->count()).', and nothing was found wrong with any of them.'],
                'tone' => 'default',
            ];
        }

        $lines = [$this->count($checks->count()).', '.$withProblems->count().' with problems:'];

        foreach ($withProblems->take(5) as $check) {
            $worst = $check->findings->first();

            $lines[] = $check->path.' - '.RuleLabel::for((string) $worst->rule_id)
                .($check->findings->count() > 1 ? ' and '.($check->findings->count() - 1).' more' : '');
        }

        if ($withProblems->count() > 5) {
            $lines[] = 'and '.($withProblems->count() - 5).' more.';
        }

        return [
            'heading' => 'Documents on this page',
            'lines' => $lines,
            'tone' => $this->tone($withProblems),
            'link' => [
                'url' => cp_route('a11y-docs.queue'),
                'text' => 'Open the remediation queue',
            ],
        ];
    }

    private function count(int $n): string
    {
        return $n === 1 ? '1 document' : $n.' documents';
    }

    /**
     * Error only when something here would actually stop a publish, so the
     * panel's colour means the same thing the gate's does.
     *
     * @param  Collection<int, DocumentCheck>  $checks
     */
    private function tone(Collection $checks): string
    {
        $critical = $checks->contains(
            fn (DocumentCheck $c): bool => $c->findings->contains(fn ($f): bool => $f->severity->value === 'critical')
        );

        return $critical ? 'error' : 'warning';
    }
}
