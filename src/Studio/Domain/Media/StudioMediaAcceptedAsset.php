<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

use InvalidArgumentException;
use stdClass;

/**
 * Small accepted-asset identity returned at upload completion and status polling.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaAcceptedAsset
{
    /**
     * Capture an asset coordinate whose readiness remains host-owned.
     *
     * @param   string  $id        Canonical stable media asset identity.
     * @param   string  $revision  Immutable projection revision.
     * @param   string  $state     Processing, ready, rejected or quarantined.
     *
     * @throws  InvalidArgumentException  When identity, revision or state is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $revision,
        public string $state,
    ) {
        if (
            strlen($id) > 240
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $id) !== 1
            || in_array($id, ['__proto__', 'prototype', 'constructor'], true)
        ) {
            throw new InvalidArgumentException('The Studio accepted media identity is invalid.');
        }
        if ($revision === '' || strlen($revision) > 200) {
            throw new InvalidArgumentException('The Studio accepted media revision is invalid.');
        }
        if (!in_array($state, ['processing', 'ready', 'rejected', 'quarantined'], true)) {
            throw new InvalidArgumentException('The Studio accepted media state is invalid.');
        }
    }

    /**
     * Export the canonical small accepted-asset shape.
     *
     * @return  stdClass
     *
     * @since   2.0.0
     */
    public function document(): stdClass
    {
        return (object) [
            'id' => $this->id,
            'revision' => $this->revision,
            'state' => $this->state,
        ];
    }
}
