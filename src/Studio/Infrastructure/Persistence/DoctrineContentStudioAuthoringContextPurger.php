<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextPurger;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Deletes expired opaque Content authoring contexts through portable bounded Doctrine statements.
 *
 * The candidate read and guarded delete share one clock instant. The delete repeats the expiry
 * predicate instead of trusting the earlier read, while deterministic ordering makes overlapping
 * maintenance passes harmless and keeps each pass moving through the oldest rows first.
 *
 * @since  2.0.0
 */
final readonly class DoctrineContentStudioAuthoringContextPurger implements ContentStudioAuthoringContextPurger
{
    /**
     * Bind retention to the shared database, prefix-aware table map, and trusted expiry clock.
     *
     * @param  Connection      $database  Connection holding contextual authoring bindings.
     * @param  TableNames      $tables    Installation-specific physical table-name compiler.
     * @param  ClockInterface  $clock     Source of the single cutoff instant for each pass.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Remove one bounded set of bindings at or beyond their exclusive expiry.
     *
     * @param   int  $batchSize  Maximum rows to remove, between 1 and 10000.
     *
     * @return  int  Number of candidate rows still expired when the guarded delete ran.
     *
     * @throws  InvalidArgumentException  When the requested batch is outside the supported bound.
     * @throws  RuntimeException  When a candidate row has a context key the application cannot address.
     * @throws  \Doctrine\DBAL\Exception  When the database refuses the read or delete.
     *
     * @since   2.0.0
     */
    public function purgeExpired(int $batchSize = 1_000): int
    {
        $cutoff = $this->clock->now();
        $keys = $this->expiredCandidates($cutoff, $batchSize);
        if ($keys === []) {
            return 0;
        }

        return $this->deleteExpiredCandidates($keys, $cutoff);
    }

    /**
     * Read the next deterministic batch of canonical keys eligible for deletion.
     *
     * @param   DateTimeImmutable  $cutoff     Instant at which expiry is judged inclusively.
     * @param   int                $batchSize  Maximum candidate keys to return, between 1 and 10000.
     *
     * @return  list<string>  Canonical opaque keys ordered by expiry and then key.
     *
     * @throws  InvalidArgumentException  When the requested batch is outside the supported bound.
     * @throws  RuntimeException  When persistence returns a malformed key.
     * @throws  \Doctrine\DBAL\Exception  When the database refuses the candidate read.
     *
     * @since   2.0.0
     */
    public function expiredCandidates(DateTimeImmutable $cutoff, int $batchSize): array
    {
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new InvalidArgumentException(
                'Studio Content authoring context purge batch size must be between 1 and 10000.',
            );
        }
        $keys = array_values($this->database->fetchFirstColumn(sprintf(
            'SELECT context_key FROM %s WHERE expires_at <= ? ORDER BY expires_at, context_key LIMIT %d',
            $this->tables->quoted('studio_content_authoring_contexts'),
            $batchSize,
        ), [$cutoff], [Types::DATETIME_IMMUTABLE]));
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match('/^contexts\/[a-f0-9]{64}$/D', $key) !== 1) {
                throw new RuntimeException('A Studio Content authoring context purge candidate is invalid.');
            }
        }

        return $keys;
    }

    /**
     * Delete named candidates only while they still satisfy the same expiry cutoff.
     *
     * @param   list<string>       $keys    Canonical keys returned by the bounded candidate read.
     * @param   DateTimeImmutable  $cutoff  Instant whose expiry predicate is repeated by the delete.
     *
     * @return  int  Rows deleted after the guarded predicate was re-applied.
     *
     * @throws  \Doctrine\DBAL\Exception  When the database refuses the guarded delete.
     *
     * @since   2.0.0
     */
    public function deleteExpiredCandidates(array $keys, DateTimeImmutable $cutoff): int
    {
        if ($keys === []) {
            return 0;
        }

        return (int) $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE context_key IN (?) AND expires_at <= ?',
            $this->tables->quoted('studio_content_authoring_contexts'),
        ), [$keys, $cutoff], [ArrayParameterType::STRING, Types::DATETIME_IMMUTABLE]);
    }
}
