<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use stdClass;

/**
 * Registry boundary translating admitted block identifiers into safe preview fragments.
 *
 * @since  2.0.0
 */
interface StudioPreviewBlockRendererRegistry
{
    /**
     * Report whether one exact dependency-lock coordinate has a live executable implementation.
     *
     * @param   StudioPreviewBlockReference  $reference  Candidate block type, version, and revision.
     *
     * @return  bool  True only for an exact built-in coordinate or a live trusted contribution.
     *
     * @since   2.0.0
     */
    public function supports(StudioPreviewBlockReference $reference): bool;

    /**
     * Resolve one block through an owner-safe renderer registration.
     *
     * @param   stdClass                     $node       Schema-admitted Blueprint node.
     * @param   StudioPreviewBlockReference  $reference  Exact node and dependency-lock coordinate.
     * @param   StudioPreviewBindingResult   $binding    Safely resolved canonical `value` port.
     * @param   string                       $viewport   Active semantic viewport used for responsive intent.
     *
     * @return  StudioPreviewBlockFragment  Fixed presentation names and plain text only.
     *
     * @since   2.0.0
     */
    public function render(
        stdClass $node,
        StudioPreviewBlockReference $reference,
        StudioPreviewBindingResult $binding,
        string $viewport,
    ): StudioPreviewBlockFragment;
}
