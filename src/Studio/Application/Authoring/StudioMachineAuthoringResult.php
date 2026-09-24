<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use stdClass;

/**
 * One accepted Studio authoring operation as Producer answered it, plus the replay evidence the wire omits.
 *
 * The document is the canonical Producer result exactly as the browser receives it, so every machine
 * surface hands its caller the same bytes the Studio shell reconciles against. The replay flag comes from
 * the App mutation boundary rather than the wire: Producer answers a replayed keyed mutation with the same
 * canonical result and no marker, while the machine contracts promise to say when a retry was answered
 * from the stored outcome.
 *
 * @since  2.0.0
 */
final readonly class StudioMachineAuthoringResult
{
    /**
     * Capture one accepted operation.
     *
     * @param  StudioMachineAuthoringOperation  $operation  Operation that was performed or replayed.
     * @param  stdClass                         $document   Canonical Producer result document: `value` plus an
     *         optional `revision`.
     * @param  bool                             $replayed   Whether a keyed mutation was answered from its stored
     *         outcome instead of being performed again.
     *
     * @since  2.0.0
     */
    public function __construct(
        public StudioMachineAuthoringOperation $operation,
        public stdClass $document,
        public bool $replayed,
    ) {
    }

    /**
     * The operation's value document, which the pinned Studio schema for that operation describes.
     *
     * @return  stdClass  The `value` member of the canonical result.
     *
     * @since   2.0.0
     */
    public function value(): stdClass
    {
        $value = $this->document->value ?? null;

        return $value instanceof stdClass ? $value : new stdClass();
    }
}
