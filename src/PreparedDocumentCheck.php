<?php

declare(strict_types=1);

namespace Bpmore\StatamicA11yDocs;

use Bpmore\DocumentA11yCore\Engine;
use Bpmore\DocumentA11yCore\Finding;
use Bpmore\DocumentA11yCore\Format;
use Bpmore\DocumentA11yCore\InspectionResult;
use Bpmore\DocumentA11yCore\Location;
use Bpmore\DocumentA11yCore\Severity;
use Bpmore\DocumentA11yCore\Status;
use Bpmore\DocumentA11yCore\UncheckedRule;
use Bpmore\StatamicA11yDocs\Models\DocumentCheck;
use JsonSerializable;
use RuntimeException;

/** Lossless transfer of one read-only preparation, with no publication side effects. */
final readonly class PreparedDocumentCheck implements JsonSerializable
{
    public function __construct(
        public string $assetId,
        public ?InspectionResult $result,
        public ?string $hash,
        public int $size,
        public ?Format $format,
        public int|string|null $cachedCheckId = null,
        public ?string $cachedEngine = null,
        public ?string $cachedVersion = null,
        // Native in-process cache hits retain their exact original return value.
        // This model is deliberately absent from the transfer receipt.
        public ?DocumentCheck $cachedModel = null,
    ) {}

    public function jsonSerialize(): array
    {
        return ['asset_id' => $this->assetId, 'result' => $this->result?->jsonSerialize(),
            'hash' => $this->hash, 'size' => $this->size, 'format' => $this->format?->value,
            'cached_check_id' => $this->cachedCheckId, 'cached_engine' => $this->cachedEngine,
            'cached_version' => $this->cachedVersion];
    }

    public static function fromArray(array $data): self
    {
        $raw = $data['result'];
        $result = null;
        if ($raw !== null) {
            $status = Status::from($raw['status']);
            $engine = $raw['engine'] === null ? null : new Engine($raw['engine'], $raw['engine_version']);
            $result = match ($status) {
                Status::Pass, Status::Fail => InspectionResult::checked($engine,
                    array_map(fn (array $f) => new Finding($f['rule_id'], Severity::from($f['severity']),
                        $f['message'], $f['location'] === null ? null : Location::fromArray($f['location']), $f['help_url']), $raw['findings']),
                    array_map(fn (array $u) => new UncheckedRule($u['rule_id'], $u['reason']), $raw['unchecked']), $raw['page_count']),
                Status::Error => InspectionResult::error($engine, $raw['error']),
                Status::Skipped => InspectionResult::skipped($raw['error']),
                Status::Unsupported => InspectionResult::unsupported($raw['error']),
            };
            if ($result->status !== $status) {
                throw new RuntimeException('Prepared document status disagrees with its findings.');
            }
            $result = $result->withPageCount($raw['page_count']);
            if ($raw['duration_ms'] !== null) {
                $result = $result->withDuration($raw['duration_ms']);
            }
        }

        return new self($data['asset_id'], $result, $data['hash'], $data['size'],
            $data['format'] === null ? null : Format::from($data['format']), $data['cached_check_id'],
            $data['cached_engine'], $data['cached_version']);
    }
}
