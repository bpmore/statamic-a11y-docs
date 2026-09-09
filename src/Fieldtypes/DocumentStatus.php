<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs\Fieldtypes;

use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Bpmore\StatamicA11yDocs\Models\DocumentFinding;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\User;
use Statamic\Fields\Fieldtype;

/**
 * Shows a document's accessibility status against the asset itself.
 *
 * Stores nothing. The value is looked up from `document_checks` by the asset
 * the field is attached to, so the asset browser and the asset editor both show
 * something true rather than something somebody remembered to save.
 */
class DocumentStatus extends Fieldtype
{
    protected static $handle = 'a11y_document_status';

    protected $selectable = false;

    public function preProcess($value): ?array
    {
        return $this->statusFor();
    }

    public function preProcessIndex($value): ?array
    {
        return $this->statusFor();
    }

    public function augment($value): ?array
    {
        return $this->statusFor();
    }

    /** Nothing is ever written: the database is the source of truth, not the meta file. */
    public function process($value)
    {
        return null;
    }

    /** @return array<string, mixed>|null */
    private function statusFor(): ?array
    {
        $asset = $this->field()?->parent();

        if (! $asset instanceof Asset) {
            return null;
        }

        // Only when there is a user to ask. On the command line and in a queue
        // worker there is nobody signed in, and that is not the same as somebody
        // who has been refused.
        $user = User::current();

        if ($user !== null && ! $user->can('view document checks')) {
            return null;
        }

        $check = DocumentCheck::forAsset($asset->id())->with('findings')->first();

        if ($check === null) {
            return [
                'status' => null,
                'severity' => null,
                'findings' => 0,
                'label' => __('Not checked'),
                'colour' => 'gray',
            ];
        }

        $worst = $check->worstSeverity();

        return [
            'status' => $check->status->value,
            'severity' => $worst?->value,
            'findings' => $check->findings->count(),
            'label' => self::label($check->status, $worst, $check->findings->count()),
            'colour' => self::colour($check->status, $worst),
            'checked_at' => $check->checked_at?->toIso8601String(),
            'engine' => $check->engine === null ? null : trim($check->engine.' '.$check->engine_version),
            'error' => $check->error,
            // The findings themselves, so the asset's own screen can list what
            // is wrong with it and where — spec §9's detail panel. Sorted worst
            // first, because that is the order somebody fixes them in.
            'problems' => $check->findings
                ->sortByDesc(fn (DocumentFinding $finding): int => $finding->severity->weight())
                ->values()
                ->map(fn (DocumentFinding $finding): array => [
                    'rule' => $finding->rule_id,
                    'severity' => $finding->severity->value,
                    'message' => $finding->message,
                    'where' => $finding->locatedAt()?->describe(),
                    'help_url' => $finding->help_url,
                ])->all(),
            // What could not be checked, which is not the same as what passed.
            'unchecked' => array_map(
                static fn (array $rule): array => ['rule' => $rule['rule_id'], 'reason' => $rule['reason']],
                $check->unchecked ?? [],
            ),
        ];
    }

    private static function label(Status $status, ?Severity $worst, int $findings): string
    {
        return match (true) {
            $status === Status::Pass => __('No problems found'),
            $status === Status::Error => __('Could not be read'),
            $status === Status::Skipped => __('Too large to check'),
            $status === Status::Unsupported => __('Format not checked'),
            default => trans_choice('{1}:count problem|[2,*]:count problems', $findings, ['count' => $findings])
                .($worst === null ? '' : ' · '.$worst->value),
        };
    }

    /** Red, amber, green, as spec §9 asks for. */
    private static function colour(Status $status, ?Severity $worst): string
    {
        return match (true) {
            $status === Status::Pass => 'green',
            $status === Status::Fail && $worst === Severity::Critical => 'red',
            $status === Status::Fail => 'amber',
            default => 'gray',
        };
    }
}
