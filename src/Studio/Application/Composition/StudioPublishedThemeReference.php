<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use stdClass;

/**
 * Exact trusted public-theme coordinate bound into a Blueprint and host-session generation.
 *
 * @since  2.0.0
 */
final readonly class StudioPublishedThemeReference
{
    /**
     * Capture one schema-valid locked artifact reference.
     *
     * @param  string  $id        Active built-in or signed extension theme identifier.
     * @param  string  $version   Active theme package version.
     * @param  string  $revision  Digest binding package bytes and validated site presentation.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $revision,
    ) {
    }

    /**
     * Export the canonical Studio locked-artifact shape.
     *
     * @return  stdClass  Exact `id`, `version`, and `revision` members.
     *
     * @since   2.0.0
     */
    public function document(): stdClass
    {
        return (object) [
            'id' => $this->id,
            'version' => $this->version,
            'revision' => $this->revision,
        ];
    }

    /**
     * Compare a decoded artifact lock without accepting partial or additional coordinates.
     *
     * @param   mixed  $candidate  Decoded Blueprint dependency-lock member.
     *
     * @return  bool  True only for this exact public-theme coordinate.
     *
     * @since   2.0.0
     */
    public function matches(mixed $candidate): bool
    {
        if (!$candidate instanceof stdClass) {
            return false;
        }
        $members = get_object_vars($candidate);

        return count($members) === 3
            && ($members['id'] ?? null) === $this->id
            && ($members['version'] ?? null) === $this->version
            && ($members['revision'] ?? null) === $this->revision;
    }
}
