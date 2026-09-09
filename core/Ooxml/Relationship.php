<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml;

/** One entry from a `.rels` part, with its target already resolved. */
final readonly class Relationship
{
    public function __construct(
        public string $id,
        public string $type,
        /** A part name within the package, or the raw URI when external. */
        public string $target,
        public bool $external = false,
    ) {}

    /**
     * The last segment of the type URI: `image`, `hyperlink`, `slide`,
     * `officeDocument`. Rules match on this rather than on the whole URI,
     * because the same relationship is published under two different bases
     * depending on whether it is a package or a document relationship.
     */
    public function shortType(): string
    {
        $position = strrpos($this->type, '/');

        return $position === false ? $this->type : substr($this->type, $position + 1);
    }

    public function is(string $shortType): bool
    {
        return $this->shortType() === $shortType;
    }
}
