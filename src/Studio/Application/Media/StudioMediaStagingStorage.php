<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use Psr\Http\Message\StreamInterface;

/**
 * Private host-owned staging custody for authorized Studio upload bytes.
 *
 * @since  2.0.0
 */
interface StudioMediaStagingStorage
{
    /**
     * Allocate an empty private object for one new grant.
     *
     * @param   string  $uploadId  Opaque host upload identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function create(string $uploadId): void;

    /**
     * Replace the staged body through a bounded stream and return its exact size.
     *
     * @param   string           $uploadId      Opaque host upload identity.
     * @param   StreamInterface  $source        Request body; never materialized as one string.
     * @param   int              $maximumBytes  Inclusive transfer quota.
     *
     * @return  int  Exact received byte count.
     *
     * @since   2.0.0
     */
    public function write(string $uploadId, StreamInterface $source, int $maximumBytes): int;

    /**
     * Return the private path after a complete bounded transfer.
     *
     * @param   string  $uploadId  Opaque host upload identity.
     *
     * @return  string  Absolute private path.
     *
     * @since   2.0.0
     */
    public function path(string $uploadId): string;

    /**
     * Remove staged bytes after cancellation, completion or failure.
     *
     * @param   string  $uploadId  Opaque host upload identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function delete(string $uploadId): void;
}
