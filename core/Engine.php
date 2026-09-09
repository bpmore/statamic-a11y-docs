<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore;

use InvalidArgumentException;
use JsonSerializable;

/**
 * What did the checking, and which version of it.
 *
 * Recorded on every result and shown on every export, because a report that
 * does not say whether it used authoritative PDF/UA validation or a set of
 * heuristics is a report that overstates itself (spec §5).
 */
final readonly class Engine implements JsonSerializable
{
    public const HEURISTICS = 'heuristics';

    public const VERAPDF = 'verapdf';

    /**
     * The widths of the `engine` and `engine_version` columns.
     *
     * Enforced here rather than left to the database, because under MySQL's
     * strict mode an overlong engine name does not truncate — it rejects the
     * whole row, and losing an entire check because two engine names were
     * concatenated would be an absurd way to fail.
     */
    public const MAX_NAME_LENGTH = 32;

    public const MAX_VERSION_LENGTH = 64;

    /** Separates the parts of an engine made of more than one. */
    private const SEPARATOR = '+';

    public function __construct(
        public string $name,
        public string $version,
    ) {
        if (trim($name) === '' || trim($version) === '') {
            throw new InvalidArgumentException('An engine needs both a name and a version.');
        }
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(
                "Engine name '$name' is longer than ".self::MAX_NAME_LENGTH.' characters.'
            );
        }
        if (strlen($version) > self::MAX_VERSION_LENGTH) {
            throw new InvalidArgumentException(
                "Engine version '$version' is longer than ".self::MAX_VERSION_LENGTH.' characters.'
            );
        }
    }

    /** The PHP rule sets in this package. */
    public static function heuristics(string $version): self
    {
        return new self(self::HEURISTICS, $version);
    }

    /** The optional external PDF/UA validator. */
    public static function veraPdf(string $version): self
    {
        return new self(self::VERAPDF, $version);
    }

    /**
     * Both, on a document where the external validator was available.
     *
     * Neither name alone would be true: the findings come from the heuristics
     * and from PDF/UA validation, and a report that claimed only one of them
     * would misdescribe itself in one direction or the other.
     */
    public static function heuristicsWithVeraPdf(string $coreVersion, string $veraVersion): self
    {
        return new self(
            self::HEURISTICS.self::SEPARATOR.self::VERAPDF,
            $coreVersion.self::SEPARATOR.$veraVersion,
        );
    }

    /**
     * The engines this one is made of, each with its own version.
     *
     * A control panel showing "heuristics+verapdf 0.1.0+1.30.0" has made the
     * reader do the parsing. Spec §5 wants the engine shown on every export, so
     * there has to be a way to show it as two things rather than one string
     * with a plus in the middle.
     *
     * @return list<self>
     */
    public function components(): array
    {
        $names = explode(self::SEPARATOR, $this->name);
        $versions = explode(self::SEPARATOR, $this->version);

        if (count($names) < 2 || count($names) !== count($versions)) {
            return [$this];
        }

        return array_map(
            static fn (string $name, string $version): self => new self($name, $version),
            $names,
            $versions,
        );
    }

    /** Did real PDF/UA validation contribute to this result, or only heuristics? */
    public function isAuthoritative(): bool
    {
        return in_array(self::VERAPDF, explode(self::SEPARATOR, $this->name), true);
    }

    public function describe(): string
    {
        return "{$this->name} {$this->version}";
    }

    public function jsonSerialize(): array
    {
        return [
            'engine' => $this->name,
            'engine_version' => $this->version,
        ];
    }
}
