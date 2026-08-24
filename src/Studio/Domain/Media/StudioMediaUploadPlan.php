<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

use InvalidArgumentException;
use stdClass;

/**
 * Deterministic bounded transfer plan derived solely from host upload policy.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaUploadPlan
{
    /**
     * Capture a plan within the canonical media bounds.
     *
     * @param   int       $maximumBytes  Inclusive transfer ceiling.
     * @param   bool      $resumable     Whether interrupted transfers may resume.
     * @param   int|null  $chunkBytes    Optional transport chunk bound.
     *
     * @throws  InvalidArgumentException  When either bound is outside the canonical schema.
     *
     * @since   2.0.0
     */
    public function __construct(
        public int $maximumBytes,
        public bool $resumable,
        public ?int $chunkBytes = null,
    ) {
        if ($maximumBytes < 1 || $maximumBytes > 1_099_511_627_776) {
            throw new InvalidArgumentException('The Studio upload maximum is invalid.');
        }
        if ($chunkBytes !== null && ($chunkBytes < 1024 || $chunkBytes > 1_073_741_824)) {
            throw new InvalidArgumentException('The Studio upload chunk bound is invalid.');
        }
    }

    /**
     * Export the exact plan object the media grant and vector corpus carry.
     *
     * @return  stdClass
     *
     * @since   2.0.0
     */
    public function document(): stdClass
    {
        $plan = (object) [
            'maximumBytes' => $this->maximumBytes,
            'resumable' => $this->resumable,
        ];
        if ($this->chunkBytes !== null) {
            $plan->chunkBytes = $this->chunkBytes;
        }

        return $plan;
    }
}
