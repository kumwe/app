<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\StepUp;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\CMS\Identity\Domain\StepUp\TotpCredential;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;
use Throwable;

/**
 * DBAL persistence for encrypted TOTP credentials and individually consumable recovery digests.
 *
 * This adapter expects the additive `step_up_credentials` and `step_up_recovery_codes` tables described
 * in the implementation handoff. All multi-row changes run through DBAL transactions, which become
 * savepoints when the provider already opened the surrounding audit-and-session transaction.
 *
 * @since  2.0.0
 */
final readonly class DoctrineStepUpCredentialStore implements StepUpCredentialStore
{
    /**
     * Bind the store to one database and its installation table prefix.
     *
     * @param  Connection  $database  Shared DBAL connection.
     * @param  TableNames  $tables    Logical-to-physical table name mapper.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
    ) {
    }

    /**
     * Replace only a pending enrollment, refusing to overwrite an active credential.
     *
     * @param   TotpCredential  $credential  Pending encrypted credential.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is active or the subject is already enrolled.
     *
     * @since   2.0.0
     */
    public function replacePending(TotpCredential $credential): void
    {
        if ($credential->active) {
            throw new InvalidArgumentException('Only a pending TOTP credential can replace an enrollment.');
        }
        $this->database->transactional(function () use ($credential): void {
            $subject = $this->database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE id = ?%s',
                $this->tables->quoted('users'),
                $this->lockClause(),
            ), [$credential->subjectId]);
            if ($subject === false) {
                throw new InvalidArgumentException('The step-up actor does not exist.');
            }
            $active = $this->database->fetchOne(sprintf(
                "SELECT id FROM %s WHERE subject_id = ? AND status = 'active'",
                $this->tables->quoted('step_up_credentials'),
            ), [$credential->subjectId]);
            if ($active !== false) {
                throw new InvalidArgumentException('The actor already has an active TOTP credential.');
            }
            $this->database->executeStatement(sprintf(
                "DELETE FROM %s WHERE subject_id = ? AND status = 'pending'",
                $this->tables->quoted('step_up_credentials'),
            ), [$credential->subjectId]);
            $this->database->insert($this->tables->raw('step_up_credentials'), [
                'id' => $credential->id,
                'subject_id' => $credential->subjectId,
                'encrypted_secret' => $credential->encryptedSecret,
                'status' => 'pending',
                'created_at' => $credential->createdAt,
                'enrollment_expires_at' => $credential->enrollmentExpiresAt,
                'confirmed_at' => null,
                'last_accepted_time_step' => null,
                'last_verified_at' => null,
                'disabled_at' => null,
                'version' => $credential->version,
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'enrollment_expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        });
    }

    /**
     * Find one pending credential without exposing other records.
     *
     * @param   string  $credentialId  Enrollment UUID.
     * @param   string  $subjectId     Authenticated actor UUID.
     *
     * @return  ?TotpCredential  Matching pending credential or null.
     *
     * @since   2.0.0
     */
    public function pending(string $credentialId, string $subjectId): ?TotpCredential
    {
        $row = $this->database->fetchAssociative(sprintf(
            "SELECT id, subject_id, encrypted_secret, status, created_at, enrollment_expires_at, "
            . "confirmed_at, last_accepted_time_step, version FROM %s "
            . "WHERE id = ? AND subject_id = ? AND status = 'pending'",
            $this->tables->quoted('step_up_credentials'),
        ), [$credentialId, $subjectId]);

        return is_array($row) ? $this->credential($row) : null;
    }

    /**
     * Find a subject's active, non-disabled credential.
     *
     * @param   string  $subjectId  Authenticated actor UUID.
     *
     * @return  ?TotpCredential  Active credential or null.
     *
     * @since   2.0.0
     */
    public function active(string $subjectId): ?TotpCredential
    {
        $row = $this->database->fetchAssociative(sprintf(
            "SELECT id, subject_id, encrypted_secret, status, created_at, enrollment_expires_at, "
            . "confirmed_at, last_accepted_time_step, version FROM %s "
            . "WHERE subject_id = ? AND status = 'active' AND disabled_at IS NULL",
            $this->tables->quoted('step_up_credentials'),
        ), [$subjectId]);

        return is_array($row) ? $this->credential($row) : null;
    }

    /**
     * Activate one unexpired pending row and insert all recovery digests atomically.
     *
     * @param   string             $credentialId      Enrollment UUID.
     * @param   string             $subjectId         Authenticated actor UUID.
     * @param   int                $expectedVersion   Version fence.
     * @param   int                $acceptedTimeStep  Confirmation TOTP counter.
     * @param   list<string>       $recoveryDigests   Unique keyed digests.
     * @param   DateTimeImmutable  $confirmedAt       Confirmation and expiry-check instant.
     *
     * @return  bool  True only when the row changed and every digest was stored.
     *
     * @since   2.0.0
     */
    public function activate(
        string $credentialId,
        string $subjectId,
        int $expectedVersion,
        int $acceptedTimeStep,
        array $recoveryDigests,
        DateTimeImmutable $confirmedAt,
    ): bool {
        if ($recoveryDigests === [] || !array_is_list($recoveryDigests)) {
            throw new InvalidArgumentException('TOTP recovery digests must be a non-empty list.');
        }

        return $this->database->transactional(function () use (
            $credentialId,
            $subjectId,
            $expectedVersion,
            $acceptedTimeStep,
            $recoveryDigests,
            $confirmedAt,
        ): bool {
            $changed = $this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'active', enrollment_expires_at = NULL, confirmed_at = ?, "
                . 'last_accepted_time_step = ?, last_verified_at = ?, version = version + 1 '
                . "WHERE id = ? AND subject_id = ? AND status = 'pending' AND version = ? "
                . 'AND enrollment_expires_at > ?',
                $this->tables->quoted('step_up_credentials'),
            ), [
                $confirmedAt,
                $acceptedTimeStep,
                $confirmedAt,
                $credentialId,
                $subjectId,
                $expectedVersion,
                $confirmedAt,
            ], [
                Types::DATETIME_IMMUTABLE,
                Types::BIGINT,
                Types::DATETIME_IMMUTABLE,
                Types::STRING,
                Types::STRING,
                Types::INTEGER,
                Types::DATETIME_IMMUTABLE,
            ]);
            if ($changed !== 1) {
                return false;
            }
            $unique = [];
            foreach ($recoveryDigests as $digest) {
                if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1 || isset($unique[$digest])) {
                    throw new InvalidArgumentException('A TOTP recovery digest is invalid or duplicated.');
                }
                $unique[$digest] = true;
                $this->database->insert($this->tables->raw('step_up_recovery_codes'), [
                    'credential_id' => $credentialId,
                    'code_digest' => $digest,
                    'created_at' => $confirmedAt,
                    'consumed_at' => null,
                ], ['created_at' => Types::DATETIME_IMMUTABLE]);
            }

            return true;
        });
    }

    /**
     * Advance a live credential only for a greater TOTP counter and matching version.
     *
     * @param   string             $credentialId     Active credential UUID.
     * @param   int                $expectedVersion  Optimistic version fence.
     * @param   int                $timeStep         Counter that must be strictly greater.
     * @param   DateTimeImmutable  $acceptedAt       Verification timestamp.
     *
     * @return  bool  True only when exactly one active row advanced.
     *
     * @since   2.0.0
     */
    public function acceptTimeStep(
        string $credentialId,
        int $expectedVersion,
        int $timeStep,
        DateTimeImmutable $acceptedAt,
    ): bool {
        return $this->database->executeStatement(sprintf(
            "UPDATE %s SET last_accepted_time_step = ?, last_verified_at = ?, version = version + 1 "
            . "WHERE id = ? AND status = 'active' AND disabled_at IS NULL AND version = ? "
            . 'AND (last_accepted_time_step IS NULL OR last_accepted_time_step < ?)',
            $this->tables->quoted('step_up_credentials'),
        ), [$timeStep, $acceptedAt, $credentialId, $expectedVersion, $timeStep], [
            Types::BIGINT,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::INTEGER,
            Types::BIGINT,
        ]) === 1;
    }

    /**
     * Consume a recovery digest and advance its credential version as one savepoint-protected unit.
     *
     * @param   string             $credentialId     Active credential UUID.
     * @param   int                $expectedVersion  Optimistic version fence.
     * @param   string             $digest           Keyed code digest.
     * @param   DateTimeImmutable  $consumedAt       Consumption timestamp.
     *
     * @return  bool  True only when one live credential and one unspent code changed.
     *
     * @since   2.0.0
     */
    public function consumeRecoveryCode(
        string $credentialId,
        int $expectedVersion,
        string $digest,
        DateTimeImmutable $consumedAt,
    ): bool {
        if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            return false;
        }
        $sentinel = new RuntimeException('step-up-recovery-not-accepted');
        try {
            return $this->database->transactional(function () use (
                $credentialId,
                $expectedVersion,
                $digest,
                $consumedAt,
                $sentinel,
            ): bool {
                $credentialChanged = $this->database->executeStatement(sprintf(
                    "UPDATE %s SET last_verified_at = ?, version = version + 1 "
                    . "WHERE id = ? AND status = 'active' AND disabled_at IS NULL AND version = ?",
                    $this->tables->quoted('step_up_credentials'),
                ), [$consumedAt, $credentialId, $expectedVersion], [
                    Types::DATETIME_IMMUTABLE,
                    Types::STRING,
                    Types::INTEGER,
                ]);
                $codeChanged = $this->database->executeStatement(sprintf(
                    'UPDATE %s SET consumed_at = ? '
                    . 'WHERE credential_id = ? AND code_digest = ? AND consumed_at IS NULL',
                    $this->tables->quoted('step_up_recovery_codes'),
                ), [$consumedAt, $credentialId, $digest], [
                    Types::DATETIME_IMMUTABLE,
                    Types::STRING,
                    Types::STRING,
                ]);
                if ($credentialChanged !== 1 || $codeChanged !== 1) {
                    throw $sentinel;
                }

                return true;
            });
        } catch (Throwable $exception) {
            if ($exception === $sentinel) {
                return false;
            }
            throw $exception;
        }
    }

    /**
     * Reconstitute a validated credential from a DBAL row.
     *
     * @param   array<string, mixed>  $row  Selected storage row.
     *
     * @return  TotpCredential  Validated pending or active credential.
     *
     * @throws  RuntimeException  When stored values have an impossible type.
     *
     * @since   2.0.0
     */
    private function credential(array $row): TotpCredential
    {
        $status = $this->string($row, 'status');
        if (!in_array($status, ['pending', 'active'], true)) {
            throw new RuntimeException('A stored TOTP credential status is invalid.');
        }

        return new TotpCredential(
            $this->string($row, 'id'),
            $this->string($row, 'subject_id'),
            $this->string($row, 'encrypted_secret'),
            $status === 'active',
            $this->date($row['created_at'] ?? null),
            $this->nullableDate($row['enrollment_expires_at'] ?? null),
            $this->nullableDate($row['confirmed_at'] ?? null),
            $this->nullableInteger($row['last_accepted_time_step'] ?? null),
            $this->integer($row['version'] ?? null),
        );
    }

    /**
     * Read a required non-empty string from a row.
     *
     * @param   array<string, mixed>  $row     Storage row.
     * @param   string                $column  Column name.
     *
     * @return  string  Stored string.
     *
     * @throws  RuntimeException  When absent or empty.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored TOTP column %s is invalid.', $column));
        }

        return $value;
    }

    /**
     * Normalize a required database datetime.
     *
     * @param   mixed  $value  Driver-returned datetime.
     *
     * @return  DateTimeImmutable  Immutable instant.
     *
     * @throws  RuntimeException  When it cannot be parsed.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        $date = $this->nullableDate($value);
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('A stored TOTP datetime is missing.');
        }

        return $date;
    }

    /**
     * Normalize a nullable database datetime.
     *
     * @param   mixed  $value  Driver-returned datetime or null.
     *
     * @return  ?DateTimeImmutable  Parsed instant or null.
     *
     * @throws  RuntimeException  When a non-null value cannot be parsed.
     *
     * @since   2.0.0
     */
    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (Throwable $exception) {
                throw new RuntimeException('A stored TOTP datetime is invalid.', 0, $exception);
            }
        }
        throw new RuntimeException('A stored TOTP datetime is invalid.');
    }

    /**
     * Normalize a required positive integer.
     *
     * @param   mixed  $value  Driver-returned integer.
     *
     * @return  int  Positive integer.
     *
     * @throws  RuntimeException  When malformed or below one.
     *
     * @since   2.0.0
     */
    private function integer(mixed $value): int
    {
        $integer = $this->nullableInteger($value);
        if ($integer === null || $integer < 1) {
            throw new RuntimeException('A stored TOTP integer is invalid.');
        }

        return $integer;
    }

    /**
     * Normalize a nullable non-negative integer.
     *
     * @param   mixed  $value  Driver-returned integer or null.
     *
     * @return  ?int  Non-negative integer or null.
     *
     * @throws  RuntimeException  When malformed or negative.
     *
     * @since   2.0.0
     */
    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new RuntimeException('A stored TOTP integer is invalid.');
        }
        if ($integer < 0) {
            throw new RuntimeException('A stored TOTP integer is invalid.');
        }

        return $integer;
    }

    /**
     * Add a pessimistic actor lock where supported, serializing concurrent enrollment replacement.
     *
     * @return  string  Portable lock suffix.
     *
     * @since   2.0.0
     */
    private function lockClause(): string
    {
        return $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }
}
