<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

/**
 * Private immutable byte store for generated export artifacts.
 *
 * @since  2.0.0
 */
interface ExportArtifactStorage
{
    /**
     * Atomically store chunks under a fresh attempt-owned key without overwriting.
     *
     * Every invocation must return a different unguessable key, including concurrent invocations for the
     * same artifact. This fences cleanup to bytes owned by the calling generation attempt.
     *
     * @param   string            $artifactId  Canonical artifact UUID that owns the generation attempt.
     * @param   iterable<string>  $chunks      Ordered CSV byte chunks.
     *
     * @return  StoredExportArtifact  Stored key, size and checksum.
     *
     * @since   2.0.0
     */
    public function store(string $artifactId, iterable $chunks): StoredExportArtifact;

    /**
     * Open verified artifact bytes for streaming.
     *
     * @param   StoredExportArtifact  $artifact  Expected key, size and checksum.
     *
     * @return  resource  Read-only stream positioned at byte zero.
     *
     * @since   2.0.0
     */
    public function open(StoredExportArtifact $artifact): mixed;

    /**
     * Remove an expired private object idempotently.
     *
     * @param   string  $key  Opaque storage key previously returned by `store()`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function delete(string $key): void;
}
