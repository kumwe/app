<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use LogicException;

/**
 * Closed readiness result deciding whether the Content surface may mount contextual Studio.
 *
 * @since  2.0.0
 */
final readonly class StudioContextualAuthoringReadiness
{
    /**
     * Hold a mutually exclusive ready or fallback result.
     *
     * @param   bool                                      $available  Whether contextual mounting is qualified.
     * @param   ?StudioContextualAuthoringFallbackReason  $reason     Required reason while unavailable.
     *
     * @throws  LogicException  When availability and reason do not describe one closed state.
     *
     * @since   2.0.0
     */
    private function __construct(
        public bool $available,
        public ?StudioContextualAuthoringFallbackReason $reason,
    ) {
        if ($available === ($reason !== null)) {
            throw new LogicException('Studio contextual authoring readiness is inconsistent.');
        }
    }

    /**
     * Declare that every pinned protocol, browser, and PHP readiness check passed.
     *
     * @return  self  Qualified contextual authoring readiness.
     *
     * @since   2.0.0
     */
    public static function available(): self
    {
        return new self(true, null);
    }

    /**
     * Declare the structured editor fallback with one stable reason.
     *
     * @param   StudioContextualAuthoringFallbackReason  $reason  First failed readiness boundary.
     *
     * @return  self  Fail-closed contextual authoring readiness.
     *
     * @since   2.0.0
     */
    public static function fallback(StudioContextualAuthoringFallbackReason $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * Present a closed template-safe state without deployment details.
     *
     * @return  array{available: bool, fallback: string, reason: ?string}  Readiness view.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'fallback' => 'structured-form',
            'reason' => $this->reason?->value,
        ];
    }
}
