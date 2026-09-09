<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml;

use DOMDocument;
use ZipArchive;

/**
 * A DOCX, PPTX or XLSX, read as what it actually is: a ZIP of XML parts wired
 * together by relationship files.
 *
 * Built once and shared by all three rule sets, which is the decision spec §3
 * says to make from the first commit. Three separate parsers would be three
 * times the work; the formats differ in which parts they contain and what those
 * parts mean, not in how the container works.
 */
final class OoxmlPackage
{
    private const CONTENT_TYPES = '[Content_Types].xml';

    /** @var array<string, XmlPart> parsed parts, kept because rules re-query them */
    private array $parsed = [];

    /** @var array<string, list<Relationship>>|null */
    private ?array $relationships = null;

    /** @var array{defaults: array<string, string>, overrides: array<string, string>}|null */
    private ?array $contentTypes = null;

    /** @var list<string> */
    private readonly array $names;

    private function __construct(
        private ?ZipArchive $zip,
        private readonly string $path,
        /**
         * A part that expands to more than this is refused.
         *
         * An OOXML package is a ZIP, and a ZIP can be made to expand to
         * gigabytes from a few kilobytes on disk. A queue worker that reads one
         * of those into a string does not come back.
         */
        private readonly int $maxPartBytes = 67_108_864,
    ) {
        $names = [];
        for ($index = 0, $total = $zip?->numFiles ?? 0; $index < $total; $index++) {
            $name = $zip?->getNameIndex($index);
            if (is_string($name) && ! str_ends_with($name, '/')) {
                $names[] = $name;
            }
        }
        $this->names = $names;
    }

    public static function open(string $path, int $maxPartBytes = 67_108_864): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new OoxmlException('The file could not be opened.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new OoxmlException("The file is not a readable ZIP container (code $opened).");
        }

        $package = new self($zip, $path, $maxPartBytes);

        if (! $package->has(self::CONTENT_TYPES)) {
            $package->close();

            throw new OoxmlException('The package has no [Content_Types].xml, so it is not an OOXML document.');
        }

