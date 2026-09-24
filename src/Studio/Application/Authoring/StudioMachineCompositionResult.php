<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use stdClass;

/**
 * One accepted Blueprint artifact operation as Producer answered it, plus the replay evidence the wire omits.
 *
 * The document is the canonical Producer result exactly as the composition screen's Studio shell receives it:
 * a load answers the artifact document and its revision, dependencies answer the locked list, and a save,
 * publish or unpublish answers no value and the revision it appended. The replay flag comes from the App
 * mutation boundary, as it does for the authoring operations.
 *
 * @since  2.0.0
 */
final readonly class StudioMachineCompositionResult
{
    /**
     * Capture one accepted operation.
     *
     * @param  StudioMachineCompositionOperation  $operation  Operation that was performed or replayed.
     * @param  stdClass                           $document   Canonical Producer result document: `value` plus an
     *         optional `revision`.
     * @param  bool                               $replayed   Whether a keyed mutation was answered from its stored
     *         outcome instead of being performed again.
     *
     * @since  2.0.0
     */
    public function __construct(
        public StudioMachineCompositionOperation $operation,
        public stdClass $document,
        public bool $replayed,
    ) {
    }

    /**
     * The machine document every surface answers: operation, replay flag, value and revision.
     *
     * @return  stdClass  `{operation, replayed, value, revision}`, with a null value for a lifecycle mutation.
     *
     * @since   2.0.0
     */
    public function toDocument(): stdClass
    {
        $revision = $this->document->revision ?? null;

        return (object) [
            'operation' => $this->operation->value,
            'replayed' => $this->replayed,
            'value' => $this->document->value ?? null,
            'revision' => is_string($revision) ? $revision : null,
        ];
    }
}
