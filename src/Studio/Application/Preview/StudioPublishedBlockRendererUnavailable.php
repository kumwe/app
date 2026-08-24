<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use RuntimeException;

/**
 * Refuses public output when an immutable Blueprint block lock has no exact live renderer.
 *
 * Preview may retain an inert diagnostic while an extension is withdrawn. Published output may not:
 * silently dropping a block would make the public page disagree with the artifact that was approved.
 *
 * @since  2.0.0
 */
final class StudioPublishedBlockRendererUnavailable extends RuntimeException
{
    /**
     * Name only the failed immutable coordinate, never the Content values being rendered.
     *
     * @param  string   $type      Locked block type.
     * @param  string   $version   Locked block version.
     * @param  ?string  $revision  Locked block revision, null when the node has no exact lock.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $type,
        public readonly string $version,
        public readonly ?string $revision,
    ) {
        parent::__construct(sprintf(
            'The published Studio block renderer for %s at %s is unavailable.',
            $type,
            $version,
        ));
    }
}
