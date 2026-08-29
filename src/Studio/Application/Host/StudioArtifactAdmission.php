<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Artifact\StudioStoredDocumentPolicy;
use Kumwe\App\Studio\Domain\Artifact\UnsafeStudioStoredDocument;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use stdClass;

/**
 * Schema, canonical-byte and active-content admission boundary for Studio artifacts.
 *
 * @since  2.0.0
 */
final readonly class StudioArtifactAdmission
{
    /**
     * Bind artifact admission to Producer's exact pinned Studio schema registry.
     *
     * @param  StudioDocumentSchemaRegistry  $schemas  Exact contract schema interpreter.
     *
     * @since  2.0.0
     */
    public function __construct(private StudioDocumentSchemaRegistry $schemas)
    {
    }

    /**
     * Validate and canonicalize one artifact without rewriting accepted user data.
     *
     * @param   string  $siteIdentifier  Trusted site scope.
     * @param   mixed   $document        Candidate Studio document.
     *
     * @return  StoredStudioArtifact  Admitted immutable artifact revision.
     *
     * @throws  HostRefusal  When the artifact is unsupported, lossy or unsafe.
     *
     * @since   2.0.0
     */
    public function admit(string $siteIdentifier, mixed $document): StoredStudioArtifact
    {
        if (!$document instanceof stdClass || !property_exists($document, 'kind') || !is_string($document->kind)) {
            StudioProducerError::refuse('validation-failed', 'studio.artifact/invalid-document');
        }
        $kind = $document->kind;
        if (!in_array($kind, ['blueprint', 'content-model', 'entry'], true)) {
            StudioProducerError::refuse('validation-failed', 'studio.artifact/unsupported-kind');
        }
        if (!$this->schemas->validate($kind, $document)->valid()) {
            StudioProducerError::refuse('validation-failed', 'studio.artifact/schema-invalid');
        }
        try {
            StudioStoredDocumentPolicy::assertSafe($document);
        } catch (UnsafeStudioStoredDocument $refused) {
            StudioProducerError::refuse(
                'validation-failed',
                'studio.artifact/' . $refused->rejection->value,
            );
        }
        $id = $this->stringMember($document, 'id');
        $revision = $this->stringMember($document, 'revision');
        $status = $this->stringMember($document, 'status');
        $version = $kind === 'entry'
            ? $this->stringMember($this->objectMember($document, 'model'), 'version')
            : $this->stringMember($document, 'version');
        $dependencies = $this->dependencies($document);

        return new StoredStudioArtifact(
            $siteIdentifier,
            $id,
            $version,
            $kind,
            $revision,
            $status,
            CanonicalJson::stringify($document),
            CanonicalJson::stringify($dependencies),
        );
    }

    /**
     * Replace only host-owned revision/status fields, then re-run full admission.
     *
     * @param   StoredStudioArtifact  $artifact  Current admitted artifact.
     * @param   string                $revision  Host-generated next revision.
     * @param   string                $status    Next canonical lifecycle status.
     *
     * @return  StoredStudioArtifact  Fully re-admitted replacement revision.
     *
     * @since   2.0.0
     */
    public function revise(
        StoredStudioArtifact $artifact,
        string $revision,
        string $status,
    ): StoredStudioArtifact {
        $document = $artifact->document();
        $document->revision = $revision;
        $document->status = $status;

        return $this->admit($artifact->siteIdentifier, $document);
    }

    /**
     * Derive the complete locked dependency set from the schema-valid document.
     *
     * @param   stdClass  $document  Admitted Studio artifact.
     *
     * @return  list<stdClass>  Deduplicated references in canonical byte order.
     *
     * @since   2.0.0
     */
    private function dependencies(stdClass $document): array
    {
        $dependencies = [];
        if ($document->kind === 'blueprint') {
            $dependencies[] = $this->reference($this->objectMember($document, 'model'));
            $lock = $this->objectMember($document, 'dependencyLock');
            $dependencies[] = $this->reference($this->objectMember($lock, 'theme'));
            foreach ($this->listMember($lock, 'blocks') as $block) {
                if (!$block instanceof stdClass) {
                    StudioProducerError::refuse(
                        'validation-failed',
                        'studio.artifact/invalid-document',
                    );
                }
                $reference = (object) [
                    'id' => $this->stringMember($block, 'type'),
                    'version' => $this->stringMember($block, 'version'),
                    'revision' => $this->stringMember($block, 'revision'),
                ];
                if (property_exists($block, 'integrity')) {
                    $reference->integrity = $this->stringMember($block, 'integrity');
                }
                $dependencies[] = $reference;
            }
            if (property_exists($lock, 'plugins')) {
                foreach ($this->listMember($lock, 'plugins') as $plugin) {
                    if (!$plugin instanceof stdClass) {
                        StudioProducerError::refuse(
                            'validation-failed',
                            'studio.artifact/invalid-document',
                        );
                    }
                    $dependencies[] = $this->reference($plugin);
                }
            }
        } elseif ($document->kind === 'content-model') {
            foreach ($this->listMember($document, 'relationships') as $relationship) {
                if (!$relationship instanceof stdClass) {
                    StudioProducerError::refuse(
                        'validation-failed',
                        'studio.artifact/invalid-document',
                    );
                }
                $dependencies[] = $this->reference($this->objectMember($relationship, 'targetModel'));
            }
        } else {
            $dependencies[] = $this->reference($this->objectMember($document, 'model'));
        }

        $unique = [];
        foreach ($dependencies as $dependency) {
            $unique[CanonicalJson::stringify($dependency)] = $dependency;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /**
     * Clone one locked artifact reference without retaining foreign object identity.
     *
     * @param   stdClass  $source  Schema-valid reference object.
     *
     * @return  stdClass  Closed copied reference.
     *
     * @since   2.0.0
     */
    private function reference(stdClass $source): stdClass
    {
        $reference = (object) [
            'id' => $this->stringMember($source, 'id'),
            'version' => $this->stringMember($source, 'version'),
        ];
        foreach (['integrity', 'revision'] as $optional) {
            if (property_exists($source, $optional)) {
                $reference->{$optional} = $this->stringMember($source, $optional);
            }
        }

        return $reference;
    }

    /**
     * Require one object-valued member from a schema-valid document.
     *
     * @param   stdClass  $object  Source object.
     * @param   string    $member  Required member name.
     *
     * @return  stdClass  Required object member.
     *
     * @since   2.0.0
     */
    private function objectMember(stdClass $object, string $member): stdClass
    {
        $value = $object->{$member} ?? null;
        if (!$value instanceof stdClass) {
            StudioProducerError::refuse('validation-failed', 'studio.artifact/invalid-document');
        }

        return $value;
    }

    /**
     * Require one dense list-valued member from a schema-valid document.
     *
     * @param   stdClass  $object  Source object.
     * @param   string    $member  Required member name.
     *
     * @return  list<mixed>  Dense JSON array member.
     *
     * @since   2.0.0
     */
    private function listMember(stdClass $object, string $member): array
    {
        $value = $object->{$member} ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            StudioProducerError::refuse('validation-failed', 'studio.artifact/invalid-document');
        }

        return $value;
    }

    /**
     * Require one non-empty string member from a schema-valid document.
     *
     * @param   stdClass  $object  Source object.
     * @param   string    $member  Required member name.
     *
     * @return  string  Required non-empty value.
     *
     * @since   2.0.0
     */
    private function stringMember(stdClass $object, string $member): string
    {
        $value = $object->{$member} ?? null;
        if (!is_string($value) || $value === '') {
            StudioProducerError::refuse('validation-failed', 'studio.artifact/invalid-document');
        }

        return $value;
    }
}
