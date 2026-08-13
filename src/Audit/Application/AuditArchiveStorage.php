<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Application;

use Kumwe\CMS\Audit\Domain\StoredAuditArchive;

/**
 * Port for the private, permission-locked storage audit archives are written into.
 *
 * An archive is written once and never overwritten: implementations must publish atomically, keep the
 * file readable by the application account only, and hand back the byte size and checksum computed
 * over exactly the bytes that landed. Export and retention both write through this port, so incident
 * preservation and retention archiving produce files with identical storage guarantees.
 *
 * @since  2.0.0
 */
interface AuditArchiveStorage
{
    /**
     * Write, checksum and atomically publish one private audit archive.
     *
     * @param   string            $archiveId  Canonical UUID naming this archive.
     * @param   iterable<string>  $chunks     Ordered NDJSON chunks to persist.
     *
     * @return  StoredAuditArchive  Key, size and checksum evidence for the stored bytes.
     *
     * @throws  \RuntimeException  When a chunk is invalid or the filesystem refuses a safe write.
     *
     * @since   2.0.0
     */
    public function store(string $archiveId, iterable $chunks): StoredAuditArchive;
}
