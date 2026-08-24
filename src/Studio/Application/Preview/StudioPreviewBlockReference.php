<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use InvalidArgumentException;
use stdClass;

/**
 * Exact block coordinate selected from a Blueprint node and its dependency lock.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewBlockReference
{
    /**
     * Capture one node version and, when present, its immutable locked definition revision.
     *
     * @param   string       $type      Qualified block type.
     * @param   string       $version   Semantic block version carried by the node and lock.
     * @param   string|null  $revision  Locked definition revision, or null when the lock is absent or ambiguous.
     *
     * @throws  InvalidArgumentException  When a required coordinate is empty.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $type,
        public string $version,
        public ?string $revision,
    ) {
        if ($type === '' || $version === '' || $revision === '') {
            throw new InvalidArgumentException('A Studio preview block reference is incomplete.');
        }
    }

    /**
     * Require that this reference still describes the node about to be rendered.
     *
     * @param   stdClass  $node  Candidate Blueprint node.
     *
     * @return  bool  True only for the exact node type and version.
     *
     * @since   2.0.0
     */
    public function matchesNode(stdClass $node): bool
    {
        return ($node->type ?? null) === $this->type && ($node->version ?? null) === $this->version;
    }
}
