<?php

declare(strict_types=1);

namespace Bpmore\DocumentA11yCore\Ooxml;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

/**
 * One parsed XML part, with every OOXML namespace already registered.
 *
 * Rules query with XPath rather than walking the DOM by hand: the structures
 * they care about are usually several levels down through elements that are not
 * interesting in themselves, and `//w:drawing//wp:docPr` says what it is looking
 * for in a way that a chain of firstChild calls does not.
 */
final class XmlPart
{
    private readonly DOMXPath $xpath;

    public function __construct(
        public readonly string $name,
        public readonly DOMDocument $document,
    ) {
        $this->xpath = new DOMXPath($document);

        foreach (Namespaces::MAP as $prefix => $uri) {
            $this->xpath->registerNamespace($prefix, $uri);
        }
    }

    /** @return DOMNodeList<DOMNode> */
    public function query(string $expression, ?DOMNode $context = null): DOMNodeList
    {
        $result = $context === null
            ? $this->xpath->query($expression)
            : $this->xpath->query($expression, $context);

        if ($result === false) {
            throw new OoxmlException("Not a valid XPath expression for {$this->name}: $expression");
        }

        return $result;
    }

    /** @return list<DOMElement> */
    public function elements(string $expression, ?DOMNode $context = null): array
    {
        $elements = [];
        foreach ($this->query($expression, $context) as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    public function first(string $expression, ?DOMNode $context = null): ?DOMElement
    {
        return $this->elements($expression, $context)[0] ?? null;
    }

    public function exists(string $expression, ?DOMNode $context = null): bool
    {
        return $this->query($expression, $context)->length > 0;
    }

    public function count(string $expression, ?DOMNode $context = null): int
    {
        return $this->query($expression, $context)->length;
    }

    /** The text of the first match, trimmed, or null when there is no match. */
    public function text(string $expression, ?DOMNode $context = null): ?string
    {
        $element = $this->first($expression, $context);
        if ($element === null) {
            return null;
        }

        $text = trim($element->textContent);

        return $text === '' ? null : $text;
    }

    /**
     * A namespaced attribute, distinguishing absent from empty.
     *
     * That distinction is the whole of the alt-text rules: `descr=""` and no
     * `descr` at all are different in the XML and identical to somebody using a
     * screen reader, so both have to be reportable and neither may be mistaken
     * for the other.
     */
    public function attribute(DOMElement $element, string $name, ?string $prefix = null): ?string
    {
        if ($prefix === null) {
            return $element->hasAttribute($name) ? $element->getAttribute($name) : null;
        }

        $uri = Namespaces::MAP[$prefix] ?? throw new OoxmlException("Unknown namespace prefix '$prefix'.");

        return $element->hasAttributeNS($uri, $name) ? $element->getAttributeNS($uri, $name) : null;
    }
}
