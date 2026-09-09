<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\VeraPdf;

use JsonException;
use RuntimeException;

/**
 * A parsed veraPDF JSON report.
 *
 * Built against real output from veraPDF 1.30, and tolerant of the two shapes
 * seen in the wild for `validationResult` — an object in older releases, a
 * one-element array in current ones.
 *
 * The parser fails loudly on a shape it does not recognise. Returning "no
 * findings" from a report it could not read would turn a non-compliant
 * document into a passing one, which is the single worst thing this code could
 * do.
 */
final readonly class VeraPdfReport
{
    /** @param  list<VeraPdfRule>  $rules */
    private function __construct(
        public bool $compliant,
        public array $rules,
        public ?string $profileName = null,
        public ?string $statement = null,
        /** Set when veraPDF could not read the file at all. */
        public ?string $parseFailure = null,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('veraPDF output was not valid JSON: '.$exception->getMessage());
        }

        $report = $decoded['report'] ?? null;
        if (! is_array($report)) {
            throw new RuntimeException('veraPDF output has no "report" key.');
        }

        $job = $report['jobs'][0] ?? null;
        if (! is_array($job)) {
            throw new RuntimeException('veraPDF reported no jobs for the file.');
        }

        // A file veraPDF cannot read comes back with a taskException and no
        // validationResult at all — that is how a truncated or empty PDF looks.
        if (isset($job['taskException'])) {
            $exception = $job['taskException'];

            return new self(
                compliant: false,
                rules: [],
                parseFailure: self::firstString($exception, ['exceptionMessage', 'exception'])
                    ?? 'veraPDF could not read the file.',
            );
        }

        $result = $job['validationResult'] ?? null;
        // An array in veraPDF 1.30, an object in earlier releases.
        if (is_array($result) && array_is_list($result)) {
            $result = $result[0] ?? null;
        }
        if (! is_array($result)) {
            throw new RuntimeException('veraPDF returned a job with no validation result.');
        }

        $summaries = $result['details']['ruleSummaries'] ?? [];
        if (! is_array($summaries)) {
            throw new RuntimeException('veraPDF returned a validation result with no rule summaries.');
        }

        $rules = [];
        foreach ($summaries as $summary) {
            if (! is_array($summary) || ($summary['status'] ?? null) !== 'failed') {
                continue;
            }
            $rules[] = new VeraPdfRule(
                clause: (string) ($summary['clause'] ?? '?'),
                testNumber: (int) ($summary['testNumber'] ?? 0),
                description: trim((string) ($summary['description'] ?? '')),
                failedChecks: max(1, (int) ($summary['failedChecks'] ?? 1)),
                tags: array_values(array_filter(
                    (array) ($summary['tags'] ?? []),
                    static fn (mixed $tag): bool => is_string($tag),
                )),
                contexts: array_values(array_filter(array_map(
                    static fn (mixed $check): ?string => is_array($check) && isset($check['context'])
                        ? (string) $check['context']
                        : null,
                    (array) ($summary['checks'] ?? []),
                ))),
            );
        }

        return new self(
            compliant: (bool) ($result['compliant'] ?? false),
            rules: $rules,
            profileName: self::firstString($result, ['profileName']),
            statement: self::firstString($result, ['statement']),
        );
    }

    /** The release veraPDF says it is, taken from the report rather than assumed. */
    public static function versionFromJson(string $json): ?string
    {
        $decoded = json_decode($json, true);
        foreach ($decoded['report']['buildInformation']['releaseDetails'] ?? [] as $release) {
            if (($release['id'] ?? null) === 'apps' && isset($release['version'])) {
                return (string) $release['version'];
            }
        }

        return null;
    }

    /** @param  list<string>  $keys */
    private static function firstString(mixed $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = is_array($data) ? ($data[$key] ?? null) : null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
