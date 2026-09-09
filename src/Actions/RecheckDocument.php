<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Actions;

use Bpmore\DocumentA11yCore\Format;
use Bpmore\StatamicA11yDocs\AssetChecker;
use Statamic\Actions\Action;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\User;

/**
 * "Re-check" on an asset, in the browser and in the queue.
 *
 * Forced, because somebody clicking this has just fixed the document and the
 * hash-based cache would otherwise tell them nothing happened.
 */
class RecheckDocument extends Action
{
    public static function title()
    {
        return __('Re-check accessibility');
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Asset
            && User::current()?->can('run document scans') !== false
            && Format::detect($item->disk()->filesystem()->path($item->path())) !== null;
    }

    /**
     * Checked again on the way in, not only when deciding what to show.
     * Hiding a button is a courtesy; this is the part that matters.
     */
    public function authorize($user, $item): bool
    {
        return (bool) $user?->can('run document scans');
    }

    public function visibleToBulk($items): bool
    {
        return $items->every(fn ($item): bool => $this->visibleTo($item));
    }

    public function run($items, $values): string
    {
        $checker = app(AssetChecker::class);
        $failing = 0;

        foreach ($items as $asset) {
            $check = $checker->check($asset, force: true);
            $failing += $check->status->needsAttention() ? 1 : 0;
        }

        return $failing === 0
            ? __('Checked. Nothing to fix.')
            : trans_choice(
                '{1}Checked. :count document still needs attention.|[2,*]Checked. :count documents still need attention.',
                $failing,
                ['count' => $failing],
            );
    }
}
