<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Artifact;

use JsonException;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use RuntimeException;
use stdClass;

/**
 * One immutable, schema-admitted Studio artifact revision.
 *
 * @since  2.0.0
 */
final readonly class StoredStudioArtifact
{
    /**
     * Retain exact canonical document and dependency bytes beside their routing identity.
     *
     * @param  string  $siteIdentifier         Trusted owning site.
     * @param  string  $id                     Artifact identity.
     * @param  string  $version                Artifact semantic version, or the entry sentinel.
     * @param  string  $kind                   Closed Studio artifact kind.
     * @param  string  $revision               Immutable revision identity.
     * @param  string  $status                 Canonical lifecycle status.
     * @param  string  $canonicalDocument      Exact canonical artifact bytes.
     * @param  string  $canonicalDependencies  Exact canonical ArtifactReference-list bytes.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $siteIdentifier,
        public string $id,
        public string $version,
        public string $kind,
        public string $revision,
        public string $status,
        public string $canonicalDocument,
        public string $canonicalDependencies,
    ) {
        if (
            $siteIdentifier === '' || $id === '' || $version === '' || $revision === ''
            || !in_array($kind, ['blueprint', 'content-model', 'entry'], true)
        ) {
            throw new RuntimeException('A stored Studio artifact identity is invalid.');
        }
        $document = $this->decode($canonicalDocument);
        if (
            !property_exists($document, 'id') || $document->id !== $id
            || !property_exists($document, 'kind') || $document->kind !== $kind
            || !property_exists($document, 'revision') || $document->revision !== $revision
            || !property_exists($document, 'status') || $document->status !== $status
            || !hash_equals($canonicalDocument, CanonicalJson::stringify($document))
        ) {
            throw new RuntimeException('Stored Studio artifact bytes do not match their identity.');
        }
        try {
            $dependencies = json_decode($canonicalDependencies, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored Studio dependencies are corrupt.', 0, $exception);
        }
        if (
            !is_array($dependencies) || !hash_equals(
                $canonicalDependencies,
                CanonicalJson::stringify($dependencies),
            )
        ) {
            throw new RuntimeException('Stored Studio dependencies are not a canonical list.');
        }
    }

    /**
     * Decode and prove the exact artifact bytes for delivery.
     *
     * @return  stdClass  Canonical artifact document.
     *
     * @since   2.0.0
     */
    public function document(): stdClass
    {
        return $this->decode($this->canonicalDocument);
    }

    /**
     * Decode dependency references without changing their canonical representation.
     *
     * @return  list<stdClass>  Canonically ordered artifact references.
     *
     * @since   2.0.0
     */
    public function dependencies(): array
    {
        try {
            $dependencies = json_decode($this->canonicalDependencies, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored Studio dependencies are corrupt.', 0, $exception);
        }
        if (!is_array($dependencies)) {
            throw new RuntimeException('Stored Studio dependencies are corrupt.');
        }
        foreach ($dependencies as $dependency) {
            if (!$dependency instanceof stdClass) {
                throw new RuntimeException('Stored Studio dependencies are corrupt.');
            }
        }

        /** @var list<stdClass> $dependencies */
        return $dependencies;
    }

    /**
     * Decode one canonical object document.
     *
     * @param   string  $bytes  Canonical JSON bytes.
     *
     * @return  stdClass  Decoded object.
     *
     * @since   2.0.0
     */
    private function decode(string $bytes): stdClass
    {
        try {
            $document = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored Studio artifact bytes are corrupt.', 0, $exception);
        }
        if (!$document instanceof stdClass) {
            throw new RuntimeException('Stored Studio artifact bytes are corrupt.');
        }

        return $document;
    }
}