        return $package;
    }

    public function close(): void
    {
        $this->zip?->close();
        $this->zip = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return list<string> */
    public function parts(): array
    {
        return $this->names;
    }

    public function has(string $part): bool
    {
        return in_array($part, $this->names, true);
    }

    public function contents(string $part): string
    {
        if ($this->zip === null) {
            throw new OoxmlException('The package has been closed.');
        }
        if (! $this->has($part)) {
            throw new OoxmlException("The package has no part named '$part'.");
        }

        // Checked before reading, not after: the point is not to allocate it.
        $stat = $this->zip->statName($part);
        $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
        if ($size > $this->maxPartBytes) {
            throw new OoxmlException(sprintf(
                "The part '%s' expands to %s, over the %s limit for a single part.",
                $part,
                self::megabytes($size),
                self::megabytes($this->maxPartBytes),
            ));
        }

        $contents = $this->zip->getFromName($part);

        if ($contents === false) {
            throw new OoxmlException("The part '$part' could not be read.");
        }

        return $contents;
    }

    /** A parsed part, or null when it is not in the package. Parsed once. */
    public function xml(string $part): ?XmlPart
    {
        if (array_key_exists($part, $this->parsed)) {
            return $this->parsed[$part];
        }
        if (! $this->has($part)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument;
        // LIBXML_NONET blocks network fetches; entity substitution stays off,
        // which is what keeps a package from expanding a billion laughs into
        // memory. Neither is theoretical for files uploaded by the public.
        $loaded = $document->loadXML($this->contents($part), LIBXML_NONET | LIBXML_COMPACT);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            $first = $errors[0]->message ?? 'unknown error';

            throw new OoxmlException("The part '$part' is not well-formed XML: ".trim($first));
        }

        return $this->parsed[$part] = new XmlPart($part, $document);
    }

    /** Like xml(), but for a part a rule set cannot work without. */
    public function requireXml(string $part): XmlPart
    {
        return $this->xml($part) ?? throw new OoxmlException("The package has no part named '$part'.");
    }

    /**
     * The relationships declared by a part, with targets resolved to part names.
     *
     * Pass an empty string for the package-level relationships in `_rels/.rels`,
     * which is where the main document part is named.
     *
     * @return list<Relationship>
     */
    public function relationships(string $part = ''): array
    {
        $source = self::relationshipPartFor($part);

        if (isset($this->relationships[$source])) {
            return $this->relationships[$source];
        }

        $xml = $this->xml($source);
        if ($xml === null) {
            return $this->relationships[$source] = [];
        }

        // Targets are relative to the directory of the part that owns them, so
        // a slide's "../slideLayouts/slideLayout1.xml" resolves against
        // "ppt/slides" and not against the package root.
        $base = $part === '' ? '' : self::directoryOf($part);

        $relationships = [];
        foreach ($xml->elements('/rel:Relationships/rel:Relationship') as $element) {
            $target = $element->getAttribute('Target');
            $external = strcasecmp($element->getAttribute('TargetMode'), 'External') === 0;

            $relationships[] = new Relationship(
                id: $element->getAttribute('Id'),
                type: $element->getAttribute('Type'),
                target: $external ? $target : self::resolve($base, $target),
                external: $external,
            );
        }

        return $this->relationships[$source] = $relationships;
    }

    /** @return list<Relationship> */
    public function relationshipsOfType(string $shortType, string $part = ''): array
    {
        return array_values(array_filter(
            $this->relationships($part),
            static fn (Relationship $relationship): bool => $relationship->is($shortType),
        ));
    }

    public function relationship(string $id, string $part = ''): ?Relationship
    {
        foreach ($this->relationships($part) as $relationship) {
            if ($relationship->id === $id) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * The part the package points at as its main document: `word/document.xml`,
     * `ppt/presentation.xml`, `xl/workbook.xml`.
     *
     * Read from the relationships rather than assumed, because the conventional
     * names are a convention and nothing more.
     */
    public function mainPart(): ?string
    {
        return $this->relationshipsOfType('officeDocument')[0]->target ?? null;
    }

    public function contentType(string $part): ?string
    {
        $types = $this->loadContentTypes();
        $override = $types['overrides']['/'.ltrim($part, '/')] ?? null;

        if ($override !== null) {
            return $override;
        }

        $extension = strtolower(pathinfo($part, PATHINFO_EXTENSION));

        return $types['defaults'][$extension] ?? null;
    }

    /** @return list<string> */
    public function partsOfContentType(string $contentType): array
    {
        return array_values(array_filter(
            $this->names,
            fn (string $part): bool => $this->contentType($part) === $contentType,
        ));
    }

    /** docProps/core.xml, where dc:title and dc:language live in all three formats. */
    public function coreProperties(): ?XmlPart
    {
        $part = $this->relationshipsOfType('core-properties')[0]->target ?? 'docProps/core.xml';

        return $this->xml($part);
    }

    /** The document title, or null when there is not one. Absent and empty are both null. */
    public function title(): ?string
    {
        return $this->coreProperties()?->text('/cp:coreProperties/dc:title');
    }

    public function language(): ?string
    {
        return $this->coreProperties()?->text('/cp:coreProperties/dc:language');
    }

    /** @return array{defaults: array<string, string>, overrides: array<string, string>} */
    private function loadContentTypes(): array
    {
        if ($this->contentTypes !== null) {
            return $this->contentTypes;
        }

        $xml = $this->xml(self::CONTENT_TYPES);
        $defaults = [];
        $overrides = [];

        foreach ($xml?->elements('/ct:Types/ct:Default') ?? [] as $element) {
            $defaults[strtolower($element->getAttribute('Extension'))] = $element->getAttribute('ContentType');
        }
        foreach ($xml?->elements('/ct:Types/ct:Override') ?? [] as $element) {
            $overrides[$element->getAttribute('PartName')] = $element->getAttribute('ContentType');
        }

        return $this->contentTypes = ['defaults' => $defaults, 'overrides' => $overrides];
    }

    /** `word/document.xml` keeps its relationships in `word/_rels/document.xml.rels`. */
    private static function relationshipPartFor(string $part): string
    {
        if ($part === '') {
            return '_rels/.rels';
        }

        $directory = self::directoryOf($part);
        $file = basename($part);

        return ($directory === '' ? '' : $directory.'/')."_rels/$file.rels";
    }

    private static function directoryOf(string $part): string
    {
        $position = strrpos($part, '/');

        return $position === false ? '' : substr($part, 0, $position);
    }

    /** Resolve a relationship target against the directory of its owning part. */
    private static function resolve(string $base, string $target): string
    {
        // A leading slash means the package root, not the filesystem root.
        $path = str_starts_with($target, '/')
            ? ltrim($target, '/')
            : ($base === '' ? $target : $base.'/'.$target);

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /** A size a person can read. "0 MB" in an error message helps nobody. */
    private static function megabytes(int $bytes): string
    {
        return $bytes < 1_048_576
            ? round($bytes / 1_024).' KB'
            : round($bytes / 1_048_576, 1).' MB';
    }
}
