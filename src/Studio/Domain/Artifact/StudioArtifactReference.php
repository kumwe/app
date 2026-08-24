<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Artifact;

use InvalidArgumentException;
use stdClass;

/**
 * Strict canonical ArtifactReference value used at the App host boundary.
 *
 * @since  2.0.0
 */
final readonly class StudioArtifactReference
{
    /**
     * Create one closed canonical artifact reference.
     *
     * @param  string       $id         Canonical artifact identifier.
     * @param  string       $version    Canonical artifact version.
     * @param  string|null  $revision   Optional immutable revision.
     * @param  string|null  $integrity  Optional integrity token.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $version,
        public ?string $revision = null,
        public ?string $integrity = null,
    ) {
        if (
            $id === '' || $version === '' || $revision === '' || $integrity === ''
            || strlen($id) > 240 || strlen($version) > 100
            || $revision !== null && strlen($revision) > 200
        ) {
            throw new InvalidArgumentException('The Studio artifact reference is invalid.');
        }
    }

    /**
     * Project the value into the canonical semantic mutation argument.
     *
     * @return  stdClass  Closed ArtifactReference object.
     *
     * @since   2.0.0
     */
    public function document(): stdClass
    {
        $document = (object) ['id' => $this->id, 'version' => $this->version];
        if ($this->integrity !== null) {
            $document->integrity = $this->integrity;
        }
        if ($this->revision !== null) {
            $document->revision = $this->revision;
        }

        return $document;
    }
}
