<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Domain;

/**
 * Durable lifecycle of one immutable report export artifact.
 *
 * @since  2.0.0
 */
enum ExportArtifactStatus: string
{
    /** Waiting for a durable queue worker. @since 2.0.0 */
    case Queued = 'queued';
    /** A worker has claimed and started the export. @since 2.0.0 */
    case Running = 'running';
    /** Bytes and checksum were stored successfully. @since 2.0.0 */
    case Completed = 'completed';
    /** Generation ended permanently without an artifact. @since 2.0.0 */
    case Failed = 'failed';
}
