<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Infrastructure;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Reads an XLIFF 2.0 document into the shape the catalogue compiler works from.
 *
 * XLIFF is the format every professional translation tool and platform already reads, which is why
 * it is what this repository authors and exchanges: a translator never opens a source file, and an
 * external translation service or an AI-assisted pipeline connects through a format it already
 * speaks rather than through bespoke tooling this repository would then have to own.
 *
 * This reader runs at build time only. Nothing on the request path parses XML — the request path
 * reads the compiled PHP the compiler emits from what this returns.
 *
 * The parse is deliberately strict. A document type declaration is refused outright rather than
 * being ignored, network entity resolution is disabled, and a unit without an identifier or without
 * source text is an error rather than a silently dropped message, because a catalogue that quietly
 * loses entries is indistinguishable from one that never had them.
 *
 * @since  2.0.0
 */
final readonly class XliffCatalogueReader
{
    /**
     * The XLIFF 2.0 namespace this reader accepts, and the only one it accepts.
     *
     * @var    string
     * @since  2.0.0
     */
    public const NAMESPACE_URI = 'urn:oasis:names:tc:xliff:document:2.0';

    /**
     * Read a document from a file.
     *
     * @param   string  $path  Absolute path of the XLIFF document.
     *
     * @return  XliffCatalogue  The parsed document.
     *
     * @throws  RuntimeException  When the file cannot be read, or its contents are not a well-formed
     *          XLIFF 2.0 document this reader accepts.
     *
     * @since   2.0.0
     */
    public function readFile(string $path): XliffCatalogue
    {
        $xml = file_get_contents($path);
        if (!is_string($xml)) {
            throw new RuntimeException(sprintf('The XLIFF catalogue %s cannot be read.', $path));
        }

        return $this->read($xml, $path);
    }

    /**
     * Read a document from a string.
     *
     * @param   string  $xml     Complete XLIFF document.
     * @param   string  $origin  Path or description used in error messages to locate the document.
     *
     * @return  XliffCatalogue  The parsed document.
     *
     * @throws  RuntimeException  When the document is not well formed, declares a document type,
     *          is not XLIFF 2.0, or carries a unit without an identifier or without source text.
     *
     * @since   2.0.0
     */
    public function read(string $xml, string $origin = 'the XLIFF catalogue'): XliffCatalogue
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            $errors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new RuntimeException(sprintf(
                '%s is not well-formed XML: %s',
                $origin,
                $errors === [] ? 'the parser reported no diagnostic.' : trim($errors[0]->message),
            ));
        }
        if ($document->doctype !== null) {
            throw new RuntimeException(sprintf('%s declares a document type, which is refused.', $origin));
        }

        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->localName !== 'xliff') {
            throw new RuntimeException(sprintf('%s has no xliff root element.', $origin));
        }
        if ($root->namespaceURI !== self::NAMESPACE_URI || $root->getAttribute('version') !== '2.0') {
            throw new RuntimeException(sprintf('%s is not an XLIFF 2.0 document.', $origin));
        }
        $sourceLanguage = $root->getAttribute('srcLang');
        if ($sourceLanguage === '') {
            throw new RuntimeException(sprintf('%s declares no srcLang.', $origin));
        }
        $targetLanguage = $root->getAttribute('trgLang');

        return new XliffCatalogue(
            $sourceLanguage,
            $targetLanguage === '' ? null : $targetLanguage,
            $this->units($document, $origin),
        );
    }

    /**
     * Collect every translation unit in document order.
     *
     * @param   DOMDocument  $document  Parsed document.
     * @param   string       $origin    Path or description used in error messages.
     *
     * @return  array<string, array{source: string, target: ?string}>  Source and target text keyed by
     *          message identifier.
     *
     * @throws  RuntimeException  When a unit has no identifier, has no source text, or repeats an
     *          identifier another unit already claimed.
     *
     * @since   2.0.0
     */
    private function units(DOMDocument $document, string $origin): array
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', self::NAMESPACE_URI);
        $nodes = $xpath->query('//x:unit');
        if ($nodes === false) {
            throw new RuntimeException(sprintf('%s cannot be queried for translation units.', $origin));
        }

        $units = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $identifier = $node->getAttribute('id');
            if ($identifier === '') {
                throw new RuntimeException(sprintf('%s carries a translation unit with no id.', $origin));
            }
            if (isset($units[$identifier])) {
                throw new RuntimeException(sprintf(
                    '%s declares the message identifier %s more than once.',
                    $origin,
                    $identifier,
                ));
            }
            $source = $this->segmentText($xpath, $node, 'source');
            if ($source === null) {
                throw new RuntimeException(sprintf(
                    '%s carries the translation unit %s with no source text.',
                    $origin,
                    $identifier,
                ));
            }
            $units[$identifier] = ['source' => $source, 'target' => $this->segmentText($xpath, $node, 'target')];
        }

        return $units;
    }

    /**
     * Read the text of one side of a unit's segments, joined across segments in document order.
     *
     * @param   DOMXPath    $xpath  Query context bound to the XLIFF namespace.
     * @param   DOMElement  $unit   Translation unit being read.
     * @param   string      $side   Either `source` or `target`.
     *
     * @return  ?string  The concatenated text, or null when the unit carries no element of that side.
     *
     * @since   2.0.0
     */
    private function segmentText(DOMXPath $xpath, DOMElement $unit, string $side): ?string
    {
        $nodes = $xpath->query('./x:segment/x:' . $side, $unit);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = '';
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $text .= $node->textContent;
            }
        }

        return $text;
    }
}
