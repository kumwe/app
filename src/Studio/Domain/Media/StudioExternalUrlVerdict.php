<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

use InvalidArgumentException;

/**
 * Result of applying the canonical lexical URL policy without touching the network.
 *
 * @since  2.0.0
 */
final readonly class StudioExternalUrlVerdict
{
    /**
     * Keep exactly one accepted normalized URL or one stable rejection reason.
     *
     * @param   string|null                      $url        Normalized absolute URL when accepted.
     * @param   StudioExternalUrlRejection|null  $rejection  Stable reason when rejected.
     *
     * @throws  InvalidArgumentException  When both outcomes, or neither outcome, are supplied.
     *
     * @since   2.0.0
     */
    private function __construct(
        public ?string $url,
        public ?StudioExternalUrlRejection $rejection,
    ) {
        if (($url === null) === ($rejection === null)) {
            throw new InvalidArgumentException('A Studio external-URL verdict requires exactly one outcome.');
        }
    }

    /**
     * Accept one normalized public URL.
     *
     * @param   string  $url  Normalized absolute URL.
     *
     * @return  self
     *
     * @since   2.0.0
     */
    public static function accepted(string $url): self
    {
        return new self($url, null);
    }

    /**
     * Refuse a candidate under one stable canonical reason.
     *
     * @param   StudioExternalUrlRejection  $rejection  Stable policy reason.
     *
     * @return  self
     *
     * @since   2.0.0
     */
    public static function rejected(StudioExternalUrlRejection $rejection): self
    {
        return new self(null, $rejection);
    }

    /**
     * Report whether the policy accepted the candidate.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    public function acceptedUrl(): bool
    {
        return $this->url !== null;
    }
}
