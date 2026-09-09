<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Actions;

use Bpmore\StatamicA11yDocs\Models\DocumentExemption;
use Statamic\Actions\Action;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\User;

/**
 * "Exempt" on an asset, one or many.
 *
 * The reason is required and is not a formality: spec §8 asks for exemptions
 * with a reason, an optional expiry and an audit trail, and an exemption
 * nobody has to justify is just a way of turning the addon off one file at a
 * time.
 */
class ExemptDocument extends Action
{
    public static function title()
    {
        return __('Exempt from accessibility checks');
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Asset
            && User::current()?->can('manage document exemptions') !== false;
    }

    public function visibleToBulk($items): bool
    {
        return $items->every(fn ($item): bool => $this->visibleTo($item));
    }

    /** Deciding a document will not be fixed is the one thing here worth guarding twice. */
    public function authorize($user, $item): bool
    {
        return (bool) $user?->can('manage document exemptions');
    }

    public function buttonText()
    {
        return trans_choice('Exempt Document|Exempt :count Documents', 'count');
    }

    protected function fieldItems()
    {
        return [
            'reason' => [
                'display' => __('Why is this exempt?'),
                'instructions' => __('Somebody will read this in a year and need it to make sense.'),
                'type' => 'textarea',
                'validate' => 'required|min:10',
            ],
            'expires_at' => [
                'display' => __('Expires'),
                'instructions' => __('Optional. After this date the document is checked again like any other.'),
                'type' => 'date',
                'validate' => 'nullable',
            ],
        ];
    }

    public function run($items, $values): string
    {
        $user = User::current();

        foreach ($items as $asset) {
            DocumentExemption::create([
                'asset_id' => $asset->id(),
                'reason' => trim((string) $values['reason']),
                'granted_by' => $user?->id(),
                'granted_at' => now(),
                'expires_at' => $values['expires_at'] ?? null,
            ]);
        }

        return trans_choice(
            '{1}:count document exempted.|[2,*]:count documents exempted.',
            $items->count(),
            ['count' => $items->count()],
        );
    }
}
