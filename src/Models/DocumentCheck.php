<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Models;

use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The latest result of checking one asset.
 *
 * One row per asset, never a history: the only question hash-based caching ever
 * asks is whether this file has changed since we last looked.
 *
 * @property string $asset_id
 * @property string|null $file_hash
 * @property Status $status
 */
class DocumentCheck extends Model
{
    protected $table = 'document_checks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'format' => Format::class,
            'status' => Status::class,
            'file_size' => 'integer',
            'page_count' => 'integer',
            'duration_ms' => 'integer',
            'checked_at' => 'datetime',
            'unchecked' => 'array',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(DocumentFinding::class, 'check_id');
    }

    public function scopeForAsset(Builder $query, string $assetId): Builder
    {
        return $query->where('asset_id', $assetId);
    }

    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->where('status', '!=', Status::Pass->value);
    }

    /**
     * Is this result still good for the file as it stands now?
     *
     * Two things invalidate it. The obvious one is the file changing. The other
     * is the engine changing: a site that installs veraPDF has 1,240 documents
     * whose results were produced by something less authoritative, and leaving
     * them alone would mean a report claiming PDF/UA validation it never had.
     */
    public function isCurrentFor(?string $fileHash, ?string $engine, ?string $engineVersion): bool
    {
        if ($this->file_hash === null || $fileHash === null) {
            return false;
        }

        return $this->file_hash === $fileHash
            && $this->engine === $engine
            && $this->engine_version === $engineVersion;
    }

    public function worstSeverity(): ?Severity
    {
        return Severity::worst(
            $this->findings->map(fn (DocumentFinding $finding): Severity => $finding->severity)
        );
    }
}
