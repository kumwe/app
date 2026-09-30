<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Storage;

use InvalidArgumentException;
use Kumwe\App\Audit\Application\AuditCheckpoint;
use RuntimeException;

/**
 * Append-only private store of verified audit checkpoints, outside the database's authority.
 *
 * Each checkpoint is published once as its own file named by its zero-padded ledger sequence and head
 * position, created exclusively and never rewritten or removed by the application. The retained mark is
 * the highest name present, so a database principal, however much of the trail and ledger it can erase,
 * cannot lower it. The directory belongs in the same custody as the archives and receipts beside it; the
 * operator additionally keeps copies of printed checkpoints off-host, which survive loss of this host.
 *
 * @since  2.0.0
 */
final readonly class FilesystemAuditCheckpointStore
{
    /**
     * Largest checkpoint document this store or an operator file may hold.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_BYTES = 65_536;

    /**
     * Bind the store to its private directory.
     *
     * @param   string  $directory  Absolute directory holding this installation's checkpoints.
     *
     * @throws  RuntimeException  When the directory is not absolute.
     *
     * @since   2.0.0
     */
    public function __construct(private string $directory)
    {
        if (!str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The audit checkpoint directory must be absolute.');
        }
    }

    /**
     * Bind a store to the checkpoint directory of one database installation beneath the archive root.
     *
     * Checkpoints describe one database's trail, so each engine, database and table prefix sharing an
     * archive root keeps its own directory. The host and port are deliberately excluded: moving the same
     * database to another server must not silently start a fresh, unprotected checkpoint history.
     *
     * @param   string  $archiveRoot  Absolute private audit archive directory.
     * @param   string  $driver       Configured database driver.
     * @param   string  $database     Configured database name.
     * @param   string  $tablePrefix  Configured table prefix.
     *
     * @return  self  Store for that installation's checkpoints.
     *
     * @throws  RuntimeException  When the archive root is not absolute.
     *
     * @since   2.0.0
     */
    public static function forInstallation(
        string $archiveRoot,
        string $driver,
        string $database,
        string $tablePrefix,
    ): self {
        return new self(sprintf(
            '%s%scheckpoints%s%s',
            $archiveRoot,
            DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR,
            substr(hash('sha256', $driver . "\0" . $database . "\0" . $tablePrefix), 0, 24),
        ));
    }

    /**
     * Read the highest checkpoint retained so far.
     *
     * @return  ?AuditCheckpoint  The retained high-water mark, or null when nothing has been retained yet.
     *
     * @throws  RuntimeException  When the directory is unsafe or the newest checkpoint is unreadable.
     *
     * @since   2.0.0
     */
    public function retained(): ?AuditCheckpoint
    {
        if (is_link($this->directory)) {
            throw new RuntimeException('The audit checkpoint directory is unsafe.');
        }
        if (!is_dir($this->directory)) {
            return null;
        }
        $names = glob($this->directory . DIRECTORY_SEPARATOR . '*.json');
        $newest = null;
        foreach ($names === false ? [] : $names as $path) {
            if (preg_match('/^[0-9]{20}-[0-9]{20}\.json$/D', basename($path)) === 1 && basename($path) > $newest) {
                $newest = basename($path);
            }
        }
        if ($newest === null) {
            return null;
        }
        $checkpoint = self::read($this->directory . DIRECTORY_SEPARATOR . $newest);
        if (self::name($checkpoint) !== $newest) {
            throw new RuntimeException('The retained audit checkpoint does not match its name.');
        }

        return $checkpoint;
    }

    /**
     * Retain a newly verified checkpoint when it advances beyond the retained one.
     *
     * @param   AuditCheckpoint  $checkpoint  Verified high-water mark to preserve.
     *
     * @return  bool  True when a new checkpoint was published, false when the retained one already reaches it.
     *
     * @throws  RuntimeException  When the checkpoint would lower the retained mark or cannot be published.
     *
     * @since   2.0.0
     */
    public function retain(AuditCheckpoint $checkpoint): bool
    {
        $retained = $this->retained();
        if ($retained !== null && $retained->reaches($checkpoint)) {
            return false;
        }
        if ($retained !== null && !$checkpoint->reaches($retained)) {
            throw new RuntimeException('An audit checkpoint may never lower the retained high-water mark.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('The audit checkpoint directory cannot be created.');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . self::name($checkpoint);
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $bytes = json_encode($checkpoint->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temporary);
            throw new RuntimeException('The audit checkpoint cannot be written.');
        }
        chmod($temporary, 0600);
        $published = @link($temporary, $path);
        @unlink($temporary);
        if (!$published) {
            // Another verifier may have published this exact checkpoint after our initial read.
            if (is_file($path) && self::read($path)->toArray() === $checkpoint->toArray()) {
                return false;
            }

            throw new RuntimeException('The audit checkpoint could not be published exclusively.');
        }

        return true;
    }

    /**
     * Read one checkpoint document, retained here or supplied by an operator.
     *
     * @param   string  $path  Absolute path of the document.
     *
     * @return  AuditCheckpoint  The checkpoint it holds.
     *
     * @throws  RuntimeException  When the file is missing, oversized, unreadable or not a checkpoint.
     *
     * @since   2.0.0
     */
    public static function read(string $path): AuditCheckpoint
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path) || !is_file($path)) {
            throw new RuntimeException('The audit checkpoint file is not a regular file at an absolute path.');
        }
        $bytes = @file_get_contents($path, false, null, 0, self::MAXIMUM_BYTES + 1);
        if (!is_string($bytes) || strlen($bytes) > self::MAXIMUM_BYTES) {
            throw new RuntimeException('The audit checkpoint file cannot be read.');
        }
        try {
            return AuditCheckpoint::fromArray(json_decode($bytes, true, 16, JSON_THROW_ON_ERROR));
        } catch (\JsonException | InvalidArgumentException $exception) {
            throw new RuntimeException('The audit checkpoint file is not a valid checkpoint.', 0, $exception);
        }
    }

    /**
     * Name the file a checkpoint is published under, ordering names by ledger sequence and then head.
     *
     * @param   AuditCheckpoint  $checkpoint  Checkpoint being named.
     *
     * @return  string  Zero-padded `sequence-head.json` file name.
     *
     * @since   2.0.0
     */
    private static function name(AuditCheckpoint $checkpoint): string
    {
        return sprintf('%020d-%020d.json', $checkpoint->ledgerSequence, $checkpoint->headPosition);
    }
}
