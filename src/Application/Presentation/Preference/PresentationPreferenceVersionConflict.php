<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Presentation\Preference;

use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use RuntimeException;

/**
 * Signals that a presentation preference compare-and-swap observed another version.
 *
 * @since  2.0.0
 */
final class PresentationPreferenceVersionConflict extends RuntimeException
{
    /**
     * Describe the expected and currently stored versions without exposing the preference value.
     *
     * @param  PresentationPreferenceKey  $key              Durable key whose version moved.
     * @param  int                        $expectedVersion  Version supplied by the caller.
     * @param  int                        $actualVersion    Stored version, or zero when the record is absent.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly PresentationPreferenceKey $key,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct(sprintf(
            'The KIS presentation preference version changed (expected %d, actual %d).',
            $expectedVersion,
            $actualVersion,
        ));
    }
}
