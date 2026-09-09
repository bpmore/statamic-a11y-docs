<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs;

use Bpmore\DocumentA11yCore\DocumentInspector;
use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\UncheckedRule;
use Bpmore\DocumentA11yCore\Version;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use Illuminate\Support\Facades\DB;
use Statamic\Contracts\Assets\Asset;
use Throwable;

/**
 * Checks one asset and stores the result, doing nothing at all when the file
 * has not changed since last time.
 *
 * Spec §7: hash-based caching is not optional. A library of 1,240 PDFs must not
 * be reprocessed nightly, and at roughly a second each that is not a matter of
 * tidiness.
 */
final class AssetChecker
{
    public function __construct(
        private readonly DocumentInspector $inspector,
        /** Above this, a document is recorded as skipped without being read. */
        private readonly int $maxBytes = 104_857_600,
    ) {}

    /**
     * @param  bool  $force  re-check even when nothing has changed
     */
    public function check(Asset $asset, bool $force = false): DocumentCheck
    {
        try {
            return $this->attempt($asset, $force);
        } catch (Throwable $exception) {
            // Per-file isolation, spec §7. One malformed file, one unreadable
            // disk, one asset deleted mid-scan — none of them may take down a
            // run over 1,240 documents. The failure is recorded against the
            // document it belongs to and the scan carries on.
            return $this->store(
                $asset,
                DocumentCheck::forAsset($asset->id())->first(),
                InspectionResult::error(
                    $this->inspector->inspectorFor(Format::Pdf)?->engine() ?? Engine::heuristics(Version::CURRENT),
                    'This document could not be checked: '.rtrim($exception->getMessage(), '.').'.',
                ),
                null,
                (int) $asset->size(),
                null,
            );
        }
    }

    private function attempt(Asset $asset, bool $force): DocumentCheck
    {
        $existing = DocumentCheck::forAsset($asset->id())->first();
        $size = (int) $asset->size();

        // Checked before the file is fetched, not after: on a remote disk the
        // difference is a 400 MB download nobody asked for.
        if ($size > $this->maxBytes) {
            return $this->store($asset, $existing, InspectionResult::skipped(sprintf(
                'This document is %s, over the %s limit for checking.',
                self::megabytes($size),
                self::megabytes($this->maxBytes),
            )), null, $size, null);
        }

        $file = AssetFile::for($asset);

        try {
            $hash = hash_file('sha256', $file->path()) ?: null;
            $format = Format::detect($file->path());
            $engine = $format === null ? null : $this->inspector->inspectorFor($format)?->engine();

            if (! $force && $existing?->isCurrentFor($hash, $engine?->name, $engine?->version)) {
                // The whole point. Nothing is read, parsed or written.
                return $existing;
            }

            $result = $this->inspector->inspect($file->path());
        } finally {
            $file->release();
        }

        return $this->store($asset, $existing, $result, $hash, $size, $format);
    }

    /** Would checking this asset do any work, or is the stored result still good? */
    public function isUpToDate(Asset $asset): bool
    {
        $existing = DocumentCheck::forAsset($asset->id())->first();

        if ($existing === null || (int) $asset->size() > $this->maxBytes) {
            return false;
        }

        $file = AssetFile::for($asset);

        try {
            $hash = hash_file('sha256', $file->path()) ?: null;
            $format = Format::detect($file->path());
            $engine = $format === null ? null : $this->inspector->inspectorFor($format)?->engine();

            return $existing->isCurrentFor($hash, $engine?->name, $engine?->version);
        } finally {
            $file->release();
        }
    }

    /** One row per asset, findings replaced wholesale, both or neither. */
    private function store(
        Asset $asset,
        ?DocumentCheck $existing,
        InspectionResult $result,
        ?string $hash,
        int $size,
        ?Format $format,
    ): DocumentCheck {
        return DB::transaction(function () use ($asset, $existing, $result, $hash, $size, $format): DocumentCheck {
            $check = $existing ?? new DocumentCheck(['asset_id' => $asset->id()]);

            $check->fill([
                'container' => $asset->container()->handle(),
                'path' => $asset->path(),
                'format' => $format ?? Format::tryFrom(strtolower($asset->extension() ?? '')),
                'file_hash' => $hash,
                'file_size' => $size,
                'page_count' => $result->pageCount,
                'engine' => $result->engine?->name,
                'engine_version' => $result->engine?->version,
                'status' => $result->status,
                'checked_at' => now(),
                'duration_ms' => $result->durationMs,
                'error' => $result->error,
                'unchecked' => array_map(
                    static fn (UncheckedRule $rule): array => $rule->jsonSerialize(),
                    $result->unchecked,
                ) ?: null,
            ])->save();

            // Replaced rather than merged: the findings describe the file as it
            // is now, and a stale one left behind would be a problem somebody
            // fixed still sitting in the queue.
            $check->findings()->delete();

            if ($result->findings !== []) {
                $check->findings()->insert(array_map(
                    static fn (Finding $finding): array => [
                        'check_id' => $check->id,
                        'rule_id' => $finding->ruleId,
                        'severity' => $finding->severity->value,
                        'message' => $finding->message,
                        'help_url' => $finding->helpUrl,
                        'location' => $finding->location === null
                            ? null
                            : json_encode($finding->location->jsonSerialize()),
                    ],
                    $result->findings,
                ));
            }

            return $check->refresh();
        });
    }

    private static function megabytes(int $bytes): string
    {
        return round($bytes / 1_048_576, 1).' MB';
    }
}
