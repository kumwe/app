<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\BusinessRecord\Application\PostingPeriodCalendar;
use Kumwe\App\BusinessRecord\Application\PostingPeriodRepository;
use Kumwe\App\BusinessRecord\Domain\PostingPeriod;
use Kumwe\App\BusinessRecord\Domain\PostingPeriodStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Serves posting-period declarations from the `business_posting_periods` table.
 *
 * One table backs both faces of the primitive: the administrative store `PostingPeriodService` writes
 * through, and the two containment reads — the lock's closed-range question, and the calendar seam the
 * fiscal-period number-sequence reset consumes. A site-wide declaration stores its absent organization
 * as the empty string rather than as null, because all three engines treat two nulls in a unique index
 * as distinct and the (site, organization, key) identity index is what makes a key unambiguous; null
 * remains the shape the application speaks, translated at this boundary.
 *
 * Containment reads compare at whole-second precision, matching the stored DATETIME columns, and
 * order their answer by narrower scope first, later range start, then key — the deterministic pick
 * both port contracts promise.
 *
 * @since  2.0.0
 */
final readonly class DoctrinePostingPeriodRepository implements PostingPeriodRepository, PostingPeriodCalendar
{
    /**
     * Columns every hydrating read selects, in hydration order.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string COLUMNS = 'site_identifier, organization_identifier, period_key, starts_at, '
        . 'ends_at, status, closed_by, closed_at, reopened_by, reopened_at';

    /**
     * Bind the repository to the connection and the prefixed table map.
     *
     * @param  Connection  $database  Connection the period rows are read from and written to.
     * @param  TableNames  $tables    Resolver applying the configured prefix to table names.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
    ) {
    }

    /**
     * Read one declaration by its scope and stable key.
     *
     * @param   string   $siteIdentifier          Site the declaration belongs to.
     * @param   ?string  $organizationIdentifier  Organization scope of the declaration, or null for a
     *          site-wide one.
     * @param   string   $key                     Stable key the declaration was made under.
     *
     * @return  ?PostingPeriod  The declaration, or null when the scope holds none under that key.
     *
     * @throws  RuntimeException  When a stored row is unreadable.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function find(string $siteIdentifier, ?string $organizationIdentifier, string $key): ?PostingPeriod
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT %s FROM %s WHERE site_identifier = ? AND organization_identifier = ? AND period_key = ?',
            self::COLUMNS,
            $this->tables->quoted('business_posting_periods'),
        ), [$siteIdentifier, $organizationIdentifier ?? '', $key]);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Store one declaration, replacing the state a previous declaration under the same key holds.
     *
     * Update first keeps the common close-and-reopen path to one write; zero affected rows is then
     * distinguished from a genuine no-op by a keyed existence read before the insert path is taken,
     * because MySQL and MariaDB report zero when every value already matches.
     *
     * @param   PostingPeriod  $period  Declaration to persist.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the write.
     *
     * @since   2.0.0
     */
    public function save(PostingPeriod $period): void
    {
        $updated = $this->database->executeStatement(sprintf(
            'UPDATE %s SET starts_at = ?, ends_at = ?, status = ?, closed_by = ?, closed_at = ?, '
            . 'reopened_by = ?, reopened_at = ? '
            . 'WHERE site_identifier = ? AND organization_identifier = ? AND period_key = ?',
            $this->tables->quoted('business_posting_periods'),
        ), [
            $this->stamp($period->startsAt),
            $this->stamp($period->endsAt),
            $period->status->value,
            $period->closedBy,
            $this->stamp($period->closedAt),
            $period->reopenedBy,
            $period->reopenedAt === null ? null : $this->stamp($period->reopenedAt),
            $period->siteIdentifier,
            $period->organizationIdentifier ?? '',
            $period->key,
        ]);
        if ($updated !== 0 || $this->exists($period)) {
            return;
        }

        $this->database->insert($this->tables->raw('business_posting_periods'), [
            'id' => Uuid::uuid7()->toString(),
            'site_identifier' => $period->siteIdentifier,
            'organization_identifier' => $period->organizationIdentifier ?? '',
            'period_key' => $period->key,
            'starts_at' => $period->startsAt,
            'ends_at' => $period->endsAt,
            'status' => $period->status->value,
            'closed_by' => $period->closedBy,
            'closed_at' => $period->closedAt,
            'reopened_by' => $period->reopenedBy,
            'reopened_at' => $period->reopenedAt,
        ], [
            'starts_at' => Types::DATETIME_IMMUTABLE,
            'ends_at' => Types::DATETIME_IMMUTABLE,
            'closed_at' => Types::DATETIME_IMMUTABLE,
            'reopened_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * List the declarations that govern one scope, site-wide ones included.
     *
     * @param   string   $siteIdentifier          Site whose declarations are listed.
     * @param   ?string  $organizationIdentifier  Organization whose declarations are listed beside the
     *          site-wide ones, or null to list every declaration on the site.
     *
     * @return  list<PostingPeriod>  Declarations ordered by their range start and then by key.
     *
     * @throws  RuntimeException  When a stored row is unreadable.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function listFor(string $siteIdentifier, ?string $organizationIdentifier): array
    {
        $sql = sprintf(
            'SELECT %s FROM %s WHERE site_identifier = ?',
            self::COLUMNS,
            $this->tables->quoted('business_posting_periods'),
        );
        $parameters = [$siteIdentifier];
        if ($organizationIdentifier !== null) {
            $sql .= " AND organization_identifier IN ('', ?)";
            $parameters[] = $organizationIdentifier;
        }
        $sql .= ' ORDER BY starts_at, period_key';

        $periods = [];
        foreach ($this->database->fetchAllAssociative($sql, $parameters) as $row) {
            $periods[] = $this->hydrate($row);
        }

        return $periods;
    }

    /**
     * Resolve the closed declaration containing an instant, preferring the narrower scope.
     *
     * @param   string             $siteIdentifier          Site whose declarations are consulted.
     * @param   ?string            $organizationIdentifier  Organization consulted beside the site-wide
     *          declarations, or null for site-wide declarations only.
     * @param   DateTimeImmutable  $instant                 Posting instant to classify.
     *
     * @return  ?PostingPeriod  The closed declaration refusing this instant, or null when none does.
     *
     * @throws  RuntimeException  When a stored row is unreadable.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function closedPeriodContaining(
        string $siteIdentifier,
        ?string $organizationIdentifier,
        DateTimeImmutable $instant,
    ): ?PostingPeriod {
        return $this->containing($siteIdentifier, $organizationIdentifier, $instant, true);
    }

    /**
     * Resolve the declared period containing an instant regardless of its state.
     *
     * @param   string             $siteIdentifier          Site whose declarations are consulted.
     * @param   ?string            $organizationIdentifier  Organization consulted beside the site-wide
     *          declarations, or null for site-wide declarations only.
     * @param   DateTimeImmutable  $instant                 Moment to classify.
     *
     * @return  ?PostingPeriod  The containing declaration, or null when no declared period covers the
     *          instant in this scope.
     *
     * @throws  RuntimeException  When a stored row is unreadable.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function periodContaining(
        string $siteIdentifier,
        ?string $organizationIdentifier,
        DateTimeImmutable $instant,
    ): ?PostingPeriod {
        return $this->containing($siteIdentifier, $organizationIdentifier, $instant, false);
    }

    /**
     * Run one containment read, optionally restricted to closed declarations.
     *
     * The ordering carries the deterministic pick both ports promise: any organization identifier
     * sorts after the empty site-wide marker, so descending order puts the narrower scope first,
     * then the later range start, then the key.
     *
     * @param   string             $siteIdentifier          Site whose declarations are consulted.
     * @param   ?string            $organizationIdentifier  Organization consulted beside the site-wide
     *          declarations, or null for site-wide declarations only.
     * @param   DateTimeImmutable  $instant                 Moment to classify.
     * @param   bool               $closedOnly              True to consider closed declarations only.
     *
     * @return  ?PostingPeriod  The winning declaration, or null when none contains the instant.
     *
     * @throws  RuntimeException  When a stored row is unreadable.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    private function containing(
        string $siteIdentifier,
        ?string $organizationIdentifier,
        DateTimeImmutable $instant,
        bool $closedOnly,
    ): ?PostingPeriod {
        $stamp = $this->stamp($instant);
        $sql = sprintf(
            'SELECT %s FROM %s WHERE site_identifier = ? AND starts_at <= ? AND ends_at > ?',
            self::COLUMNS,
            $this->tables->quoted('business_posting_periods'),
        );
        $parameters = [$siteIdentifier, $stamp, $stamp];
        if ($organizationIdentifier === null) {
            $sql .= " AND organization_identifier = ''";
        } else {
            $sql .= " AND organization_identifier IN ('', ?)";
            $parameters[] = $organizationIdentifier;
        }
        if ($closedOnly) {
            $sql .= ' AND status = ?';
            $parameters[] = PostingPeriodStatus::Closed->value;
        }
        $sql .= ' ORDER BY organization_identifier DESC, starts_at DESC, period_key LIMIT 1';

        $row = $this->database->fetchAssociative($sql, $parameters);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Render one instant the way the stored DATETIME columns are compared.
     *
     * @param   DateTimeImmutable  $instant  Moment to render.
     *
     * @return  string  UTC `Y-m-d H:i:s`, whole seconds, matching the column precision.
     *
     * @since   2.0.0
     */
    private function stamp(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Distinguish a no-op update from a missing declaration using the unique identity.
     *
     * @param   PostingPeriod  $period  Declaration whose identity may already be stored.
     *
     * @return  bool  True when the row exists, regardless of its current values.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the existence read.
     *
     * @since   2.0.0
     */
    private function exists(PostingPeriod $period): bool
    {
        return $this->database->fetchOne(sprintf(
            'SELECT 1 FROM %s WHERE site_identifier = ? AND organization_identifier = ? AND period_key = ?',
            $this->tables->quoted('business_posting_periods'),
        ), [$period->siteIdentifier, $period->organizationIdentifier ?? '', $period->key]) !== false;
    }

    /**
     * Turn one stored row into the domain declaration both ports answer with.
     *
     * @param   array<string, mixed>  $row  Row as the driver returned it.
     *
     * @return  PostingPeriod  The hydrated declaration.
     *
     * @throws  RuntimeException  When the row carries an unreadable column or instant.
     *
     * @since   2.0.0
     */
    private function hydrate(array $row): PostingPeriod
    {
        $site = $row['site_identifier'] ?? null;
        $organization = $row['organization_identifier'] ?? null;
        $key = $row['period_key'] ?? null;
        $status = $row['status'] ?? null;
        $closedBy = $row['closed_by'] ?? null;
        $reopenedBy = $row['reopened_by'] ?? null;
        if (
            !is_string($site) || !is_string($organization) || !is_string($key) || !is_string($status)
            || !is_string($closedBy) || ($reopenedBy !== null && !is_string($reopenedBy))
        ) {
            throw new RuntimeException('A stored posting period row is incomplete.');
        }
        $state = PostingPeriodStatus::tryFrom($status)
            ?? throw new RuntimeException('A stored posting period carries an unknown status.');
        $reopenedAtRaw = $row['reopened_at'] ?? null;

        return new PostingPeriod(
            $site,
            $organization === '' ? null : $organization,
            $key,
            $this->instant($row['starts_at'] ?? null),
            $this->instant($row['ends_at'] ?? null),
            $state,
            $closedBy,
            $this->instant($row['closed_at'] ?? null),
            $reopenedBy,
            $reopenedAtRaw === null ? null : $this->instant($reopenedAtRaw),
        );
    }

    /**
     * Parse one stored timestamp, tolerating the three engines' differing spellings.
     *
     * @param   mixed  $value  Column value as the driver returned it.
     *
     * @return  DateTimeImmutable  The instant in UTC.
     *
     * @throws  RuntimeException  When the value is not a string or does not parse.
     *
     * @since   2.0.0
     */
    private function instant(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new RuntimeException('A stored posting period carries an unreadable instant.');
        }
        try {
            // The three engines spell a stored timestamp differently — with microseconds, with a
            // trailing offset, or without either — so the value is parsed rather than pattern-matched.
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $unreadable) {
            throw new RuntimeException('A stored posting period carries an unreadable instant.', 0, $unreadable);
        }
    }
}
