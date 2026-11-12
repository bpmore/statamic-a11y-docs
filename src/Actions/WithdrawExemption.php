<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Actions;

use Bpmore\StatamicA11yDocs\Models\DocumentExemption;
use Statamic\Actions\Action;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\User;

/**
 * "Withdraw exemption" on an asset, one or many.
 *
 * The other half of ExemptDocument. Without it an exemption is permanent from
 * the control panel, which makes the decision harder to reverse than it was to
 * make.
 *
 * The row is not deleted. It is stamped with who ended it and when, so the
 * record still shows that the document was exempt for a period and by whose
 * decision it stopped being.
 */
class WithdrawExemption extends Action
{
    public static function title()
    {
        return __('Withdraw accessibility exemption');
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Asset
            && User::current()?->can('manage document exemptions') !== false
            && DocumentExemption::query()->active()->forAsset($item->id())->exists();
    }

    public function visibleToBulk($items): bool
    {
        return $items->every(fn ($item): bool => $this->visibleTo($item));
    }

    public function authorize($user, $item): bool
    {
        return (bool) $user?->can('manage document exemptions');
    }

    public function buttonText()
    {
        return trans_choice('Withdraw Exemption|Withdraw :count Exemptions', 'count');
    }

    public function confirmationText()
    {
        return trans_choice(
            'Are you sure you want to withdraw this exemption? The document will be gated again.|Are you sure you want to withdraw these :count exemptions? The documents will be gated again.',
            'count',
        );
    }

    public function run($items, $values): string
    {
        $userId = User::current()?->id();
        $withdrawn = 0;

        foreach ($items as $asset) {
            // Every active row, not just the newest: an asset exempted twice
            // without the first being withdrawn would otherwise stay exempt
            // after somebody was told it was not.
            $exemptions = DocumentExemption::query()
                ->active()
                ->forAsset($asset->id())
                ->get();

            foreach ($exemptions as $exemption) {
                $withdrawn += $exemption->withdraw($userId) ? 1 : 0;
            }
        }

        return trans_choice(
            '{0}Nothing was exempt.|{1}Exemption withdrawn. The document is gated again.|[2,*]:count exemptions withdrawn. Those documents are gated again.',
            $withdrawn,
            ['count' => $withdrawn],
        );
    }
}
