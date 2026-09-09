<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A document somebody has decided not to fix, and why.
 *
 * Append-only. Withdrawing an exemption sets `revoked_at` rather than deleting
 * the row, because "exemptions with an audit trail" is only true if the trail
 * survives the exemption.
 */
class DocumentExemption extends Model
{
    protected $table = 'document_exemptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Exemptions that are still in force: not withdrawn, not expired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function scopeForAsset(Builder $query, string $assetId): Builder
    {
        return $query->where('asset_id', $assetId);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
