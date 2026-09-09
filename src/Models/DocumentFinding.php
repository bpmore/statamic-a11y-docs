<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Models;

use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One problem found in one document.
 *
 * No timestamps: findings are regenerated wholesale every time their check
 * runs, so a created_at here would record the last re-scan rather than when the
 * problem appeared. The parent's `checked_at` is the date that means something.
 *
 * @property string $rule_id
 * @property Severity $severity
 */
class DocumentFinding extends Model
{
    protected $table = 'document_findings';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'location' => 'array',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(DocumentCheck::class, 'check_id');
    }

    /**
     * Where in the document this is.
     *
     * Not called location(): `location` is a column, and a method of the same
     * name reads like an Eloquent relationship to anybody skimming.
     */
    public function locatedAt(): ?Location
    {
        return Location::fromArray($this->location ?? []);
    }
}
